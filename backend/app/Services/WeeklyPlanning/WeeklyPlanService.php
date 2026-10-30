<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanReviewRoundStatus;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanSuggestionStatus;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanComment;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanEntryApplication;
use App\Models\WeeklyPlanEvent;
use App\Models\WeeklyPlanGoogleDocsTabMapping;
use App\Models\WeeklyPlanGoogleDocsTabMappingEvent;
use App\Models\WeeklyPlanReviewDecision;
use App\Models\WeeklyPlanReviewRound;
use App\Models\WeeklyPlanSourcePassage;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsAiAssignmentService;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsSourceException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class WeeklyPlanService
{
    private const EDITABLE_STATUSES = [
        WeeklyPlanStatus::DRAFT,
        WeeklyPlanStatus::CHANGES_REQUESTED,
    ];

    private const CORRECTION_STATUSES = [
        WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED,
        WeeklyPlanStatus::OWNER_CHANGES_REQUESTED,
    ];

    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanClock $clock,
        private readonly WeeklyPlanFileParser $parser,
        private readonly WeeklyPlanEntryValidator $validator,
        private readonly WeeklyPlanSuggestionGenerator $suggestions,
        private readonly WeeklyPlanPublisher $publisher,
        private readonly WeeklyPlanReviewWorkflowService $reviewWorkflow,
        private readonly WeeklyPlanReadinessService $planReadiness,
        private readonly WeeklyPlanWorkflowVersionGuard $workflowVersions,
        private readonly WeeklyPlanTaskExtractionService $taskExtraction,
        private readonly GoogleDocsAiAssignmentService $googleDocsAiAssignments,
    ) {}

    /** @return LengthAwarePaginator<WeeklyPlan> */
    public function paginate(User $viewer, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->scope->queryFor($viewer)
            ->with('creator:id,name')
            ->withCount(['entries', 'employeeReviews'])
            ->orderByDesc('week_start')
            ->paginate($perPage);

        $canManageDrafts = $this->scope->allows($viewer, 'weekly_plans.manage_draft');
        $hasAssistantReviewPermission = $this->scope->allows(
            $viewer,
            'weekly_plans.assistant_review',
        );
        $hasOwnerReviewPermission = $this->scope->allows($viewer, 'weekly_plans.review');
        $paginator->setCollection($paginator->getCollection()->map(
            function (WeeklyPlan $plan) use (
                $canManageDrafts,
                $hasAssistantReviewPermission,
                $hasOwnerReviewPermission,
            ): WeeklyPlan {
                $v2Workflow = $this->workflowVersions->isV2($plan);
                $plan->setAttribute('actions', [
                    'can_delete' => $v2Workflow
                        && $canManageDrafts
                        && $plan->status === WeeklyPlanStatus::DRAFT,
                    'can_assistant_review' => $v2Workflow
                        && $hasAssistantReviewPermission
                        && $plan->status === WeeklyPlanStatus::ASSISTANT_REVIEW,
                    'can_owner_review' => $v2Workflow
                        && $hasOwnerReviewPermission
                        && $plan->status === WeeklyPlanStatus::OWNER_REVIEW,
                    'can_open_review_workspace' => $v2Workflow && $this->canOpenReviewWorkspace(
                        $plan->status,
                        $hasAssistantReviewPermission,
                        $hasOwnerReviewPermission,
                    ),
                ]);

                return $plan;
            },
        ));

        return $paginator;
    }

    /** @return array<string, mixed> */
    public function references(User $viewer): array
    {
        $membership = $this->membershipOrFail($viewer);
        $employees = OrganizationMembership::query()
            ->where('organization_id', $membership->organization_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('user')
            ->with(['user:id,name,email', 'department:id,name', 'location:id,name'])
            ->get()
            ->sortBy(fn (OrganizationMembership $item): string => $item->user->name)
            ->values()
            ->map(fn (OrganizationMembership $item): array => [
                'id' => $item->user_id,
                'name' => $item->user->name,
                'email' => $item->user->email,
                'department' => $item->department?->only(['id', 'name']),
                'location' => $item->location?->only(['id', 'name']),
            ])
            ->all();

        return [
            'next_week' => $this->clock->nextWeek(),
            'employees' => $employees,
            'actions' => [
                'can_import' => $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            ],
        ];
    }

    public function findVisible(
        User $viewer,
        int $planId,
        string $permission = 'weekly_plans.view',
    ): WeeklyPlan {
        return $this->load(
            $this->scope->queryFor($viewer, $permission)->findOrFail($planId),
            $viewer,
        );
    }

    /** @param array<string, string> $mapping */
    public function import(User $actor, UploadedFile $file, array $mapping): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.manage_draft');
        $membership = $this->membershipOrFail($actor);
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $this->assertFileType($extension, $mime);

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => ['Không thể đọc tệp tải lên.']]);
        }

        try {
            $parsed = $this->parser->parse($path, $extension, $mapping);
            $passages = $extension === 'docx'
                ? $this->parser->extractDocumentText($path, $extension)
                : [];
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => [$exception->getMessage()]]);
        }

        $week = $this->clock->nextWeek();
        if (WeeklyPlan::query()
            ->where('organization_id', $membership->organization_id)
            ->whereDate('week_start', $week['start'])
            ->exists()) {
            throw new ConflictHttpException('Tổ chức đã có kế hoạch cho tuần này.');
        }

        $disk = (string) config('weekly_plans.storage_disk', 'local');
        $storedPath = sprintf(
            'weekly-plans/%d/%s.%s',
            $membership->organization_id,
            Str::uuid(),
            $extension,
        );
        $contents = fopen($path, 'rb');
        if ($contents === false || Storage::disk($disk)->put($storedPath, $contents, ['visibility' => 'private']) !== true) {
            if (is_resource($contents)) {
                fclose($contents);
            }
            throw new RuntimeException('Không thể lưu tệp nguồn vào kho lưu trữ riêng tư.');
        }
        if (is_resource($contents)) {
            fclose($contents);
        }

        try {
            $plan = DB::transaction(function () use (
                $actor,
                $disk,
                $extension,
                $file,
                $membership,
                $mime,
                $parsed,
                $passages,
                $path,
                $storedPath,
                $week,
            ): WeeklyPlan {
                $plan = WeeklyPlan::query()->create([
                    'organization_id' => $membership->organization_id,
                    'created_by' => $actor->id,
                    'week_start' => $week['start'],
                    'week_end' => $week['end'],
                    'status' => WeeklyPlanStatus::DRAFT,
                    'source_type' => WeeklyPlanSourceType::UPLOADED_FILE,
                    'source_disk' => $disk,
                    'source_path' => $storedPath,
                    'original_filename' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'source_extension' => $extension,
                    'source_mime' => $mime,
                    'source_size' => $file->getSize() ?: filesize($path),
                    'source_sha256' => hash_file('sha256', $path),
                    'parse_status' => $parsed['needs_mapping']
                        ? WeeklyPlanParseStatus::NEEDS_MAPPING
                        : WeeklyPlanParseStatus::PARSED,
                    'parse_metadata' => [
                        'headers' => $parsed['headers'],
                        'errors' => $parsed['errors'],
                        'row_count' => count($parsed['rows']),
                    ],
                    'uploaded_at' => CarbonImmutable::now('UTC'),
                ]);

                $this->replaceParsedEntries($plan, $parsed['rows']);
                $this->record($plan, $actor, 'IMPORTED', [
                    'row_count' => count($parsed['rows']),
                    'sha256' => $plan->source_sha256,
                ]);
                if ($extension === 'docx') {
                    $this->persistSourcePassages($plan, $passages);
                    $this->record($plan, $actor, 'SOURCE_TEXT_EXTRACTED', [
                        'source_sha256' => $plan->source_sha256,
                        'passage_count' => count($passages),
                        'status' => $passages === [] ? 'EMPTY' : 'EXTRACTED',
                    ]);
                }

                return $plan;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);
            if ($exception instanceof QueryException && $this->isDuplicatePlanException($exception)) {
                throw new ConflictHttpException('Tổ chức đã có kế hoạch cho tuần này.', $exception);
            }
            throw $exception;
        }

        return $this->load($plan, $actor);
    }

    public function extractSourceText(User $actor, int $planId): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);
        if ($this->sourceWasExtracted($plan)) {
            return $this->findVisible($actor, $planId);
        }

        $passages = $this->readSourcePassages($plan);

        DB::transaction(function () use ($actor, $passages, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            if ($locked->source_sha256 !== $plan->source_sha256) {
                throw new ConflictHttpException('Tệp nguồn đã thay đổi. Vui lòng tải lại kế hoạch.');
            }
            if ($this->sourceWasExtracted($locked)) {
                return;
            }

            $locked->sourcePassages()
                ->where('source_sha256', $locked->source_sha256)
                ->delete();
            $this->persistSourcePassages($locked, $passages);
            $this->record($locked, $actor, 'SOURCE_TEXT_EXTRACTED', [
                'source_sha256' => $locked->source_sha256,
                'passage_count' => count($passages),
                'status' => $passages === [] ? 'EMPTY' : 'EXTRACTED',
            ]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function replaceMissingSource(User $actor, int $planId, UploadedFile $file): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);
        $sourceAvailability = $this->sourceAvailability($plan);
        if ($sourceAvailability === 'AVAILABLE') {
            throw new ConflictHttpException('Tệp nguồn hiện tại vẫn còn khả dụng.');
        }
        if ($sourceAvailability === 'UNAVAILABLE') {
            throw new ServiceUnavailableHttpException(null, 'Chưa thể kiểm tra kho lưu trữ tệp nguồn. Vui lòng thử lại sau.');
        }

        $extension = mb_strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $this->assertFileType($extension, $mime);
        if ($extension !== $plan->source_extension) {
            throw ValidationException::withMessages([
                'file' => [sprintf('Vui lòng tải lại tệp .%s đúng định dạng ban đầu.', $plan->source_extension)],
            ]);
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => ['Không thể đọc tệp tải lên.']]);
        }
        try {
            if ($extension === 'docx') {
                $passages = $this->parser->extractDocumentText($path, $extension);
            } else {
                $metadata = is_array($plan->parse_metadata) ? $plan->parse_metadata : [];
                $mapping = is_array($metadata['mapping'] ?? null) ? $metadata['mapping'] : [];
                $this->parser->parse($path, $extension, $mapping);
                $passages = [];
            }
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => [$exception->getMessage()]]);
        }

        $disk = (string) config('weekly_plans.storage_disk', 'local');
        $storedPath = sprintf('weekly-plans/%d/%s.%s', $plan->organization_id, Str::uuid(), $extension);
        $contents = fopen($path, 'rb');
        if ($contents === false || Storage::disk($disk)->put($storedPath, $contents, ['visibility' => 'private']) !== true) {
            if (is_resource($contents)) {
                fclose($contents);
            }
            throw new RuntimeException('Không thể lưu tệp nguồn vào kho lưu trữ riêng tư.');
        }
        if (is_resource($contents)) {
            fclose($contents);
        }

        $oldDisk = $plan->source_disk;
        $oldPath = $plan->source_path;

        try {
            DB::transaction(function () use (
                $actor,
                $disk,
                $extension,
                $file,
                $mime,
                $passages,
                $path,
                $plan,
                $storedPath,
            ): void {
                $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
                $this->assertEditable($locked);
                $lockedAvailability = $this->sourceAvailability($locked);
                if ($lockedAvailability === 'AVAILABLE') {
                    throw new ConflictHttpException('Tệp nguồn hiện tại vẫn còn khả dụng.');
                }
                if ($lockedAvailability === 'UNAVAILABLE') {
                    throw new ServiceUnavailableHttpException(null, 'Chưa thể kiểm tra kho lưu trữ tệp nguồn. Vui lòng thử lại sau.');
                }

                $locked->forceFill([
                    'source_disk' => $disk,
                    'source_path' => $storedPath,
                    'original_filename' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'source_extension' => $extension,
                    'source_mime' => $mime,
                    'source_size' => $file->getSize() ?: filesize($path),
                    'source_sha256' => hash_file('sha256', $path),
                    'uploaded_at' => CarbonImmutable::now('UTC'),
                ])->save();

                $this->record($locked, $actor, 'SOURCE_FILE_REPLACED', [
                    'source_sha256' => $locked->source_sha256,
                    'passage_count' => count($passages),
                ]);
                if (! $this->sourceWasExtracted($locked)) {
                    $this->persistSourcePassages($locked, $passages);
                    $this->record($locked, $actor, 'SOURCE_TEXT_EXTRACTED', [
                        'source_sha256' => $locked->source_sha256,
                        'passage_count' => count($passages),
                        'status' => $passages === [] ? 'EMPTY' : 'EXTRACTED',
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);
            throw $exception;
        }

        if (is_string($oldDisk) && $oldDisk !== '' && is_string($oldPath) && $oldPath !== '') {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $this->findVisible($actor, $planId);
    }

    /** @param list<int> $passageIds */
    public function assignSourceGroup(
        User $actor,
        int $planId,
        array $passageIds,
        string $groupTitle,
    ): WeeklyPlan {
        $plan = $this->editablePlan($actor, $planId);
        $groupTitle = trim($groupTitle);

        DB::transaction(function () use ($actor, $groupTitle, $passageIds, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            if ($locked->source_sha256 !== $plan->source_sha256) {
                throw new ConflictHttpException('Tệp nguồn đã thay đổi. Vui lòng tải lại kế hoạch.');
            }
            if (! $this->sourceWasExtracted($locked)) {
                throw new ConflictHttpException('Chưa có nội dung tài liệu nguồn để phân nhóm.');
            }

            $passages = WeeklyPlanSourcePassage::query()
                ->where('weekly_plan_id', $locked->id)
                ->where('source_sha256', $locked->source_sha256)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->values();
            $requestedIds = collect($passageIds)
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            if ($requestedIds->isEmpty()
                || $requestedIds->count() !== count($passageIds)
                || $passages->isEmpty()) {
                throw ValidationException::withMessages([
                    'passage_ids' => ['Các đoạn nguồn được chọn không hợp lệ.'],
                ]);
            }

            $positionsById = $passages
                ->mapWithKeys(fn (WeeklyPlanSourcePassage $passage, int $position): array => [
                    $passage->id => $position,
                ]);
            $positions = $requestedIds
                ->map(fn (int $id): mixed => $positionsById->get($id))
                ->filter(fn (mixed $position): bool => is_int($position))
                ->sort()
                ->values();
            if ($positions->count() !== $requestedIds->count()
                || $positions->last() - $positions->first() + 1 !== $positions->count()) {
                throw ValidationException::withMessages([
                    'passage_ids' => ['Chỉ có thể gán nhãn cho một dải đoạn nguồn liên tiếp.'],
                ]);
            }

            $startPosition = (int) $positions->first();
            $endPosition = (int) $positions->last();
            $selectedGroup = $this->manualSourceGroup(
                $locked,
                $groupTitle,
                $passages[$startPosition],
                $passages[$endPosition],
            );
            $groups = [];
            $requestedLookup = $requestedIds->flip();
            foreach ($passages as $position => $passage) {
                $metadata = is_array($passage->metadata) ? $passage->metadata : [];
                $groups[$position] = $requestedLookup->has($passage->id)
                    ? $selectedGroup
                    : ($metadata['source_group'] ?? null);
            }

            // Re-key every resulting contiguous manual segment. This keeps a
            // stable invariant when a newly assigned range splits or merges a
            // previously labelled range, instead of leaving one key attached
            // to two disconnected passage ranges.
            $position = 0;
            while ($position < $passages->count()) {
                $group = $groups[$position];
                if (! $this->isManualSourceGroup($group)) {
                    $position++;

                    continue;
                }

                $end = $position;
                while ($end + 1 < $passages->count()
                    && $this->isManualSourceGroup($groups[$end + 1])
                    && $groups[$end + 1]['key'] === $group['key']) {
                    $end++;
                }

                $normalized = $this->manualSourceGroup(
                    $locked,
                    (string) $group['title'],
                    $passages[$position],
                    $passages[$end],
                );
                for ($index = $position; $index <= $end; $index++) {
                    $groups[$index] = $normalized;
                }
                $position = $end + 1;
            }

            $changedPassageIds = [];
            $previousGroupKeys = [];
            foreach ($passages as $position => $passage) {
                $metadata = is_array($passage->metadata) ? $passage->metadata : [];
                $previousGroup = $metadata['source_group'] ?? null;
                if ($previousGroup === $groups[$position]) {
                    continue;
                }
                if (is_array($previousGroup) && is_string($previousGroup['key'] ?? null)) {
                    $previousGroupKeys[] = $previousGroup['key'];
                }

                $metadata['source_group'] = $groups[$position];
                $passage->forceFill(['metadata' => $metadata])->save();
                $changedPassageIds[] = $passage->id;
            }

            if ($changedPassageIds !== []) {
                $assignedGroup = $groups[$startPosition];
                $this->record($locked, $actor, 'SOURCE_GROUP_ASSIGNED', [
                    'source_sha256' => $locked->source_sha256,
                    'group_key' => $assignedGroup['key'],
                    'group_title' => $assignedGroup['title'],
                    'start_sequence' => $passages[$startPosition]->sequence,
                    'end_sequence' => $passages[$endPosition]->sequence,
                    'passage_count' => $requestedIds->count(),
                    'normalized_passage_count' => count($changedPassageIds),
                    'previous_group_keys' => array_values(array_unique($previousGroupKeys)),
                ]);
            }
        });

        return $this->findVisible($actor, $planId);
    }

    /** @param list<array<string, mixed>> $entries */
    public function applySourceEntries(
        User $actor,
        int $planId,
        string $requestId,
        array $entries,
    ): WeeklyPlan {
        $plan = $this->editablePlan($actor, $planId);
        $payloadHash = hash('sha256', json_encode([
            'source_sha256' => $plan->source_sha256,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        DB::transaction(function () use ($actor, $entries, $payloadHash, $plan, $requestId): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            $existing = WeeklyPlanEntryApplication::query()
                ->where('weekly_plan_id', $locked->id)
                ->where('idempotency_key', $requestId)
                ->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_sha256, $payloadHash)) {
                    throw new ConflictHttpException('Mã yêu cầu đã được dùng với nội dung khác.');
                }

                return;
            }

            $passageIds = collect($entries)
                ->flatMap(function (array $entry): array {
                    $itemPassageIds = collect($entry['items'] ?? [])
                        ->flatMap(fn (array $item): array => $item['source_passage_ids'] ?? [])
                        ->all();

                    return [...$entry['source_passage_ids'], ...$itemPassageIds];
                })
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $passages = WeeklyPlanSourcePassage::query()
                ->where('weekly_plan_id', $locked->id)
                ->where('source_sha256', $locked->source_sha256)
                ->whereIn('id', $passageIds)
                ->get()
                ->keyBy('id');
            if ($passages->count() !== $passageIds->count()) {
                throw ValidationException::withMessages([
                    'entries' => ['Đoạn nguồn không còn thuộc phiên bản tài liệu hiện tại.'],
                ]);
            }

            if ($locked->source_type === WeeklyPlanSourceType::GOOGLE_DOCS) {
                $processedLookup = array_fill_keys(
                    $this->googleDocsAiAssignments->processedTabIds($locked),
                    true,
                );
                $selectedTabIds = $passages
                    ->map(fn (WeeklyPlanSourcePassage $passage): ?string => $this->googleDocsAiAssignments->passageTabId($passage))
                    ->filter()
                    ->unique();
                if ($selectedTabIds->contains(
                    fn (string $tabId): bool => isset($processedLookup[$tabId]),
                )) {
                    throw ValidationException::withMessages([
                        'entries' => ['Một hoặc nhiều nhóm Google Docs đã được áp dụng. Vui lòng tải lại kế hoạch và chỉ chọn nhóm còn lại.'],
                    ]);
                }
            }

            $preparedEntries = [];
            $validationErrors = $locked->source_type === WeeklyPlanSourceType::GOOGLE_DOCS
                ? $this->googleDocsAiAssignments->applyErrors($locked, $entries, $passages)
                : [];
            foreach ($entries as $index => $attributes) {
                $sourcePassageIds = array_values(array_map('intval', $attributes['source_passage_ids']));
                $items = $attributes['items'] ?? [];
                unset($attributes['items']);
                $sourceGroupKeys = collect($sourcePassageIds)
                    ->map(fn (int $passageId): ?string => $this->sourceGroupKey($passages->get($passageId)));
                $groupedSourceKeys = $sourceGroupKeys
                    ->filter(fn (?string $key): bool => $key !== null)
                    ->values();
                if ($groupedSourceKeys->isNotEmpty()
                    && ($groupedSourceKeys->count() !== $sourceGroupKeys->count()
                        || $groupedSourceKeys->unique()->count() !== 1)) {
                    $validationErrors['entries.'.$index.'.source_passage_ids'] = [
                        'Các đoạn nguồn của một đầu việc phải thuộc cùng một nhóm.',
                    ];
                }
                unset($attributes['source_passage_ids']);
                $attributes['source_origin'] = 'DOCUMENT_PASSAGES';
                $attributes['source_passage_ids'] = $sourcePassageIds;
                $prepared = $this->prepareEntry($locked, $attributes);
                if ($prepared['validation_errors'] !== []) {
                    $validationErrors['entries.'.$index] = $prepared['validation_errors'];
                }
                $preparedItems = $this->prepareItems(
                    $items,
                    $sourcePassageIds,
                    $locked->source_type === WeeklyPlanSourceType::GOOGLE_DOCS,
                    'entries.'.$index.'.items',
                    $validationErrors,
                );
                $preparedEntries[] = [$prepared, $sourcePassageIds, $preparedItems];
            }
            if ($validationErrors !== []) {
                throw ValidationException::withMessages($validationErrors);
            }

            $application = WeeklyPlanEntryApplication::query()->create([
                'weekly_plan_id' => $locked->id,
                'created_by' => $actor->id,
                'idempotency_key' => $requestId,
                'payload_sha256' => $payloadHash,
                'entry_ids' => [],
            ]);
            $sortOrder = (int) $locked->entries()->max('sort_order');
            $entryIds = [];
            $itemCount = 0;
            foreach ($preparedEntries as [$prepared, $sourcePassageIds, $preparedItems]) {
                $entry = $locked->entries()->create($prepared + ['sort_order' => ++$sortOrder]);
                $entry->sourcePassages()->attach($sourcePassageIds);
                $this->replaceEntryItems($entry, $preparedItems);
                $itemCount += count($preparedItems);
                $entryIds[] = $entry->id;
            }
            $application->forceFill(['entry_ids' => $entryIds])->save();

            $this->syncReviews($locked);
            $this->invalidateSuggestions($locked, $actor, 'SOURCE_ENTRIES_APPLIED');
            $this->record($locked, $actor, 'SOURCE_ENTRIES_APPLIED', [
                'application_id' => $application->id,
                'entry_count' => count($entryIds),
                'item_count' => $itemCount,
                'passage_count' => $passageIds->count(),
            ]);
        });

        return $this->findVisible($actor, $planId);
    }

    /**
     * @param  list<int>|null  $sourcePassageIds
     * @return list<array<string, mixed>>
     */
    public function previewAiSourceEntries(
        User $actor,
        int $planId,
        ?array $sourcePassageIds = null,
    ): array {
        $plan = $this->editablePlan($actor, $planId);
        $plan->load(['sourcePassages' => fn ($query) => $query
            ->where('source_sha256', $plan->source_sha256)
            ->orderBy('sequence')]);
        if ($plan->sourcePassages->isEmpty()) {
            throw ValidationException::withMessages([
                'source' => ['Chưa có nội dung tài liệu nguồn để trích xuất.'],
            ]);
        }

        $googleDocsContext = null;
        $selectionFingerprint = null;
        if ($plan->source_type === WeeklyPlanSourceType::GOOGLE_DOCS) {
            $processedLookup = array_fill_keys(
                $this->googleDocsAiAssignments->processedTabIds($plan),
                true,
            );
            if ($sourcePassageIds !== null) {
                $requestedIds = collect($sourcePassageIds)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values();
                $knownPassages = $plan->sourcePassages->keyBy('id');
                if ($requestedIds->count() !== count($sourcePassageIds)
                    || $requestedIds->contains(fn (int $id): bool => ! $knownPassages->has($id))) {
                    throw ValidationException::withMessages([
                        'source_passage_ids' => ['Đoạn nguồn đã chọn không thuộc phiên bản Google Docs hiện tại.'],
                    ]);
                }

                $selectedIdLookup = $requestedIds->flip();
                $selectedPassages = $plan->sourcePassages
                    ->filter(fn (WeeklyPlanSourcePassage $passage): bool => $selectedIdLookup->has($passage->id))
                    ->values();
                $selectedTabIds = $selectedPassages
                    ->map(fn (WeeklyPlanSourcePassage $passage): ?string => $this->googleDocsAiAssignments->passageTabId($passage));
                if ($selectedTabIds->contains(null)) {
                    throw new GoogleDocsSourceException(
                        'GOOGLE_DOCS_SOURCE_METADATA_INVALID',
                        409,
                        'Metadata tab của nguồn Google Docs không còn hợp lệ.',
                    );
                }
                $selectedTabIds = $selectedTabIds->unique()->values();
                $processedSelectedTabIds = $selectedTabIds
                    ->filter(fn (string $tabId): bool => isset($processedLookup[$tabId]));
                if ($processedSelectedTabIds->isNotEmpty()) {
                    $message = $processedSelectedTabIds->count() === $selectedTabIds->count()
                        ? 'Các nhóm Google Docs đã chọn đã được áp dụng.'
                        : 'Không thể kết hợp nhóm Google Docs đã áp dụng với nhóm chưa xử lý. Vui lòng tải lại và chọn lại các nhóm còn lại.';
                    throw ValidationException::withMessages([
                        'source_passage_ids' => [$message],
                    ]);
                }
                $googleDocsContext = $this->googleDocsAiAssignments->previewContextForTabs(
                    $plan,
                    $selectedTabIds->all(),
                );
                $plan->setRelation('sourcePassages', $selectedPassages);
                $selectionFingerprint = hash('sha256', $selectedPassages
                    ->pluck('id')
                    ->sort()
                    ->implode(','));
            } else {
                $allTabsContext = $this->googleDocsAiAssignments->previewContext($plan);
                $remainingPassages = $plan->sourcePassages->filter(
                    function (WeeklyPlanSourcePassage $passage) use ($allTabsContext, $processedLookup): bool {
                        $tabId = $this->googleDocsAiAssignments->passageTabId($passage);
                        $tab = $tabId === null ? null : ($allTabsContext['tabs'][$tabId] ?? null);

                        return is_array($tab)
                            && $tab['status'] === 'VERIFIED'
                            && ! isset($processedLookup[$tabId]);
                    },
                )->values();
                $remainingTabIds = $remainingPassages
                    ->map(fn (WeeklyPlanSourcePassage $passage): ?string => $this->googleDocsAiAssignments->passageTabId($passage))
                    ->filter()
                    ->unique()
                    ->values();
                if ($remainingTabIds->isEmpty()) {
                    throw ValidationException::withMessages([
                        'source' => ['Không còn nhóm Google Docs chưa xử lý để phân tích.'],
                    ]);
                }
                $googleDocsContext = $this->googleDocsAiAssignments->previewContextForTabs(
                    $plan,
                    $remainingTabIds->all(),
                );
                $plan->setRelation('sourcePassages', $remainingPassages);
            }

            $knownTabIds = array_keys($googleDocsContext['tabs']);
            $hasInvalidPassage = $plan->sourcePassages->contains(
                fn (WeeklyPlanSourcePassage $passage): bool => ! in_array(
                    $this->googleDocsAiAssignments->passageTabId($passage),
                    $knownTabIds,
                    true,
                ),
            );
            if ($hasInvalidPassage) {
                throw new GoogleDocsSourceException(
                    'GOOGLE_DOCS_SOURCE_METADATA_INVALID',
                    409,
                    'Metadata tab của nguồn Google Docs không còn hợp lệ.',
                );
            }

            $eligiblePassages = $plan->sourcePassages->filter(
                function (WeeklyPlanSourcePassage $passage) use ($googleDocsContext): bool {
                    $tabId = $this->googleDocsAiAssignments->passageTabId($passage);
                    $tab = $tabId === null ? null : ($googleDocsContext['tabs'][$tabId] ?? null);

                    return is_array($tab) && $tab['status'] === 'VERIFIED';
                },
            )->values();
            if ($eligiblePassages->isEmpty()) {
                throw ValidationException::withMessages([
                    'source' => ['Không có đoạn nguồn nào thuộc tab Google Docs đã xác minh.'],
                ]);
            }
            $plan->setRelation('sourcePassages', $eligiblePassages);
        }

        $suggestions = $this->taskExtraction->preview(
            $plan,
            $actor,
            $googleDocsContext,
            $selectionFingerprint,
        );
        if ($googleDocsContext !== null) {
            $currentContext = $this->googleDocsAiAssignments->previewContextForTabs(
                $plan,
                array_keys($googleDocsContext['tabs']),
            );
            if (! hash_equals($googleDocsContext['fingerprint'], $currentContext['fingerprint'])) {
                throw new GoogleDocsSourceException(
                    'GOOGLE_DOCS_MAPPING_CHANGED',
                    409,
                    'Mapping tab Google Docs đã thay đổi trong khi AI phân tích. Vui lòng chạy lại.',
                );
            }
        }

        return $suggestions;
    }

    /** @param array<string, string> $mapping */
    public function reparse(User $actor, int $planId, array $mapping): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);
        if (! $plan->source_disk || ! $plan->source_path) {
            throw new ConflictHttpException('File nguồn không còn khả dụng. Vui lòng tải lại file để đọc lại dữ liệu.');
        }
        $sourceAvailability = $this->sourceAvailability($plan);
        if ($sourceAvailability === 'MISSING') {
            throw new ConflictHttpException('File nguồn không còn khả dụng. Vui lòng tải lại file để đọc lại dữ liệu.');
        }
        if ($sourceAvailability === 'UNAVAILABLE') {
            throw new ServiceUnavailableHttpException(null, 'Chưa thể truy cập kho lưu trữ tệp nguồn. Vui lòng thử lại sau.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'kps-weekly-reparse-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Không thể tạo tệp tạm.');
        }
        try {
            $stream = Storage::disk($plan->source_disk)->readStream($plan->source_path);
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            throw new ServiceUnavailableHttpException(null, 'Chưa thể truy cập kho lưu trữ tệp nguồn. Vui lòng thử lại sau.', $exception);
        }
        if (! is_resource($stream)) {
            @unlink($temporaryPath);
            throw new ConflictHttpException('File nguồn không còn khả dụng. Vui lòng tải lại file để đọc lại dữ liệu.');
        }
        $target = fopen($temporaryPath, 'wb');
        if ($target === false) {
            fclose($stream);
            @unlink($temporaryPath);
            throw new RuntimeException('Không thể tạo tệp tạm.');
        }
        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        try {
            $parsed = $this->parser->parse($temporaryPath, $plan->source_extension, $mapping);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['mapping' => [$exception->getMessage()]]);
        } finally {
            @unlink($temporaryPath);
        }

        DB::transaction(function () use ($actor, $mapping, $parsed, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            $this->invalidateSuggestions($locked, $actor, 'REPARSED');
            $locked->employeeReviews()->delete();
            $locked->entries()->delete();
            $locked->forceFill([
                'parse_status' => $parsed['needs_mapping']
                    ? WeeklyPlanParseStatus::NEEDS_MAPPING
                    : WeeklyPlanParseStatus::PARSED,
                'parse_metadata' => [
                    'headers' => $parsed['headers'],
                    'errors' => $parsed['errors'],
                    'row_count' => count($parsed['rows']),
                    'mapping' => $mapping,
                ],
            ])->save();
            $this->replaceParsedEntries($locked, $parsed['rows']);
            $this->record($locked, $actor, 'REPARSED', ['row_count' => count($parsed['rows'])]);
        });

        return $this->findVisible($actor, $plan->id);
    }

    /** @param array<string, mixed> $attributes */
    public function addEntry(User $actor, int $planId, array $attributes): WeeklyPlan
    {
        $plan = $this->entryEditablePlan($actor, $planId);
        DB::transaction(function () use ($actor, $attributes, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEntryEditingAllowed($locked);
            if ($this->isCorrectionStatus($locked->status)) {
                $this->reviewWorkflow->assertAssigneeCanBeCorrected(
                    $locked,
                    (int) $attributes['assignee_id'],
                );
            } elseif (in_array($locked->status, [
                WeeklyPlanStatus::ASSISTANT_REVIEW,
                WeeklyPlanStatus::OWNER_REVIEW,
            ], true)) {
                $this->reviewWorkflow->reopenAcceptedDecisionForEmptyPlan(
                    $locked,
                    (int) $attributes['assignee_id'],
                );
            }
            $entry = $this->storeEntry(
                $locked,
                $attributes,
                (int) $locked->entries()->max('sort_order') + 1,
            );
            $this->resetEmployeeReview($locked, $entry->assignee_id);
            $this->invalidateSuggestions($locked, $actor, 'ENTRY_ADDED');
            $this->record($locked, $actor, 'ENTRY_ADDED', ['entry_id' => $entry->id]);
        });

        return $this->findVisible($actor, $planId);
    }

    /** @param array<string, mixed> $attributes */
    public function updateEntry(User $actor, int $planId, int $entryId, array $attributes): WeeklyPlan
    {
        $plan = $this->scope->queryFor($actor)->findOrFail($planId);
        $canManageEntry = $this->scope->allows($actor, 'weekly_plans.manage_draft')
            && (in_array($plan->status, self::EDITABLE_STATUSES, true)
                || $this->isCorrectionStatus($plan->status));
        $canReviewEdit = $this->scope->allows($actor, 'weekly_plans.review')
            && in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true);

        if (! $canManageEntry && ! $canReviewEdit) {
            throw new AccessDeniedHttpException;
        }

        DB::transaction(function () use ($actor, $attributes, $entryId, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->workflowVersions->assertV2($locked);
            $isDraftEditor = $this->scope->allows($actor, 'weekly_plans.manage_draft')
                && in_array($locked->status, self::EDITABLE_STATUSES, true);
            $isCorrectionEditor = $this->scope->allows($actor, 'weekly_plans.manage_draft')
                && $this->isCorrectionStatus($locked->status);
            $isReviewer = $this->scope->allows($actor, 'weekly_plans.review')
                && in_array($locked->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true);

            if (! $isDraftEditor && ! $isCorrectionEditor && ! $isReviewer) {
                throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể xét duyệt.');
            }
            $entry = $locked->entries()->whereKey($entryId)->lockForUpdate()->firstOrFail();
            if ($isCorrectionEditor) {
                if ($entry->assignee_id === null
                    || (int) $attributes['assignee_id'] !== $entry->assignee_id) {
                    throw new ConflictHttpException('Không thể thay đổi nhân viên trong lần chỉnh sửa này.');
                }
                $this->reviewWorkflow->assertAssigneeCanBeCorrected(
                    $locked,
                    $entry->assignee_id,
                );
            }
            $hasItems = array_key_exists('items', $attributes);
            $items = $hasItems ? $attributes['items'] : [];
            unset($attributes['items']);
            $oldAssignee = $entry->assignee_id;
            if (! $isDraftEditor && ! $isCorrectionEditor) {
                $attributes['assignee_id'] = $entry->assignee_id;
                $attributes['start_date'] = $entry->start_date?->toDateString();
            }
            $prepared = $this->prepareEntry($locked, $attributes + ['source_row' => $entry->source_row]);
            $entry->forceFill($prepared)->save();
            if ($hasItems) {
                $parentSourceIds = $entry->sourcePassages()->pluck('weekly_plan_source_passages.id')->all();
                $validationErrors = [];
                $preparedItems = $this->prepareItems(
                    $items,
                    array_map('intval', $parentSourceIds),
                    false,
                    'items',
                    $validationErrors,
                );
                if ($validationErrors !== []) {
                    throw ValidationException::withMessages($validationErrors);
                }
                $this->replaceEntryItems($entry, $preparedItems);
            }
            $this->resetEmployeeReview($locked, $oldAssignee);
            $this->resetEmployeeReview($locked, $entry->assignee_id);
            $this->syncReviews($locked);
            $this->invalidateSuggestions($locked, $actor, 'ENTRY_UPDATED');
            $this->record($locked, $actor, 'ENTRY_UPDATED', ['entry_id' => $entry->id]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function deleteEntry(User $actor, int $planId, int $entryId): WeeklyPlan
    {
        $plan = $this->entryEditablePlan($actor, $planId);
        DB::transaction(function () use ($actor, $entryId, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEntryEditingAllowed($locked);
            $entry = $locked->entries()->whereKey($entryId)->lockForUpdate()->firstOrFail();
            if ($this->isCorrectionStatus($locked->status)) {
                if ($entry->assignee_id === null) {
                    throw new ConflictHttpException('Công việc không thuộc nhân viên được yêu cầu chỉnh sửa.');
                }
                $this->reviewWorkflow->assertAssigneeCanBeCorrected(
                    $locked,
                    $entry->assignee_id,
                );
            }
            $assigneeId = $entry->assignee_id;
            $entry->delete();
            $this->resetEmployeeReview($locked, $assigneeId);
            $this->syncReviews($locked);
            $this->invalidateSuggestions($locked, $actor, 'ENTRY_DELETED');
            $this->record($locked, $actor, 'ENTRY_DELETED', ['entry_id' => $entryId]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function resetDraft(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.manage_draft');

        DB::transaction(function () use ($actor, $planId): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.manage_draft')
                ->lockForUpdate()
                ->findOrFail($planId);
            $this->workflowVersions->assertV2($plan);

            if ($plan->status !== WeeklyPlanStatus::DRAFT) {
                throw new ConflictHttpException('Chỉ có thể đặt lại kế hoạch đang ở trạng thái nháp.');
            }

            $entryIds = $this->lockedEntryIdsWithoutTasks($plan);

            $counts = [
                'entry_count' => $entryIds->count(),
                'item_count' => DB::table('weekly_plan_entry_items')
                    ->whereIn('weekly_plan_entry_id', $entryIds)
                    ->count(),
                'suggestion_count' => $plan->suggestions()->count(),
                'employee_review_count' => $plan->employeeReviews()->count(),
                'entry_comment_count' => $plan->comments()
                    ->whereNotNull('weekly_plan_entry_id')
                    ->count(),
            ];

            $this->invalidateSuggestions($plan, $actor, 'PLAN_RESET');
            $plan->suggestions()->delete();
            $plan->employeeReviews()->delete();
            $plan->entries()->delete();
            $this->record($plan, $actor, 'PLAN_RESET', $counts);
        });

        return $this->findVisible($actor, $planId);
    }

    public function deleteDraft(User $actor, int $planId): void
    {
        $this->assertPermission($actor, 'weekly_plans.manage_draft');

        /** @var array{disk: string, path: string}|null $sourceFile */
        $sourceFile = DB::transaction(function () use ($actor, $planId): ?array {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.manage_draft')
                ->lockForUpdate()
                ->findOrFail($planId);
            $this->workflowVersions->assertV2($plan);

            if ($plan->status !== WeeklyPlanStatus::DRAFT) {
                throw new ConflictHttpException('Chỉ có thể xóa kế hoạch đang ở trạng thái nháp.');
            }

            $this->lockedEntryIdsWithoutTasks($plan);

            $sourceFile = $plan->source_type === WeeklyPlanSourceType::UPLOADED_FILE
                && is_string($plan->source_disk)
                && $plan->source_disk !== ''
                && is_string($plan->source_path)
                && $plan->source_path !== ''
                    ? ['disk' => $plan->source_disk, 'path' => $plan->source_path]
                    : null;

            WeeklyPlanGoogleDocsTabMappingEvent::query()
                ->where('weekly_plan_id', $plan->id)
                ->delete();
            WeeklyPlanGoogleDocsTabMapping::query()
                ->where('weekly_plan_id', $plan->id)
                ->delete();
            $plan->delete();

            return $sourceFile;
        });

        if ($sourceFile === null) {
            return;
        }

        try {
            $deleted = Storage::disk($sourceFile['disk'])->delete($sourceFile['path']);
            if (! $deleted) {
                $this->logSourceCleanupFailure($planId, $sourceFile, 'delete_returned_false');
            }
        } catch (\Throwable $exception) {
            $this->logSourceCleanupFailure($planId, $sourceFile, $exception::class);
        }
    }

    public function submit(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.submit');
        DB::transaction(function () use ($actor, $planId): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.submit')
                ->lockForUpdate()
                ->findOrFail($planId);
            $this->assertSubmittable($plan);
            $this->assertSourceProcessingComplete($plan);

            $isCorrectionResubmission = $this->isCorrectionStatus($plan->status);
            $readiness = $isCorrectionResubmission
                ? $this->planReadiness->evaluateCorrectionResubmission($plan)
                : $this->planReadiness->evaluate($plan);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(
                    $this->planReadiness->validationMessages(
                        $readiness,
                        $isCorrectionResubmission,
                    ),
                );
            }

            if ($isCorrectionResubmission) {
                $this->assertPermission($actor, 'weekly_plans.manage_draft');
                $this->reviewWorkflow->resubmitRequestedChanges($plan, $actor);
            } else {
                $this->reviewWorkflow->startAssistantReview($plan, $actor);
            }
        });

        return $this->findVisible($actor, $planId);
    }

    public function analyze(User $actor, int $planId): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);

        DB::transaction(function () use ($actor, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            $this->assertSourceProcessingComplete($locked);
            if (! $locked->entries()->exists()) {
                throw ValidationException::withMessages([
                    'plan' => ['Kế hoạch phải có ít nhất một công việc trước khi kiểm tra.'],
                ]);
            }

            $fingerprint = hash('sha256', $locked->entries()
                ->orderBy('sort_order')
                ->get([
                    'assignee_id', 'title', 'content', 'expected_result', 'start_date',
                    'due_date', 'priority', 'note', 'sort_order',
                ])->toJson());
            $latestAnalysis = $locked->events()
                ->where('event_type', 'ANALYZED')
                ->reorder()
                ->latest('id')
                ->first();
            $latestInvalidationId = $locked->events()
                ->where('event_type', 'SUGGESTIONS_INVALIDATED')
                ->max('id');
            $canReuse = $latestAnalysis !== null
                && ($latestInvalidationId === null || $latestAnalysis->id > $latestInvalidationId)
                && ($latestAnalysis->metadata['analyzer'] ?? null) === WeeklyPlanSuggestionGenerator::ANALYZER_VERSION
                && ($latestAnalysis->metadata['content_fingerprint'] ?? null) === $fingerprint;
            $suggestionCount = $canReuse
                ? $locked->suggestions()->count()
                : $this->suggestions->regenerate($locked);
            $this->record($locked, $actor, 'ANALYZED', [
                'analyzer' => WeeklyPlanSuggestionGenerator::ANALYZER_VERSION,
                'suggestion_count' => $suggestionCount,
                'content_fingerprint' => $fingerprint,
                'reused' => $canReuse,
            ]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function reviewEmployee(
        User $actor,
        int $planId,
        int $assigneeId,
        WeeklyPlanReviewStatus $status,
        ?string $comment,
    ): WeeklyPlan {
        $this->assertPermission($actor, 'weekly_plans.review');
        if ($status === WeeklyPlanReviewStatus::PENDING) {
            throw ValidationException::withMessages(['status' => ['Trạng thái xét duyệt không hợp lệ.']]);
        }

        $plan = $this->scope->queryFor($actor, 'weekly_plans.review')->findOrFail($planId);
        if (! in_array($plan->status, [
            WeeklyPlanStatus::OWNER_REVIEW,
            WeeklyPlanStatus::OWNER_CHANGES_REQUESTED,
        ], true)) {
            throw new ConflictHttpException('Kế hoạch không ở giai đoạn OWNER có thể xét duyệt.');
        }

        $this->reviewWorkflow->decide($actor, $planId, $assigneeId, $status);
        if (is_string($comment) && trim($comment) !== '') {
            $this->addComment($actor, $planId, trim($comment), null, $assigneeId);
        }

        return $this->findVisible($actor, $planId);
    }

    public function approve(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.approve');
        DB::transaction(function () use ($actor, $planId): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.approve')
                ->lockForUpdate()
                ->findOrFail($planId);
            $this->workflowVersions->assertV2($plan);
            if ($plan->status !== WeeklyPlanStatus::OWNER_REVIEW) {
                throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể phê duyệt.');
            }
            $readiness = $this->planReadiness->evaluate($plan);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(
                    $this->planReadiness->validationMessages($readiness),
                );
            }
            $this->reviewWorkflow->completeOwnerReviewForApproval($plan, $actor);

            $plan->forceFill([
                'status' => WeeklyPlanStatus::APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->record($plan, $actor, 'APPROVED');
        });

        return $this->findVisible($actor, $planId);
    }

    public function publish(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.publish');
        $plan = $this->scope->queryFor($actor, 'weekly_plans.publish')->findOrFail($planId);

        return $this->load($this->publisher->publish($actor, $plan), $actor);
    }

    public function approveAndPublish(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.approve');
        $this->assertPermission($actor, 'weekly_plans.publish');
        $plan = $this->scope->queryFor($actor, 'weekly_plans.approve')->findOrFail($planId);

        return $this->load($this->publisher->approveAndPublish($actor, $plan), $actor);
    }

    public function addComment(
        User $actor,
        int $planId,
        string $content,
        ?int $entryId,
        ?int $assigneeId,
    ): WeeklyPlan {
        $commentPermission = $this->commentPermissionFor($actor);
        if ($commentPermission === null) {
            throw new AccessDeniedHttpException;
        }

        $plan = $this->scope->queryFor($actor, $commentPermission)->findOrFail($planId);
        if ($entryId !== null && ! $plan->entries()->whereKey($entryId)->exists()) {
            throw ValidationException::withMessages(['entry_id' => ['Đầu việc không thuộc kế hoạch này.']]);
        }
        if ($assigneeId !== null && ! $plan->entries()->where('assignee_id', $assigneeId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => ['Nhân viên không thuộc kế hoạch này.']]);
        }

        DB::transaction(function () use ($actor, $assigneeId, $content, $entryId, $plan): void {
            $comment = WeeklyPlanComment::query()->create([
                'weekly_plan_id' => $plan->id,
                'weekly_plan_entry_id' => $entryId,
                'assignee_id' => $assigneeId,
                'author_id' => $actor->id,
                'content' => $content,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->record($plan, $actor, 'COMMENT_ADDED', [
                'comment_id' => $comment->id,
                'entry_id' => $entryId,
                'assignee_id' => $assigneeId,
            ]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function decideSuggestion(
        User $actor,
        int $planId,
        int $suggestionId,
        WeeklyPlanSuggestionStatus $status,
    ): WeeklyPlan {
        if ($status === WeeklyPlanSuggestionStatus::PENDING) {
            throw ValidationException::withMessages(['status' => ['Quyết định gợi ý không hợp lệ.']]);
        }
        $plan = $this->editablePlan($actor, $planId);
        $suggestion = $plan->suggestions()->whereKey($suggestionId)->firstOrFail();
        $suggestion->forceFill([
            'status' => $status,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
        ])->save();
        $this->record($plan, $actor, 'SUGGESTION_DECIDED', [
            'suggestion_id' => $suggestion->id,
            'status' => $status->value,
        ]);

        return $this->findVisible($actor, $planId);
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceParsedEntries(WeeklyPlan $plan, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $this->storeEntry($plan, $row, $index + 1);
        }
        $this->syncReviews($plan);
    }

    /** @param array<string, mixed> $attributes */
    private function storeEntry(WeeklyPlan $plan, array $attributes, int $sortOrder): WeeklyPlanEntry
    {
        $items = $attributes['items'] ?? [];
        unset($attributes['items']);
        $entry = $plan->entries()->create(
            $this->prepareEntry($plan, $attributes) + ['sort_order' => $sortOrder],
        );
        $validationErrors = [];
        $preparedItems = $this->prepareItems($items, [], false, 'items', $validationErrors);
        if ($validationErrors !== []) {
            throw ValidationException::withMessages($validationErrors);
        }
        $this->replaceEntryItems($entry, $preparedItems);

        return $entry;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $parentSourcePassageIds
     * @param  array<string, list<string>>  $validationErrors
     * @return list<array{title: string, content: string|null, source_payload: array<string, mixed>|null, source_passage_ids: list<int>}>
     */
    private function prepareItems(
        array $items,
        array $parentSourcePassageIds,
        bool $requireSourcePassages,
        string $errorPath,
        array &$validationErrors,
    ): array {
        $parentLookup = array_fill_keys($parentSourcePassageIds, true);
        $prepared = [];
        foreach ($items as $index => $item) {
            $sourcePassageIds = array_values(array_unique(array_map(
                'intval',
                (array) ($item['source_passage_ids'] ?? []),
            )));
            if ($requireSourcePassages && $sourcePassageIds === []) {
                $validationErrors["{$errorPath}.{$index}.source_passage_ids"] = [
                    'Đầu mục do AI tạo phải có ít nhất một đoạn nguồn.',
                ];
            }
            if (collect($sourcePassageIds)->contains(
                fn (int $passageId): bool => ! isset($parentLookup[$passageId]),
            )) {
                $validationErrors["{$errorPath}.{$index}.source_passage_ids"] = [
                    'Đoạn nguồn của đầu mục phải thuộc phạm vi nguồn của công việc cha.',
                ];
            }
            $prepared[] = [
                'title' => trim((string) ($item['title'] ?? '')),
                'content' => isset($item['content'])
                    ? trim((string) $item['content']) ?: null
                    : null,
                'source_payload' => $item === [] ? null : $item,
                'source_passage_ids' => $sourcePassageIds,
            ];
        }

        return $prepared;
    }

    /** @param list<array{title: string, content: string|null, source_payload: array<string, mixed>|null, source_passage_ids: list<int>}> $items */
    private function replaceEntryItems(WeeklyPlanEntry $entry, array $items): void
    {
        $entry->items()->delete();
        foreach ($items as $index => $item) {
            $sourcePassageIds = $item['source_passage_ids'];
            unset($item['source_passage_ids']);
            $entryItem = $entry->items()->create($item + ['sort_order' => $index + 1]);
            if ($sourcePassageIds !== []) {
                $entryItem->sourcePassages()->attach($sourcePassageIds);
            }
        }
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function prepareEntry(WeeklyPlan $plan, array $attributes): array
    {
        $membership = null;
        if (! empty($attributes['assignee_id'])) {
            $membership = $this->validator->activeMembership($plan, (int) $attributes['assignee_id']);
        } else {
            $membership = $this->validator->resolveExactEmail(
                $plan,
                isset($attributes['employee_identifier']) ? (string) $attributes['employee_identifier'] : null,
            );
        }

        if ($membership instanceof OrganizationMembership) {
            $membership->loadMissing(['user:id,name,email', 'department:id,name']);
            $attributes['assignee_id'] = $membership->user_id;
            $attributes['employee_identifier'] = $membership->user->email;
            $attributes['employee_name'] = $membership->user->name;
            $attributes['department_name'] = $membership->department?->name;
        }

        $attributes['priority'] = strtoupper((string) ($attributes['priority'] ?? 'MEDIUM'));
        $errors = $this->validator->errors($plan, $attributes);

        return [
            'assignee_id' => $attributes['assignee_id'] ?? null,
            'source_row' => $attributes['source_row'] ?? null,
            'employee_identifier' => $attributes['employee_identifier'] ?? null,
            'employee_name' => $attributes['employee_name'] ?? null,
            'department_name' => $attributes['department_name'] ?? null,
            'title' => trim((string) ($attributes['title'] ?? '')),
            'content' => isset($attributes['content']) ? trim((string) $attributes['content']) ?: null : null,
            'expected_result' => isset($attributes['expected_result'])
                ? trim((string) $attributes['expected_result']) ?: null
                : null,
            'start_date' => $this->storableDate($attributes['start_date'] ?? null),
            'due_date' => $this->storableDate($attributes['due_date'] ?? null),
            'priority' => $attributes['priority'],
            'note' => isset($attributes['note']) ? trim((string) $attributes['note']) ?: null : null,
            'source_payload' => $attributes,
            'validation_status' => $errors === [] ? 'VALID' : 'INVALID',
            'validation_errors' => $errors,
        ];
    }

    private function storableDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function editablePlan(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.manage_draft');
        $plan = $this->scope->queryFor($actor, 'weekly_plans.manage_draft')->findOrFail($planId);
        $this->assertEditable($plan);

        return $plan;
    }

    private function entryEditablePlan(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.manage_draft');
        $plan = $this->scope->queryFor($actor, 'weekly_plans.manage_draft')->findOrFail($planId);
        $this->assertEntryEditingAllowed($plan);

        return $plan;
    }

    private function assertEditable(WeeklyPlan $plan): void
    {
        $this->workflowVersions->assertV2($plan);

        if (! in_array($plan->status, self::EDITABLE_STATUSES, true)) {
            throw new ConflictHttpException('Kế hoạch không còn ở trạng thái có thể chỉnh sửa.');
        }
    }

    private function assertEntryEditingAllowed(WeeklyPlan $plan): void
    {
        $this->workflowVersions->assertV2($plan);

        if (in_array($plan->status, self::EDITABLE_STATUSES, true)
            || $this->isCorrectionStatus($plan->status)) {
            return;
        }

        $emptyReviewRecovery = in_array($plan->status, [
            WeeklyPlanStatus::ASSISTANT_REVIEW,
            WeeklyPlanStatus::OWNER_REVIEW,
        ], true) && ! $plan->entries()->exists();
        if ($emptyReviewRecovery) {
            return;
        }

        throw new ConflictHttpException('Kế hoạch không còn ở trạng thái có thể chỉnh sửa.');
    }

    private function assertSubmittable(WeeklyPlan $plan): void
    {
        $this->workflowVersions->assertV2($plan);

        if (! in_array($plan->status, self::EDITABLE_STATUSES, true)
            && ! $this->isCorrectionStatus($plan->status)) {
            throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể gửi xét duyệt.');
        }
    }

    private function isCorrectionStatus(WeeklyPlanStatus $status): bool
    {
        return in_array($status, self::CORRECTION_STATUSES, true);
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $this->scope->allows($actor, $permission)) {
            throw new AccessDeniedHttpException;
        }
    }

    private function membershipOrFail(User $actor): OrganizationMembership
    {
        return $this->scope->membership($actor) ?? throw new AccessDeniedHttpException;
    }

    private function syncReviews(WeeklyPlan $plan): void
    {
        // WeeklyPlan::entries() has a presentation order. Do not inherit that
        // ORDER BY in this DISTINCT projection: MySQL/TiDB rejects ordering by
        // sort_order when only assignee_id is selected.
        $assigneeIds = WeeklyPlanEntry::query()
            ->where('weekly_plan_id', $plan->id)
            ->whereNotNull('assignee_id')
            ->distinct()
            ->pluck('assignee_id');
        $plan->employeeReviews()->whereNotIn('assignee_id', $assigneeIds)->delete();

        foreach ($assigneeIds as $assigneeId) {
            $plan->employeeReviews()->firstOrCreate(
                ['assignee_id' => $assigneeId],
                ['status' => WeeklyPlanReviewStatus::PENDING],
            );
        }
    }

    private function isDuplicatePlanException(QueryException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'weekly_plans_org_week_unique')
            || str_contains($message, 'weekly_plans.organization_id, weekly_plans.week_start');
    }

    /** @return list<array{source_key: string, sequence: int, kind: string, text: string, metadata: array<string, mixed>}> */
    private function readSourcePassages(WeeklyPlan $plan): array
    {
        if ($plan->source_extension !== 'docx') {
            throw ValidationException::withMessages([
                'source' => ['Chỉ hỗ trợ đọc nội dung văn bản từ tệp DOCX.'],
            ]);
        }
        if (! is_string($plan->source_disk) || $plan->source_disk === ''
            || ! is_string($plan->source_path) || $plan->source_path === '') {
            throw new ConflictHttpException('File nguồn không còn khả dụng. Vui lòng tải lại file để tiếp tục.');
        }
        $sourceAvailability = $this->sourceAvailability($plan);
        if ($sourceAvailability === 'MISSING') {
            throw new ConflictHttpException('File nguồn không còn khả dụng. Vui lòng tải lại file để tiếp tục.');
        }
        if ($sourceAvailability === 'UNAVAILABLE') {
            throw new ServiceUnavailableHttpException(null, 'Chưa thể truy cập kho lưu trữ tệp nguồn. Vui lòng thử lại sau.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'kps-weekly-source-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Không thể tạo tệp tạm.');
        }

        try {
            $stream = Storage::disk($plan->source_disk)->readStream($plan->source_path);
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            throw new ServiceUnavailableHttpException(null, 'Chưa thể truy cập kho lưu trữ tệp nguồn. Vui lòng thử lại sau.', $exception);
        }
        $target = fopen($temporaryPath, 'wb');
        if (! is_resource($stream) || $target === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($temporaryPath);
            throw new ConflictHttpException('File nguồn không còn khả dụng. Vui lòng tải lại file để tiếp tục.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        try {
            return $this->parser->extractDocumentText($temporaryPath, $plan->source_extension);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['source' => [$exception->getMessage()]]);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /** @param list<array{source_key: string, sequence: int, kind: string, text: string, metadata: array<string, mixed>}> $passages */
    private function persistSourcePassages(WeeklyPlan $plan, array $passages): void
    {
        foreach ($passages as $passage) {
            $plan->sourcePassages()->create([
                'source_sha256' => $plan->source_sha256,
                'source_key' => $passage['source_key'],
                'sequence' => $passage['sequence'],
                'kind' => $passage['kind'],
                'text' => $passage['text'],
                'metadata' => $passage['metadata'],
                'content_sha256' => hash('sha256', $passage['text']),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function manualSourceGroup(
        WeeklyPlan $plan,
        string $title,
        WeeklyPlanSourcePassage $start,
        WeeklyPlanSourcePassage $end,
    ): array {
        $identity = implode('|', [
            (string) $plan->id,
            (string) $plan->source_sha256,
            (string) $start->sequence,
            (string) $end->sequence,
        ]);

        return [
            'version' => 1,
            'key' => 'manual:'.hash('sha256', $identity),
            'title' => $title,
            'origin' => 'MANUAL',
            'order' => (int) $start->sequence,
            'identity_verified' => false,
            'evidence' => [
                'type' => 'PASSAGE_RANGE',
                'start_sequence' => (int) $start->sequence,
                'end_sequence' => (int) $end->sequence,
                'start_source_key' => $start->source_key,
                'end_source_key' => $end->source_key,
            ],
        ];
    }

    private function isManualSourceGroup(mixed $group): bool
    {
        return is_array($group)
            && ($group['origin'] ?? null) === 'MANUAL'
            && is_string($group['key'] ?? null)
            && $group['key'] !== ''
            && is_string($group['title'] ?? null)
            && $group['title'] !== '';
    }

    private function sourceGroupKey(?WeeklyPlanSourcePassage $passage): ?string
    {
        $metadata = $passage?->metadata;
        $group = is_array($metadata) ? ($metadata['source_group'] ?? null) : null;
        $key = is_array($group) ? ($group['key'] ?? null) : null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function sourceAvailability(WeeklyPlan $plan): string
    {
        if (! is_string($plan->source_disk) || $plan->source_disk === ''
            || ! is_string($plan->source_path) || $plan->source_path === '') {
            return 'MISSING';
        }

        try {
            return Storage::disk($plan->source_disk)->exists($plan->source_path)
                ? 'AVAILABLE'
                : 'MISSING';
        } catch (\Throwable) {
            return 'UNAVAILABLE';
        }
    }

    private function sourceWasExtracted(WeeklyPlan $plan): bool
    {
        return $plan->events()
            ->where('event_type', 'SOURCE_TEXT_EXTRACTED')
            ->get()
            ->contains(fn (WeeklyPlanEvent $event): bool => ($event->metadata['source_sha256'] ?? null) === $plan->source_sha256);
    }

    private function resetEmployeeReview(WeeklyPlan $plan, ?int $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }
        $plan->employeeReviews()->where('assignee_id', $assigneeId)->update([
            'status' => WeeklyPlanReviewStatus::PENDING->value,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    private function invalidateSuggestions(WeeklyPlan $plan, User $actor, string $reason): void
    {
        $count = $plan->suggestions()->count();
        $latestAnalysisId = $plan->events()->where('event_type', 'ANALYZED')->max('id');
        $latestInvalidationId = $plan->events()->where('event_type', 'SUGGESTIONS_INVALIDATED')->max('id');
        if ($latestAnalysisId === null
            || ($latestInvalidationId !== null && $latestInvalidationId > $latestAnalysisId)) {
            return;
        }

        if ($count > 0) {
            $plan->suggestions()->delete();
        }
        $this->record($plan, $actor, 'SUGGESTIONS_INVALIDATED', [
            'reason' => $reason,
            'suggestion_count' => $count,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function record(WeeklyPlan $plan, User $actor, string $event, array $metadata = []): void
    {
        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => $event,
            'metadata' => $metadata ?: null,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function assertFileType(string $extension, string $mime): void
    {
        $allowed = [
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ],
        ];

        if (! isset($allowed[$extension]) || ! in_array($mime, $allowed[$extension], true)) {
            throw ValidationException::withMessages([
                'file' => ['Chỉ chấp nhận tệp .xlsx hoặc .docx OOXML hợp lệ.'],
            ]);
        }
    }

    private function load(WeeklyPlan $plan, User $viewer): WeeklyPlan
    {
        $plan->load([
            'creator:id,name',
            'entries.assignee:id,name,email',
            'entries.sourcePassages',
            'entries.items.sourcePassages',
            'employeeReviews.assignee:id,name',
            'reviewRounds.decisions.assignee:id,name',
            'reviewRounds.decisions.reviewer:id,name',
            'reviewRounds.starter:id,name',
            'reviewRounds.completer:id,name',
            'comments.author:id,name',
            'suggestions',
            'events.actor:id,name',
            'googleDocsSource',
            'sourcePassages' => fn ($query) => $query
                ->where('source_sha256', $plan->source_sha256)
                ->orderBy('sequence'),
        ])->loadCount('entries');

        $v2Workflow = $this->workflowVersions->isV2($plan);
        $isGoogleDocs = $plan->source_type === WeeklyPlanSourceType::GOOGLE_DOCS;
        $sourceAvailability = $isGoogleDocs ? 'NOT_APPLICABLE' : $this->sourceAvailability($plan);
        $plan->setAttribute('source_file_availability', $sourceAvailability);
        $sourceStatus = $isGoogleDocs
            ? ($plan->sourcePassages->isEmpty() ? 'EMPTY' : 'EXTRACTED')
            : ($plan->source_extension !== 'docx'
                ? 'UNSUPPORTED'
                : ($this->sourceWasExtracted($plan)
                    ? ($plan->sourcePassages->isEmpty() ? 'EMPTY' : 'EXTRACTED')
                    : 'NOT_EXTRACTED'));
        $aiStatus = $this->taskExtraction->status();
        $googleDocsAiReady = ! $isGoogleDocs
            || ($sourceStatus === 'EXTRACTED' && $this->googleDocsAiAssignments->isReady($plan));
        $googleDocsProcessing = $isGoogleDocs
            ? $this->googleDocsAiAssignments->processingState($plan)
            : null;
        $sourceProcessingComplete = ! $isGoogleDocs
            || ($googleDocsProcessing['remaining_tab_ids'] ?? []) === [];
        $sourceWorkAvailable = ! $isGoogleDocs || ! $sourceProcessingComplete;
        $plan->setAttribute('google_docs_processing', $googleDocsProcessing);
        $plan->setAttribute('source_document', [
            'status' => $sourceStatus,
            'ai' => $aiStatus,
            'limits' => [
                'max_passages_per_entry' => (int) config('weekly_plans.max_source_passages_per_entry', 20),
                'max_entries_per_request' => (int) config('weekly_plans.max_source_entries_per_request', 20),
            ],
        ]);

        $submissionReadiness = $this->planReadiness->evaluate($plan);
        $assistantRound = $plan->reviewRounds
            ->where('stage', WeeklyPlanReviewStage::ASSISTANT)
            ->sortByDesc('round_number')
            ->first();
        $ownerRound = $plan->reviewRounds
            ->where('stage', WeeklyPlanReviewStage::OWNER)
            ->sortByDesc('round_number')
            ->first();
        $allAssistantDecisionsAccepted = $assistantRound instanceof WeeklyPlanReviewRound
            && $assistantRound->decisions->isNotEmpty()
            && $assistantRound->decisions->every(
                fn ($decision): bool => $decision->status === WeeklyPlanReviewStatus::ACCEPTED,
            );
        $allOwnerDecisionsAccepted = $ownerRound instanceof WeeklyPlanReviewRound
            && $ownerRound->decisions->isNotEmpty()
            && $ownerRound->decisions->every(
                fn ($decision): bool => $decision->status === WeeklyPlanReviewStatus::ACCEPTED,
            );
        $assistantReviewStatus = $plan->status === WeeklyPlanStatus::ASSISTANT_REVIEW;
        $ownerReviewStatus = $plan->status === WeeklyPlanStatus::OWNER_REVIEW;
        $hasAssistantReviewPermission = $this->scope->allows(
            $viewer,
            'weekly_plans.assistant_review',
        );
        $hasOwnerReviewPermission = $this->scope->allows($viewer, 'weekly_plans.review');
        $canAssistantReview = $v2Workflow
            && $assistantReviewStatus
            && $hasAssistantReviewPermission;
        $canOwnerReview = $v2Workflow && $ownerReviewStatus && $hasOwnerReviewPermission;
        $currentReviewRound = match (true) {
            $assistantReviewStatus => $assistantRound,
            $ownerReviewStatus => $ownerRound,
            default => null,
        };
        $viewerCanReviewCurrentRound = $assistantReviewStatus
            ? $canAssistantReview
            : ($ownerReviewStatus && $canOwnerReview);
        if ($currentReviewRound instanceof WeeklyPlanReviewRound) {
            $currentReviewRound->decisions->each(
                function (WeeklyPlanReviewDecision $decision) use (
                    $currentReviewRound,
                    $plan,
                    $viewerCanReviewCurrentRound,
                ): void {
                    $decision->setAttribute('actions', [
                        'can_request_changes' => $this->reviewWorkflow->canRequestChangesForDecision(
                            $plan,
                            $currentReviewRound,
                            $decision,
                            $viewerCanReviewCurrentRound,
                        ),
                    ]);
                },
            );
        }
        $correctionRound = match ($plan->status) {
            WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED => $assistantRound,
            WeeklyPlanStatus::OWNER_CHANGES_REQUESTED => $ownerRound,
            default => null,
        };
        $correctionStateValid = $correctionRound instanceof WeeklyPlanReviewRound
            && $correctionRound->status === WeeklyPlanReviewRoundStatus::CHANGES_REQUESTED
            && $correctionRound->decisions->contains(
                fn ($decision): bool => $decision->status === WeeklyPlanReviewStatus::CHANGES_REQUESTED,
            )
            && $plan->entries->pluck('assignee_id')->filter()->unique()->diff(
                $correctionRound->decisions->pluck('assignee_id'),
            )->isEmpty();
        $emptyReviewRecoveryRound = match ($plan->status) {
            WeeklyPlanStatus::ASSISTANT_REVIEW => $assistantRound,
            WeeklyPlanStatus::OWNER_REVIEW => $ownerRound,
            default => null,
        };
        $emptyReviewRecovery = $plan->entries->isEmpty()
            && $emptyReviewRecoveryRound instanceof WeeklyPlanReviewRound
            && $emptyReviewRecoveryRound->status === WeeklyPlanReviewRoundStatus::OPEN
            && $emptyReviewRecoveryRound->decisions->isNotEmpty()
            && $emptyReviewRecoveryRound->decisions->every(
                fn ($decision): bool => $decision->status === WeeklyPlanReviewStatus::ACCEPTED,
            );
        $canCorrectRequestedChanges = $v2Workflow
            && ($correctionStateValid || $emptyReviewRecovery)
            && $this->scope->allows($viewer, 'weekly_plans.manage_draft');
        $canResubmitChanges = $v2Workflow
            && $correctionStateValid
            && $this->planReadiness->evaluateCorrectionResubmission($plan)['ready']
            && $sourceProcessingComplete
            && $this->scope->allows($viewer, 'weekly_plans.manage_draft')
            && $this->scope->allows($viewer, 'weekly_plans.submit');

        $actions = [
            'can_edit' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_correct_requested_changes' => $canCorrectRequestedChanges,
            'can_resubmit_changes' => $canResubmitChanges,
            'can_reset' => $plan->status === WeeklyPlanStatus::DRAFT
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_delete' => $plan->status === WeeklyPlanStatus::DRAFT
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_analyze' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $plan->entries->isNotEmpty()
                && $sourceProcessingComplete
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_submit' => (in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $submissionReadiness['ready']
                && $sourceProcessingComplete
                && $this->scope->allows($viewer, 'weekly_plans.submit'))
                || $canResubmitChanges,
            'can_assistant_review' => $canAssistantReview,
            'can_finalize_assistant_review' => $assistantReviewStatus
                && $allAssistantDecisionsAccepted
                && $submissionReadiness['ready']
                && $this->scope->allows($viewer, 'weekly_plans.assistant_review'),
            'can_owner_review' => $canOwnerReview,
            'can_open_review_workspace' => $this->canOpenReviewWorkspace(
                $plan->status,
                $hasAssistantReviewPermission,
                $hasOwnerReviewPermission,
            ),
            'can_review' => $canOwnerReview,
            'can_review_edit' => in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)
                && $this->scope->allows($viewer, 'weekly_plans.review'),
            'can_approve' => $plan->status === WeeklyPlanStatus::OWNER_REVIEW
                && $submissionReadiness['ready']
                && $allOwnerDecisionsAccepted
                && $this->scope->allows($viewer, 'weekly_plans.approve'),
            'can_publish' => $plan->status === WeeklyPlanStatus::APPROVED
                && $this->scope->allows($viewer, 'weekly_plans.publish'),
            'can_approve_and_publish' => $plan->status === WeeklyPlanStatus::APPROVED
                && $this->scope->allows($viewer, 'weekly_plans.approve')
                && $this->scope->allows($viewer, 'weekly_plans.publish'),
            'can_comment' => $this->commentPermissionFor($viewer) !== null,
            'can_extract_source' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && ! $isGoogleDocs
                && $sourceStatus === 'NOT_EXTRACTED'
                && $sourceAvailability === 'AVAILABLE'
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_replace_source' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && ! $isGoogleDocs
                && $sourceAvailability === 'MISSING'
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_apply_source' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $sourceStatus === 'EXTRACTED'
                && $sourceWorkAvailable
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_group_source' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $sourceStatus === 'EXTRACTED'
                && $sourceWorkAvailable
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_ai_extract_source' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $sourceStatus === 'EXTRACTED'
                && $aiStatus['configured']
                && $googleDocsAiReady
                && $sourceWorkAvailable
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_ai_extract_selected_source' => $isGoogleDocs
                && in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $sourceStatus === 'EXTRACTED'
                && $aiStatus['configured']
                && $sourceWorkAvailable
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_manage_google_docs_mappings' => $isGoogleDocs
                && $plan->status === WeeklyPlanStatus::DRAFT
                && $sourceWorkAvailable
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
        ];

        if (! $v2Workflow) {
            foreach (array_keys($actions) as $action) {
                if ($action !== 'can_comment') {
                    $actions[$action] = false;
                }
            }
        }

        $plan->setAttribute('actions', $actions);

        return $plan;
    }

    private function canOpenReviewWorkspace(
        WeeklyPlanStatus $status,
        bool $hasAssistantReviewPermission,
        bool $hasOwnerReviewPermission,
    ): bool {
        return match ($status) {
            WeeklyPlanStatus::ASSISTANT_REVIEW,
            WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED => $hasAssistantReviewPermission,
            WeeklyPlanStatus::OWNER_REVIEW,
            WeeklyPlanStatus::OWNER_CHANGES_REQUESTED => $hasOwnerReviewPermission,
            default => false,
        };
    }

    private function assertSourceProcessingComplete(WeeklyPlan $plan): void
    {
        if ($plan->source_type !== WeeklyPlanSourceType::GOOGLE_DOCS) {
            return;
        }

        $processing = $this->googleDocsAiAssignments->processingState($plan);
        if (($processing['remaining_tab_ids'] ?? []) !== []) {
            throw ValidationException::withMessages([
                'plan' => [
                    'Vẫn còn nhóm Google Docs chưa được xử lý. Vui lòng hoàn tất bước Phân tích công việc trước khi tiếp tục.',
                ],
            ]);
        }
    }

    /** @return Collection<int, int> */
    private function lockedEntryIdsWithoutTasks(WeeklyPlan $plan): Collection
    {
        $entryIds = WeeklyPlanEntry::query()
            ->where('weekly_plan_id', $plan->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($entryIds->isNotEmpty()
            && DB::table('tasks')->whereIn('weekly_plan_entry_id', $entryIds)->exists()) {
            throw new ConflictHttpException(
                'Kế hoạch đã có Task được phát hành; hệ thống từ chối thay đổi để tránh mất dữ liệu.',
            );
        }

        return $entryIds;
    }

    /** @param array{disk: string, path: string} $sourceFile */
    private function logSourceCleanupFailure(int $planId, array $sourceFile, string $reason): void
    {
        Log::warning('weekly_plan_source_cleanup_failed', [
            'weekly_plan_id' => $planId,
            'disk' => $sourceFile['disk'],
            'path_sha256' => hash('sha256', $sourceFile['path']),
            'reason' => $reason,
        ]);
    }

    private function commentPermissionFor(User $viewer): ?string
    {
        foreach (['weekly_plans.manage_draft', 'weekly_plans.assistant_review', 'weekly_plans.review'] as $permission) {
            if ($this->scope->allows($viewer, $permission)) {
                return $permission;
            }
        }

        return null;
    }
}
