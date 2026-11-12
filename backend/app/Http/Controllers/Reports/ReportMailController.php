<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\PersonalWeeklyMailDelivery;
use App\Models\ReportMailPreference;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailOutbox;
use App\Services\Mail\ReportMailPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportMailController extends Controller
{
    public function show(Request $request, ReportMailPreferences $preferences): JsonResponse
    {
        return $this->response($preferences->status($request->user()));
    }

    public function requestVerification(Request $request, ReportMailPreferences $preferences, MailOutbox $outbox): JsonResponse
    {
        $input = $request->validate(['request_key' => ['required', 'uuid'], 'expected_email' => ['required', 'string', 'max:255'],
            'to' => ['prohibited'], 'email' => ['prohibited'], 'recipient_user_id' => ['prohibited'], 'subject' => ['prohibited'], 'body' => ['prohibited']]);
        $id = $preferences->request($request->user(), $input['request_key'], $input['expected_email']);
        $outbox->process($id);

        return $this->response($preferences->status($request->user()), 202);
    }

    public function confirm(Request $request, ReportMailPreferences $preferences): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $preferences->confirm($request->user(), $input['token']);

        return $this->response($preferences->status($request->user()));
    }

    public function update(Request $request, ReportMailPreferences $preferences): JsonResponse
    {
        $input = $request->validate(['weekly_enabled' => ['required', 'boolean']]);
        $preferences->update($request->user(), (bool) $input['weekly_enabled']);

        return $this->response($preferences->status($request->user()));
    }

    public function recipients(Request $request, MailAccess $access, ReportMailPreferences $preferences): JsonResponse
    {
        $member = $access->require($request->user());
        $input = $request->validate(['end_date' => ['required', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        if (! $preferences->schemaReady()) {
            return $this->response(['schema_ready' => false, 'auto_enabled' => false, 'recipients' => [], 'total' => 0, 'page' => 1, 'last_page' => 1]);
        }
        $query = OrganizationMembership::query()->where('organization_id', $member->organization_id)->where('is_default', true)->where('is_active', true)
            ->whereHas('user', function ($q) use ($input): void {
                if (filled($input['search'] ?? null)) {
                    $q->where(fn ($s) => $s->where('name', 'like', '%'.$input['search'].'%')->orWhere('email', 'like', '%'.$input['search'].'%'));
                }
            })->with(['user', 'department:id,name'])->orderBy('user_id');
        $members = $query->paginate(20);
        $prefs = ReportMailPreference::query()->where('organization_id', $member->organization_id)->whereIn('user_id', $members->pluck('user_id'))->get()->keyBy('user_id');
        $deliveries = PersonalWeeklyMailDelivery::query()->where('organization_id', $member->organization_id)->whereDate('end_date', $input['end_date'])
            ->whereIn('user_id', $members->pluck('user_id'))->get()->keyBy('user_id');
        $rows = $members->map(function ($m) use ($preferences, $prefs, $deliveries): array {
            $delivery = $deliveries->get($m->user_id);

            return ['user_id' => (int) $m->user_id, 'name' => $m->user->name, 'email' => $m->user->email, 'department' => $m->department?->name,
                'eligibility' => $preferences->eligibility($m->user, $prefs->get($m->user_id)), 'delivery_status' => $delivery?->status ?? 'not_scheduled', 'error_code' => $delivery?->error_code];
        });

        return $this->response(['schema_ready' => true, 'auto_enabled' => (bool) config('report_automation.personal_weekly_mail_enabled'),
            'start_date' => config('report_automation.personal_weekly_mail_start_date'), 'recipients' => $rows->values()->all(),
            'total' => $members->total(), 'page' => $members->currentPage(), 'last_page' => $members->lastPage()]);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}
