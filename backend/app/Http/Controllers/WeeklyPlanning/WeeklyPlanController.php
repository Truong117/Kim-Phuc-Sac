<?php

namespace App\Http\Controllers\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanSuggestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyPlanning\ApplyWeeklyPlanSourceEntriesRequest;
use App\Http\Requests\WeeklyPlanning\AssignWeeklyPlanSourceGroupRequest;
use App\Http\Requests\WeeklyPlanning\ConfirmWeeklyPlanV3AssistantBatchRequest;
use App\Http\Requests\WeeklyPlanning\ConfirmWeeklyPlanV3CorrectionBatchRequest;
use App\Http\Requests\WeeklyPlanning\ConfirmWeeklyPlanV3OwnerBatchRequest;
use App\Http\Requests\WeeklyPlanning\DecideWeeklyPlanReviewRequest;
use App\Http\Requests\WeeklyPlanning\DecideWeeklyPlanSuggestionRequest;
use App\Http\Requests\WeeklyPlanning\DeleteWeeklyPlanV3CorrectionEntryRequest;
use App\Http\Requests\WeeklyPlanning\ExtractWeeklyPlanSourceWithAiRequest;
use App\Http\Requests\WeeklyPlanning\ImportWeeklyPlanRequest;
use App\Http\Requests\WeeklyPlanning\ReparseWeeklyPlanRequest;
use App\Http\Requests\WeeklyPlanning\ReplaceWeeklyPlanSourceRequest;
use App\Http\Requests\WeeklyPlanning\ReviewWeeklyPlanEmployeeRequest;
use App\Http\Requests\WeeklyPlanning\StoreWeeklyPlanCommentRequest;
use App\Http\Requests\WeeklyPlanning\WeeklyPlanEntryRequest;
use App\Http\Requests\WeeklyPlanning\WeeklyPlanV3CorrectionEntryRequest;
use App\Http\Resources\WeeklyPlanResource;
use App\Models\User;
use App\Services\WeeklyPlanning\WeeklyPlanReviewWorkflowService;
use App\Services\WeeklyPlanning\WeeklyPlanService;
use App\Services\WeeklyPlanning\WeeklyPlanTemplateService;
use App\Services\WeeklyPlanning\WeeklyPlanV3AssistantBatchService;
use App\Services\WeeklyPlanning\WeeklyPlanV3CorrectionService;
use App\Services\WeeklyPlanning\WeeklyPlanV3OwnerBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WeeklyPlanController extends Controller
{
    public function __construct(
        private readonly WeeklyPlanService $plans,
        private readonly WeeklyPlanReviewWorkflowService $reviewWorkflow,
        private readonly WeeklyPlanV3AssistantBatchService $v3AssistantBatches,
        private readonly WeeklyPlanV3CorrectionService $v3Corrections,
        private readonly WeeklyPlanV3OwnerBatchService $v3OwnerBatches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $paginator = $this->plans->paginate(
            $user,
            min(100, max(1, $request->integer('per_page', 20))),
        );

        return response()->json([
            'data' => WeeklyPlanResource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function references(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->plans->references($user)]);
    }

    public function template(WeeklyPlanTemplateService $templates): BinaryFileResponse
    {
        return response()
            ->download(
                $templates->create(),
                'KPS_Ke_hoach_cong_viec_tuan.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            )
            ->deleteFileAfterSend(true);
    }

    public function import(ImportWeeklyPlanRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('file');

        return (new WeeklyPlanResource(
            $this->plans->import($user, $file, $request->mapping()),
        ))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->findVisible($user, $id));
    }

    public function destroy(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->plans->deleteDraft($user, $id);

        return response()->noContent();
    }

    public function reset(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->resetDraft($user, $id));
    }

    public function reparse(ReparseWeeklyPlanRequest $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->reparse($user, $id, $request->mapping()));
    }

    public function extractSource(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->extractSourceText($user, $id));
    }

    public function replaceSource(ReplaceWeeklyPlanSourceRequest $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource(
            $this->plans->replaceMissingSource($user, $id, $request->file('file')),
        );
    }

    public function applySourceEntries(
        ApplyWeeklyPlanSourceEntriesRequest $request,
        int $id,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->applySourceEntries(
            $user,
            $id,
            (string) $request->validated('request_id'),
            $request->entries(),
        ));
    }

    public function assignSourceGroup(
        AssignWeeklyPlanSourceGroupRequest $request,
        int $id,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->assignSourceGroup(
            $user,
            $id,
            $request->passageIds(),
            (string) $request->validated('group_title'),
        ));
    }

    public function extractSourceWithAi(
        ExtractWeeklyPlanSourceWithAiRequest $request,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'suggestions' => $this->plans->previewAiSourceEntries(
                    $user,
                    $id,
                    $request->sourcePassageIds(),
                ),
            ],
        ]);
    }

    public function storeEntry(WeeklyPlanEntryRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new WeeklyPlanResource(
            $this->plans->addEntry($user, $id, $request->validated()),
        ))->response()->setStatusCode(201);
    }

    public function updateEntry(
        WeeklyPlanEntryRequest $request,
        int $id,
        int $entryId,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource(
            $this->plans->updateEntry($user, $id, $entryId, $request->validated()),
        );
    }

    public function destroyEntry(Request $request, int $id, int $entryId): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->deleteEntry($user, $id, $entryId));
    }

    public function submit(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->submit($user, $id));
    }

    public function analyze(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->analyze($user, $id));
    }

    public function reviewEmployee(
        ReviewWeeklyPlanEmployeeRequest $request,
        int $id,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        return new WeeklyPlanResource($this->plans->reviewEmployee(
            $user,
            $id,
            (int) $validated['assignee_id'],
            WeeklyPlanReviewStatus::from($validated['status']),
            $validated['comment'] ?? null,
        ));
    }

    public function decideReview(
        DecideWeeklyPlanReviewRequest $request,
        int $id,
        int $assigneeId,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();
        $this->reviewWorkflow->decide(
            $user,
            $id,
            $assigneeId,
            WeeklyPlanReviewStatus::from($request->validated('decision')),
        );

        return new WeeklyPlanResource($this->plans->findVisible($user, $id));
    }

    public function confirmV3AssistantBatch(
        ConfirmWeeklyPlanV3AssistantBatchRequest $request,
        int $plan,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $result = $this->v3AssistantBatches->confirm(
            $user,
            $plan,
            $request->requestId(),
            $request->decisions(),
        );
        $detail = $this->plans->findVisible($user, $plan);

        return response()->json([
            'data' => [
                'plan' => (new WeeklyPlanResource($detail))->resolve(),
                'batch' => $result['batch'],
                'results' => $result['results'],
            ],
        ], $result['replayed'] ? 200 : 201);
    }

    public function storeV3CorrectionEntry(
        WeeklyPlanV3CorrectionEntryRequest $request,
        int $plan,
        int $assignee,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $this->v3Corrections->addEntry(
            $user,
            $plan,
            $assignee,
            $request->expectedContentVersion(),
            $request->entryAttributes(),
        );

        return (new WeeklyPlanResource($this->plans->findVisible($user, $plan)))
            ->response()->setStatusCode(201);
    }

    public function confirmV3OwnerBatch(
        ConfirmWeeklyPlanV3OwnerBatchRequest $request,
        int $plan,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $result = $this->v3OwnerBatches->confirm(
            $user,
            $plan,
            $request->requestId(),
            $request->decisions(),
        );
        $detail = $this->plans->findVisible($user, $plan);

        return response()->json([
            'data' => [
                'plan' => (new WeeklyPlanResource($detail))->resolve(),
                'batch' => $result['batch'],
                'results' => $result['results'],
            ],
        ], $result['replayed'] ? 200 : 201);
    }

    public function updateV3CorrectionEntry(
        WeeklyPlanV3CorrectionEntryRequest $request,
        int $plan,
        int $assignee,
        int $entry,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();
        $this->v3Corrections->updateEntry(
            $user,
            $plan,
            $assignee,
            $entry,
            $request->expectedContentVersion(),
            $request->entryAttributes(),
        );

        return new WeeklyPlanResource($this->plans->findVisible($user, $plan));
    }

    public function destroyV3CorrectionEntry(
        DeleteWeeklyPlanV3CorrectionEntryRequest $request,
        int $plan,
        int $assignee,
        int $entry,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();
        $this->v3Corrections->deleteEntry(
            $user,
            $plan,
            $assignee,
            $entry,
            $request->expectedContentVersion(),
        );

        return new WeeklyPlanResource($this->plans->findVisible($user, $plan));
    }

    public function confirmV3CorrectionBatch(
        ConfirmWeeklyPlanV3CorrectionBatchRequest $request,
        int $plan,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $result = $this->v3Corrections->confirm(
            $user,
            $plan,
            $request->requestId(),
            $request->employees(),
        );
        $detail = $this->plans->findVisible($user, $plan);

        return response()->json([
            'data' => [
                'plan' => (new WeeklyPlanResource($detail))->resolve(),
                'batch' => $result['batch'],
                'results' => $result['results'],
            ],
        ], $result['replayed'] ? 200 : 201);
    }

    public function finalizeAssistantReview(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();
        $this->reviewWorkflow->finalizeAssistantReview($user, $id);

        return new WeeklyPlanResource($this->plans->findVisible($user, $id));
    }

    public function approve(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->approve($user, $id));
    }

    public function publish(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->publish($user, $id));
    }

    public function approveAndPublish(Request $request, int $id): WeeklyPlanResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->approveAndPublish($user, $id));
    }

    public function comment(
        StoreWeeklyPlanCommentRequest $request,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        return (new WeeklyPlanResource($this->plans->addComment(
            $user,
            $id,
            $validated['content'],
            $validated['entry_id'] ?? null,
            $validated['assignee_id'] ?? null,
        )))->response()->setStatusCode(201);
    }

    public function decideSuggestion(
        DecideWeeklyPlanSuggestionRequest $request,
        int $id,
        int $suggestionId,
    ): WeeklyPlanResource {
        /** @var User $user */
        $user = $request->user();

        return new WeeklyPlanResource($this->plans->decideSuggestion(
            $user,
            $id,
            $suggestionId,
            WeeklyPlanSuggestionStatus::from($request->validated('status')),
        ));
    }
}
