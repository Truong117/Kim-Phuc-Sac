<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use App\Models\OrganizationMembership;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanGoogleDocsTabMapping;
use App\Models\WeeklyPlanSourcePassage;
use Illuminate\Support\Collection;

class GoogleDocsAiAssignmentService
{
    /**
     * @return array{
     *   tabs: array<string, array{title: string, status: string, mapping_version: int, assignee: array{id: int, name: string, email: string}|null}>,
     *   fingerprint: string
     * }
     */
    public function previewContext(WeeklyPlan $plan): array
    {
        return $this->resolvedContext($plan, false, null, true);
    }

    /**
     * @param  list<string>  $tabIds
     * @return array{
     *   tabs: array<string, array{title: string, status: string, mapping_version: int, assignee: array{id: int, name: string, email: string}|null}>,
     *   fingerprint: string
     * }
     */
    public function previewContextForTabs(WeeklyPlan $plan, array $tabIds): array
    {
        $context = $this->resolvedContext($plan, false, $tabIds, false);
        $hasUnverifiedTab = collect($context['tabs'])->contains(
            fn (array $tab): bool => $tab['status'] !== WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value
                || ! is_array($tab['assignee']),
        );
        if ($hasUnverifiedTab) {
            throw new GoogleDocsSourceException(
                'GOOGLE_DOCS_SELECTED_TAB_NOT_VERIFIED',
                409,
                'Tất cả tab Google Docs đã chọn phải có mapping nhân viên đã xác minh.',
            );
        }

        return $context;
    }

