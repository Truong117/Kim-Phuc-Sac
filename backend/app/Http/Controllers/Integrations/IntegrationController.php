<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\SaveIntegrationRequest;
use App\Models\IntegrationEntry;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Integrations\IntegrationUrl;
use App\Services\KpsMembershipResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public function __construct(private readonly KpsMembershipResolver $memberships, private readonly AuthorizationService $authorization) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        return response()->json([
            'data' => IntegrationEntry::query()->where('organization_id', $organization)->with('responsible:id,name')->orderBy('id')->get()->map(fn (IntegrationEntry $entry): array => $this->payload($entry)),
            'actions' => ['can_manage' => $this->authorization->allows($request->user(), 'integrations.manage')],
        ]);
    }

    public function references(Request $request): JsonResponse
    {
        return response()->json(['data' => OrganizationMembership::query()
            ->where('organization_id', $this->organization($request))->where('is_active', true)->where('is_default', true)
            ->with('user:id,name')->get()->pluck('user')->filter()->unique('id')->sortBy('name')->values()
            ->map(fn (User $user): array => ['id' => (string) $user->id, 'name' => $user->name])]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $entry = IntegrationEntry::query()->where('organization_id', $this->organization($request))->with('responsible:id,name')->findOrFail($id);

        return response()->json(['data' => $this->payload($entry)]);
    }

    public function store(SaveIntegrationRequest $request): JsonResponse
    {
        return $this->save($request);
    }

    public function update(SaveIntegrationRequest $request, int $id): JsonResponse
    {
        return $this->save($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $id, $input): void {
            Organization::query()->whereKey($this->organization($request))->lockForUpdate()->firstOrFail();
            $entry = IntegrationEntry::query()->where('organization_id', $this->organization($request))->lockForUpdate()->findOrFail($id);
            $this->assertVersion($entry, (int) $input['version']);
            $entry->delete();
        });

        return response()->json(null, 204);
    }

    private function save(SaveIntegrationRequest $request, ?int $id = null): JsonResponse
    {
        $input = $request->validated();
        $organization = $this->organization($request);
        try {
            $entry = DB::transaction(function () use ($request, $input, $organization, $id): IntegrationEntry {
                Organization::query()->whereKey($organization)->lockForUpdate()->firstOrFail();
                $entry = $id === null ? new IntegrationEntry : IntegrationEntry::query()->where('organization_id', $organization)->lockForUpdate()->findOrFail($id);
                if ($id !== null) {
                    $this->assertVersion($entry, (int) $input['version']);
                    if ($entry->kind !== $input['kind']) {
                        throw ValidationException::withMessages(['kind' => 'Không thể chuyển loại tích hợp.']);
                    }
                } elseif (IntegrationEntry::query()->where('organization_id', $organization)->count() >= 500) {
                    throw ValidationException::withMessages(['name' => 'Danh mục đã đạt giới hạn 500 mục.']);
                }
                $responsible = OrganizationMembership::query()->where('organization_id', $organization)->where('user_id', $input['responsible_user_id'])->where('is_active', true)->where('is_default', true)->lockForUpdate()->first();
                if ($responsible === null) {
                    throw ValidationException::withMessages(['responsible_user_id' => 'Chọn nhân viên đang hoạt động trong KPS.']);
                }
                $hash = hash('sha256', IntegrationUrl::identity($input['url'], $input['kind'], $input['platform'] ?? null));
                if (IntegrationEntry::query()->where('organization_id', $organization)->where('kind', $input['kind'])->where('url_hash', $hash)->when($id !== null, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                    throw ValidationException::withMessages(['url' => 'Đường dẫn này đã có trong danh sách.']);
                }
                $entry->fill([
                    'organization_id' => $organization, 'kind' => $input['kind'],
                    'platform' => $input['kind'] === 'drive' ? null : $input['platform'],
                    'name' => $input['name'], 'url' => $input['url'], 'url_hash' => $hash,
                    'responsible_user_id' => $input['responsible_user_id'], 'note' => $input['note'] ?? '', 'enabled' => $input['enabled'],
                    'version' => $id === null ? 1 : $entry->version + 1,
                    'created_by' => $id === null ? $request->user()->id : $entry->created_by, 'updated_by' => $request->user()->id,
                ])->save();

                return $entry->load('responsible:id,name');
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true) && (str_contains($exception->getMessage(), 'integration_entries_url_unique') || str_contains($exception->getMessage(), 'UNIQUE constraint failed: integration_entries'))) {
                throw ValidationException::withMessages(['url' => 'Đường dẫn này đã có trong danh sách.']);
            }

            throw $exception;
        }

        return response()->json(['data' => $this->payload($entry)], $id === null ? 201 : 200);
    }

    private function assertVersion(IntegrationEntry $entry, int $version): void
    {
        abort_if($entry->version !== $version, 409, 'Mục này đã được người khác thay đổi. Tải lại danh sách trước khi thao tác.');
    }

    private function organization(Request $request): int
    {
        $membership = $this->memberships->activeMembershipFor($request->user());
        abort_if($membership === null, 403);

        return (int) $membership->organization_id;
    }

    private function payload(IntegrationEntry $entry): array
    {
        return [
            'id' => (string) $entry->id, 'kind' => $entry->kind, 'platform' => $entry->platform,
            'name' => $entry->name, 'url' => $entry->url, 'responsible_user_id' => (string) $entry->responsible_user_id,
            'owner' => $entry->responsible->name, 'note' => $entry->note ?? '', 'enabled' => $entry->enabled,
            'version' => $entry->version, 'connection_status' => 'not_connected',
            'updated_at' => $entry->updated_at->toIso8601String(),
        ];
    }
}
