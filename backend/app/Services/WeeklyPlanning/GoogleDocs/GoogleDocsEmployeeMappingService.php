<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanGoogleDocsTabMapping;
use App\Models\WeeklyPlanGoogleDocsTabMappingEvent;
use App\Services\KpsMembershipResolver;
use App\Services\WeeklyPlanning\WeeklyPlanScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GoogleDocsEmployeeMappingService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
    ) {}

    /** @return array<string, mixed> */
    public function overview(User $actor, int $planId): array
    {
        $plan = $this->viewablePlanFor($actor, $planId);

        return $this->buildOverview($plan, $actor);
    }

    /** @return array<string, mixed> */
    public function update(
        User $actor,
        int $planId,
        string $tabId,
        WeeklyPlanGoogleDocsMappingStatus $status,
        ?int $employeeId,
        int $expectedVersion,
    ): array {
        return $this->updateBatch($actor, $planId, [[
            'tab_id' => $tabId,
            'status' => $status,
            'employee_id' => $employeeId,
            'expected_version' => $expectedVersion,
        ]]);
    }

    /**
     * @param  list<array{tab_id: string, status: WeeklyPlanGoogleDocsMappingStatus, employee_id: int|null, expected_version: int}>  $updates
     * @return array<string, mixed>
     */
    public function updateBatch(User $actor, int $planId, array $updates): array
    {
        if ($updates === []) {
            throw new GoogleDocsEmployeeMappingException(
                'GOOGLE_DOCS_MAPPING_INVALID',
                422,
                'Phải có ít nhất một mapping để lưu.',
            );
        }

        $plan = $this->editablePlanFor($actor, $planId);
        $initialManifest = $plan->googleDocsSource?->tab_manifest;
        $seenTabIds = [];
        foreach ($updates as $update) {
            $tabId = $update['tab_id'];
            if (isset($seenTabIds[$tabId])) {
                throw new GoogleDocsEmployeeMappingException(
                    'GOOGLE_DOCS_MAPPING_INVALID',
                    422,
                    'Mỗi tab Google Docs chỉ được xuất hiện một lần trong batch.',
                );
            }
            $seenTabIds[$tabId] = true;

            if ($this->tabById($plan, $tabId) === null) {
                throw new GoogleDocsEmployeeMappingException(
                    'GOOGLE_DOCS_TAB_NOT_FOUND',
                    422,
                    'Tab Google Docs không còn thuộc nguồn tài liệu của kế hoạch.',
                );
            }
        }

        DB::transaction(function () use (
            $actor,
            $initialManifest,
            $plan,
            $updates,
        ): void {
            // Lock order for mapping writes is intentionally aligned with
            // UserManagementService: organization, actor membership/grants,
            // plan, mappings by tab hash, target memberships by user ID, then
            // mapping/event writes after every validation has passed.
            $organization = Organization::query()
                ->whereKey($plan->organization_id)
                ->where('code', KpsMembershipResolver::ORGANIZATION_CODE)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if ($organization === null) {
                throw new AccessDeniedHttpException;
            }

            $membership = $this->lockAuthorizedMembership(
                $actor,
                (int) $organization->id,
                'weekly_plans.manage_draft',
            );
            if ($membership === null
                || (int) $membership->organization_id !== (int) $plan->organization_id) {
                throw new AccessDeniedHttpException;
            }

            $lockedPlan = WeeklyPlan::query()
                ->whereKey($plan->id)
                ->where('organization_id', $membership->organization_id)
                ->with('googleDocsSource')
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertGoogleDocs($lockedPlan);
            $this->assertEditable($lockedPlan);

            if ($lockedPlan->googleDocsSource?->tab_manifest !== $initialManifest) {
                throw new GoogleDocsEmployeeMappingException(
                    'GOOGLE_DOCS_TAB_CHANGED',
                    409,
                    'Cấu trúc tab đã thay đổi. Vui lòng tải lại dữ liệu mapping.',
                );
            }

            $source = $lockedPlan->googleDocsSource;
            usort(
                $updates,
                static fn (array $first, array $second): int => strcmp(
                    hash('sha256', $first['tab_id']),
                    hash('sha256', $second['tab_id']),
                ),
            );
            $tabHashes = array_map(
                static fn (array $update): string => hash('sha256', $update['tab_id']),
                $updates,
            );
            $mappings = WeeklyPlanGoogleDocsTabMapping::query()
                ->where('organization_id', $lockedPlan->organization_id)
                ->where('weekly_plan_id', $lockedPlan->id)
                ->where('document_id_sha256', $source->document_id_sha256)
                ->whereIn('tab_id_sha256', $tabHashes)
                ->orderBy('tab_id_sha256')
                ->lockForUpdate()
                ->get()
                ->keyBy('tab_id_sha256');

            $employeeIds = collect($updates)
                ->filter(fn (array $update): bool => $update['status'] === WeeklyPlanGoogleDocsMappingStatus::VERIFIED
                    && $update['employee_id'] !== null)
                ->map(fn (array $update): int => (int) $update['employee_id'])
                ->unique()
                ->sort()
                ->values()
                ->all();
            $eligibleMemberships = $employeeIds === []
                ? collect()
                : OrganizationMembership::query()
                    ->where('organization_id', $lockedPlan->organization_id)
                    ->whereIn('user_id', $employeeIds)
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->whereHas('user')
                    ->orderBy('user_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('user_id');

            foreach ($updates as $update) {
                $mapping = $mappings->get(hash('sha256', $update['tab_id']));
                $currentVersion = $mapping?->version ?? 0;
                if ($update['expected_version'] !== $currentVersion) {
                    throw new GoogleDocsEmployeeMappingException(
                        'GOOGLE_DOCS_MAPPING_CONFLICT',
                        409,
                        'Mapping đã được cập nhật bởi một yêu cầu khác. Vui lòng tải lại trước khi lưu.',
                    );
                }
            }

            $decisions = [];
            foreach ($updates as $update) {
                $tabId = $update['tab_id'];
                $status = $update['status'];
                $employeeId = $update['employee_id'];
                $currentTab = $this->tabById($lockedPlan, $tabId);
                if ($currentTab === null) {
                    throw new GoogleDocsEmployeeMappingException(
                        'GOOGLE_DOCS_TAB_CHANGED',
                        409,
                        'Cấu trúc tab đã thay đổi. Vui lòng tải lại dữ liệu mapping.',
                    );
                }

                $assigneeId = null;
                if ($status === WeeklyPlanGoogleDocsMappingStatus::VERIFIED) {
                    if ($employeeId === null || ! $eligibleMemberships->has($employeeId)) {
                        throw new GoogleDocsEmployeeMappingException(
                            'GOOGLE_DOCS_EMPLOYEE_INELIGIBLE',
                            422,
                            'Nhân viên không còn thuộc tổ chức hoặc không đủ điều kiện nhận công việc.',
                        );
                    }
                    $assigneeId = $employeeId;
                } elseif ($employeeId !== null) {
                    throw new GoogleDocsEmployeeMappingException(
                        'GOOGLE_DOCS_MAPPING_INVALID',
                        422,
                        'Chỉ trạng thái đã xác minh mới được liên kết với nhân viên.',
                    );
                }

                $mapping = $mappings->get(hash('sha256', $tabId));
                $decisions[] = [
                    'tab_id' => $tabId,
                    'status' => $status,
                    'assignee_id' => $assigneeId,
                    'title_sha256' => $this->titleSha256((string) ($currentTab['title'] ?? '')),
                    'mapping' => $mapping,
                    'current_version' => $mapping?->version ?? 0,
                ];
            }

            $now = CarbonImmutable::now('UTC');
            foreach ($decisions as $decision) {
                $mapping = $decision['mapping'];
                $status = $decision['status'];
                $assigneeId = $decision['assignee_id'];
                $titleSha256 = $decision['title_sha256'];
                if ($mapping !== null && $this->sameDecision(
                    $mapping,
                    $status,
                    $assigneeId,
                    $titleSha256,
                )) {
                    continue;
                }

                $previousStatus = $mapping?->status?->value;
                $previousAssigneeId = $mapping?->assignee_user_id;
                $version = $decision['current_version'] + 1;
                $attributes = [
                    'organization_id' => $lockedPlan->organization_id,
                    'weekly_plan_id' => $lockedPlan->id,
                    'document_id_sha256' => $source->document_id_sha256,
                    'tab_id_sha256' => hash('sha256', $decision['tab_id']),
                    'tab_title_sha256' => $titleSha256,
                    'status' => $status,
                    'assignee_user_id' => $assigneeId,
                    'verified_by_user_id' => $actor->id,
                    'verified_at' => $now,
                    'version' => $version,
                ];

                if ($mapping === null) {
                    $mapping = WeeklyPlanGoogleDocsTabMapping::query()->create($attributes);
                } else {
                    $mapping->forceFill($attributes)->save();
                }

                WeeklyPlanGoogleDocsTabMappingEvent::query()->create([
                    'mapping_id' => $mapping->id,
                    'organization_id' => $lockedPlan->organization_id,
                    'weekly_plan_id' => $lockedPlan->id,
                    'actor_user_id' => $actor->id,
                    'previous_status' => $previousStatus,
                    'new_status' => $status->value,
                    'previous_assignee_user_id' => $previousAssigneeId,
                    'new_assignee_user_id' => $assigneeId,
                    'tab_title_sha256' => $titleSha256,
                    'mapping_version' => $version,
                    'created_at' => $now,
                ]);
            }
        });

        return $this->buildOverview($this->editablePlanFor($actor, $planId), $actor);
    }

    private function lockAuthorizedMembership(
        User $actor,
        int $organizationId,
        string $permission,
    ): ?OrganizationMembership {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if ($membership === null) {
            return null;
        }

        $grant = DB::table('membership_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'membership_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('membership_roles.membership_id', $membership->id)
            ->where('permissions.code', $permission)
            ->whereIn('role_permissions.data_scope', [
                DataScope::ORGANIZATION->value,
                DataScope::ALL->value,
            ])
            ->select('membership_roles.membership_id')
            ->lockForUpdate()
            ->first();

        return $grant === null ? null : $membership;
    }

    private function viewablePlanFor(User $actor, int $planId): WeeklyPlan
    {
        $plan = $this->scope->queryFor($actor, 'weekly_plans.view')
            ->with('googleDocsSource')
            ->findOrFail($planId);
        $this->assertGoogleDocs($plan);

        return $plan;
    }

    private function editablePlanFor(User $actor, int $planId): WeeklyPlan
    {
        $plan = $this->scope->queryFor($actor, 'weekly_plans.manage_draft')
            ->with('googleDocsSource')
            ->findOrFail($planId);
        $this->assertGoogleDocs($plan);

        return $plan;
    }

    private function assertGoogleDocs(WeeklyPlan $plan): void
    {
        if ($plan->source_type !== WeeklyPlanSourceType::GOOGLE_DOCS
            || $plan->googleDocsSource === null) {
            throw new GoogleDocsEmployeeMappingException(
                'GOOGLE_DOCS_SOURCE_REQUIRED',
                422,
                'Kế hoạch không sử dụng nguồn Google Docs bền vững.',
            );
        }
    }

    private function assertEditable(WeeklyPlan $plan): void
    {
        if ($plan->status !== WeeklyPlanStatus::DRAFT) {
            throw new GoogleDocsEmployeeMappingException(
                'GOOGLE_DOCS_MAPPING_LOCKED',
                409,
                'Kế hoạch không còn ở trạng thái cho phép cập nhật mapping nhân viên.',
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function tabById(WeeklyPlan $plan, string $tabId): ?array
    {
        $manifest = $plan->googleDocsSource?->tab_manifest;
        if (! is_array($manifest) || ! array_is_list($manifest)) {
            return null;
        }

        foreach ($manifest as $tab) {
            if (is_array($tab) && ($tab['tab_id'] ?? null) === $tabId) {
                return $tab;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function buildOverview(WeeklyPlan $plan, User $actor): array
    {
        $manifest = is_array($plan->googleDocsSource->tab_manifest)
            ? $plan->googleDocsSource->tab_manifest
            : [];
        $employees = $this->eligibleMemberships($plan);
        $employeeById = $employees->keyBy('user_id');
        $duplicateNames = $employees
            ->groupBy(fn (OrganizationMembership $item): string => $this->normalizedTitle($item->user->name))
            ->filter(fn (Collection $matches): bool => $matches->count() > 1)
            ->keys()
            ->all();
        $mappings = WeeklyPlanGoogleDocsTabMapping::query()
            ->where('organization_id', $plan->organization_id)
            ->where('weekly_plan_id', $plan->id)
            ->where('document_id_sha256', $plan->googleDocsSource->document_id_sha256)
            ->whereIn('tab_id_sha256', collect($manifest)
                ->filter(fn (mixed $tab): bool => is_array($tab) && is_string($tab['tab_id'] ?? null))
                ->map(fn (array $tab): string => hash('sha256', $tab['tab_id']))
                ->all())
            ->with('assignee:id,name,email')
            ->get()
            ->keyBy('tab_id_sha256');

        $tabs = collect($manifest)
            ->filter(fn (mixed $tab): bool => is_array($tab) && is_string($tab['tab_id'] ?? null))
            ->map(function (array $tab) use ($duplicateNames, $employeeById, $mappings, $plan): array {
                $mapping = $mappings->get(hash('sha256', $tab['tab_id']));
                $eligibleEmployee = $mapping?->assignee_user_id === null
                    ? null
                    : $employeeById->get($mapping->assignee_user_id);
                $employee = $eligibleEmployee === null
                    ? null
                    : $this->employeePayload($eligibleEmployee);
                $status = $mapping?->status?->value ?? 'UNVERIFIED';
                $reason = null;
                if ($status === WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value
                    && $eligibleEmployee === null) {
                    if ($plan->status !== WeeklyPlanStatus::DRAFT && $mapping?->assignee !== null) {
                        $employee = $this->historicalEmployeePayload($mapping->assignee);
                    } else {
                        $status = WeeklyPlanGoogleDocsMappingStatus::NEEDS_REVIEW->value;
                        $reason = 'EMPLOYEE_INELIGIBLE';
                    }
                }
                $suggestion = $plan->status === WeeklyPlanStatus::DRAFT
                    && in_array($status, ['UNVERIFIED', 'NEEDS_REVIEW'], true)
                    ? $this->suggestionFor(
                        $plan,
                        (string) $tab['tab_id'],
                        (string) ($tab['title'] ?? ''),
                        $employeeById,
                        $duplicateNames,
                    )
                    : null;

                return [
                    'tab_id' => $tab['tab_id'],
                    'title' => is_string($tab['title'] ?? null) ? $tab['title'] : '',
                    'parent_tab_id' => is_string($tab['parent_tab_id'] ?? null)
                        ? $tab['parent_tab_id']
                        : null,
                    'nesting_level' => is_int($tab['nesting_level'] ?? null)
                        ? $tab['nesting_level']
                        : 0,
                    'order' => is_int($tab['order'] ?? null) ? $tab['order'] : 0,
                    'status' => $status,
                    'employee' => $employee,
                    'suggestion' => $suggestion,
                    'version' => $mapping?->version ?? 0,
                    'verified_at' => $mapping?->verified_at?->toISOString(),
                    'reason' => $reason,
                ];
            })
            ->values()
            ->all();

        return [
            'tabs' => $tabs,
            'employees' => $employees->map(fn (OrganizationMembership $item): array => $this->employeePayload($item))->values()->all(),
            'actions' => [
                'can_update' => $plan->status === WeeklyPlanStatus::DRAFT
                    && $this->scope->allows($actor, 'weekly_plans.manage_draft'),
            ],
        ];
    }

    /** @return Collection<int, OrganizationMembership> */
    private function eligibleMemberships(WeeklyPlan $plan): Collection
    {
        return OrganizationMembership::query()
            ->where('organization_id', $plan->organization_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('user')
            ->with(['user:id,name,email', 'department:id,name', 'location:id,name'])
            ->get()
            ->sortBy(fn (OrganizationMembership $item): string => $item->user->name)
            ->values();
    }

    /** @param Collection<int, OrganizationMembership> $employeeById @param list<string> $duplicateNames */
    private function suggestionFor(
        WeeklyPlan $plan,
        string $tabId,
        string $title,
        Collection $employeeById,
        array $duplicateNames,
    ): ?array {
        $exactCandidateIds = WeeklyPlanGoogleDocsTabMapping::query()
            ->where('organization_id', $plan->organization_id)
            ->where('document_id_sha256', $plan->googleDocsSource->document_id_sha256)
            ->where('tab_id_sha256', hash('sha256', $tabId))
            ->where('status', WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value)
            ->where('weekly_plan_id', '!=', $plan->id)
            ->whereNotNull('assignee_user_id')
            ->distinct()
            ->pluck('assignee_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($exactCandidateIds->isNotEmpty()) {
            return $this->candidateSuggestion(
                $exactCandidateIds,
                $employeeById,
                $duplicateNames,
                'CONFIRMED_EXACT',
            );
        }

        $normalizedTitle = $this->normalizedTitle($title);
        if ($normalizedTitle === '') {
            return null;
        }

        $candidateIds = WeeklyPlanGoogleDocsTabMapping::query()
            ->where('organization_id', $plan->organization_id)
            ->where('tab_title_sha256', hash('sha256', $normalizedTitle))
            ->where('status', WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value)
            ->where('weekly_plan_id', '!=', $plan->id)
            ->whereNotNull('assignee_user_id')
            ->distinct()
            ->pluck('assignee_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        return $this->candidateSuggestion(
            $candidateIds,
            $employeeById,
            $duplicateNames,
            'CONFIRMED_ALIAS',
        );
    }

    /** @param SupportCollection<int, int> $candidateIds @param Collection<int, OrganizationMembership> $employeeById @param list<string> $duplicateNames */
    private function candidateSuggestion(
        SupportCollection $candidateIds,
        Collection $employeeById,
        array $duplicateNames,
        string $basis,
    ): ?array {
        if ($candidateIds->count() !== 1) {
            return null;
        }

        $candidate = $employeeById->get($candidateIds->first());
        if ($candidate === null
            || in_array($this->normalizedTitle($candidate->user->name), $duplicateNames, true)) {
            return null;
        }

        return [
            'employee' => $this->employeePayload($candidate),
            'basis' => $basis,
        ];
    }

    /** @return array<string, mixed> */
    private function employeePayload(OrganizationMembership $membership): array
    {
        return [
            'id' => $membership->user_id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'department' => $membership->department?->only(['id', 'name']),
            'location' => $membership->location?->only(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function historicalEmployeePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department' => null,
            'location' => null,
        ];
    }

    private function sameDecision(
        WeeklyPlanGoogleDocsTabMapping $mapping,
        WeeklyPlanGoogleDocsMappingStatus $status,
        ?int $assigneeId,
        string $titleSha256,
    ): bool {
        return $mapping->status === $status
            && $mapping->assignee_user_id === $assigneeId
            && hash_equals($mapping->tab_title_sha256, $titleSha256);
    }

    private function titleSha256(string $title): string
    {
        return hash('sha256', $this->normalizedTitle($title));
    }

    private function normalizedTitle(string $title): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));
    }
}