    public function isReady(WeeklyPlan $plan): bool
    {
        try {
            $this->resolvedContext($plan, false, null, true);

            return true;
        } catch (GoogleDocsSourceException) {
            return false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  Collection<int, WeeklyPlanSourcePassage>  $passages
     * @return array<string, list<string>>
     */
    public function applyErrors(WeeklyPlan $plan, array $entries, Collection $passages): array
    {
        $errors = [];
        $entryTabIds = [];
        foreach ($entries as $index => $entry) {
            $tabIds = collect($entry['source_passage_ids'])
                ->map(fn (mixed $id): ?string => $this->passageTabId($passages->get((int) $id)))
                ->values();
            if ($tabIds->count() !== count($entry['source_passage_ids'])
                || $tabIds->contains(null)
                || $tabIds->unique()->count() !== 1
                || count($entry['source_passage_ids']) < 1) {
                $errors["entries.{$index}.source_passage_ids"] = [
                    'Các đoạn nguồn Google Docs của một đầu việc phải thuộc đúng một tab đã xác minh.',
                ];

                continue;
            }

            $entryTabIds[$index] = (string) $tabIds->first();
        }

        if ($errors !== []) {
            return $errors;
        }

        try {
            $context = $this->resolvedContext(
                $plan,
                true,
                array_values(array_unique($entryTabIds)),
                false,
            );
        } catch (GoogleDocsSourceException $exception) {
            return ['entries' => [$exception->getMessage()]];
        }

        foreach ($entries as $index => $entry) {
            $tabId = $entryTabIds[$index];

            $tab = $context['tabs'][$tabId] ?? null;
            if (! is_array($tab)
                || $tab['status'] !== WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value
                || ! is_array($tab['assignee'])) {
                $errors["entries.{$index}.source_passage_ids"] = [
                    'Mapping tab Google Docs không còn ở trạng thái đã xác minh.',
                ];

                continue;
            }
            if ((int) ($entry['assignee_id'] ?? 0) !== $tab['assignee']['id']) {
                $errors["entries.{$index}.assignee_id"] = [
                    'Người phụ trách không còn khớp với mapping tab Google Docs đã được HR xác minh.',
                ];
            }
        }

        return $errors;
    }

    public function passageTabId(?WeeklyPlanSourcePassage $passage): ?string
    {
        if ($passage === null || ! is_array($passage->metadata)) {
            return null;
        }

        $tabId = $passage->metadata['tab_id'] ?? null;
        $contextTabId = data_get($passage->metadata, 'source_context.evidence.tab_id');
        if (! is_string($tabId) || $tabId === '' || $contextTabId !== $tabId) {
            return null;
        }

        return $tabId;
    }

    /**
     * @return array{
     *   tabs: array<string, array{title: string, status: string, mapping_version: int, assignee: array{id: int, name: string, email: string}|null}>,
     *   fingerprint: string
     * }
     */
    /** @param list<string>|null $selectedTabIds */
    private function resolvedContext(
        WeeklyPlan $plan,
        bool $lockMappings,
        ?array $selectedTabIds,
        bool $requireResolvedManifest,
    ): array {
        $plan->loadMissing('googleDocsSource');
        $source = $plan->googleDocsSource;
        $manifest = is_array($source?->tab_manifest) ? $source->tab_manifest : [];
        if ($source === null || $manifest === []) {
            throw $this->incomplete();
        }

        $tabs = collect($manifest)
            ->filter(fn (mixed $tab): bool => is_array($tab)
                && is_string($tab['tab_id'] ?? null)
                && ($tab['tab_id'] ?? '') !== '')
            ->mapWithKeys(fn (array $tab): array => [
                $tab['tab_id'] => is_string($tab['title'] ?? null) ? $tab['title'] : '',
            ]);
        if ($tabs->count() !== count($manifest)) {
            throw $this->incomplete();
        }

        if ($selectedTabIds !== null) {
            $selectedTabIds = array_values(array_unique($selectedTabIds));
            if ($selectedTabIds === []
                || count(array_intersect($selectedTabIds, $tabs->keys()->all())) !== count($selectedTabIds)) {
                throw $this->incomplete();
            }
            $selectedLookup = array_fill_keys($selectedTabIds, true);
            $tabs = $tabs->filter(
                fn (string $title, string $tabId): bool => isset($selectedLookup[$tabId]),
            );
        }

        $mappingQuery = WeeklyPlanGoogleDocsTabMapping::query()
            ->where('organization_id', $plan->organization_id)
            ->where('weekly_plan_id', $plan->id)
            ->where('document_id_sha256', $source->document_id_sha256)
            ->whereIn('tab_id_sha256', $tabs->keys()->map(fn (string $tabId): string => hash('sha256', $tabId))->all())
            ->orderBy('tab_id_sha256');
        if ($lockMappings) {
            $mappingQuery->lockForUpdate();
        }
        $mappings = $mappingQuery->get()->keyBy('tab_id_sha256');

        $assigneeIds = $mappings
            ->filter(fn (WeeklyPlanGoogleDocsTabMapping $mapping): bool => $mapping->status === WeeklyPlanGoogleDocsMappingStatus::VERIFIED)
            ->pluck('assignee_user_id')
            ->filter()
            ->unique()
            ->values();
        $membershipQuery = OrganizationMembership::query()
            ->where('organization_id', $plan->organization_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereIn('user_id', $assigneeIds)
            ->whereHas('user')
            ->with('user:id,name,email')
            ->orderBy('user_id');
        if ($lockMappings) {
            $membershipQuery->lockForUpdate();
        }
        $eligibleMemberships = $membershipQuery->get()
            ->keyBy('user_id');

        $resolved = [];
        $verifiedCount = 0;
        foreach ($tabs as $tabId => $title) {
            $mapping = $mappings->get(hash('sha256', $tabId));
            if ($requireResolvedManifest
                && ($mapping === null || ! in_array($mapping->status, [
                    WeeklyPlanGoogleDocsMappingStatus::VERIFIED,
                    WeeklyPlanGoogleDocsMappingStatus::NOT_APPLICABLE,
                ], true))) {
                throw $this->incomplete();
            }

            $status = $mapping?->status?->value
                ?? WeeklyPlanGoogleDocsMappingStatus::UNVERIFIED->value;
            $assignee = null;
            if ($mapping?->status === WeeklyPlanGoogleDocsMappingStatus::VERIFIED) {
                $membership = $eligibleMemberships->get($mapping->assignee_user_id);
                if ($membership === null || $membership->user === null) {
                    throw $this->incomplete();
                }
                $verifiedCount++;
                $assignee = [
                    'id' => (int) $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                ];
            }

            $resolved[$tabId] = [
                'title' => $title,
                'status' => $status,
                'mapping_version' => (int) ($mapping?->version ?? 0),
                'assignee' => $assignee,
            ];
        }
        if ($requireResolvedManifest && $verifiedCount < 1) {
            throw $this->incomplete();
        }

        $fingerprintInput = collect($resolved)->map(fn (array $tab, string $tabId): array => [
            'tab_id' => $tabId,
            'status' => $tab['status'],
            'mapping_version' => $tab['mapping_version'],
            'assignee_id' => $tab['assignee']['id'] ?? null,
        ])->values()->all();

        return [
            'tabs' => $resolved,
            'fingerprint' => hash('sha256', json_encode($fingerprintInput, JSON_THROW_ON_ERROR)),
        ];
    }

    private function incomplete(): GoogleDocsSourceException
    {
        return new GoogleDocsSourceException(
            'GOOGLE_DOCS_MAPPING_INCOMPLETE',
            409,
            'Mapping nhân viên theo tab Google Docs chưa hoàn tất hoặc không còn hợp lệ.',
        );
    }
}
