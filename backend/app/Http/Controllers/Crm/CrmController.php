<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmCustomers;
use App\Services\Crm\CrmReportDraft;
use App\Services\KpsMembershipResolver;
use App\Services\ReportClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CrmController extends Controller
{
    public function __construct(
        private readonly CrmAccess $access,
        private readonly KpsMembershipResolver $memberships,
        private readonly CrmCustomers $customers,
    ) {}

    public function capabilities(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $enabled = (bool) config('crm.enabled');
        $ready = $enabled && $this->access->schemaReady();

        return $this->response([
            'enabled' => $enabled, 'ready' => $ready,
            'can_create' => $ready && $this->access->scopes($member, 'customers.create') !== [],
            'business_date' => app(ReportClock::class)->businessDate(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request, true);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']]);

        return $this->response($this->customers->index($member, $input['search'] ?? null)->toArray());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->response($this->customers->detail($this->member($request, true), $id));
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->member($request, true);
        $input = $request->validate([
            'request_key' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'channel' => ['required', Rule::in(CrmCustomers::CHANNELS)],
            'contact' => ['nullable', 'string', 'max:2048'],
        ]);

        return $this->response(['id' => $this->customers->create($member, $input)], 201);
    }

    public function record(Request $request, int $id): JsonResponse
    {
        $member = $this->member($request, true);
        $input = $request->validate([
            'request_key' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['OPPORTUNITY', 'CONSULTATION', 'FOLLOW_UP'])],
            'title' => ['required_if:kind,OPPORTUNITY,FOLLOW_UP', 'nullable', 'string', 'max:200'],
            'result' => ['required_if:kind,CONSULTATION', 'nullable', 'string', 'max:1000'],
            'services' => ['required_if:kind,CONSULTATION', 'array', 'list', 'min:1', 'max:10'],
            'services.*' => ['required', 'string', 'max:200'],
            'due_on' => ['required_if:kind,FOLLOW_UP', 'nullable', 'date_format:Y-m-d'],
        ]);

        return $this->response(['id' => $this->customers->record($member, $id, $input)], 201);
    }

    public function assignees(Request $request): JsonResponse
    {
        $member = $this->member($request, true);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = $this->access->assignees($member);
        if (filled($input['search'] ?? null)) {
            $query->where('users.name', 'like', '%'.$input['search'].'%');
        }

        return $this->response($query->orderBy('users.name')->limit(50)->get(['membership.id', 'users.name'])->all());
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $member = $this->member($request, true);
        $input = $request->validate([
            'request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'],
            'membership_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:300'],
        ]);
        $this->customers->assign($member, $id, $input);

        return $this->response(['updated' => true]);
    }

    public function stage(Request $request, int $id, int $opportunity): JsonResponse
    {
        $member = $this->member($request, true);
        $input = $request->validate([
            'stage' => ['required', Rule::in(CrmCustomers::STAGES)],
            'version' => ['required', 'integer', 'min:1'],
            'resolution' => ['required_if:stage,WON,LOST', 'nullable', 'string', 'max:200'],
        ]);
        $this->customers->stage($member, $id, $opportunity, $input);

        return $this->response(['updated' => true]);
    }

    public function reportDraft(Request $request, CrmReportDraft $drafts): JsonResponse
    {
        return $this->response($drafts->preview($this->member($request)));
    }

    private function member(Request $request, bool $ready = false): OrganizationMembership
    {
        $member = $this->memberships->activeMembershipFor($request->user());
        abort_unless($member && $this->access->scopes($member, 'customers.view') !== [], 403);
        abort_if($ready && ! $this->access->ready(), 409, 'CRM_NOT_READY');

        return $member;
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}
