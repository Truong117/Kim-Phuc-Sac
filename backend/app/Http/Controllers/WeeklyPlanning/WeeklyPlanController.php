<?php

namespace App\Http\Controllers\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanSuggestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyPlanning\ApplyWeeklyPlanSourceEntriesRequest;
use App\Http\Requests\WeeklyPlanning\AssignWeeklyPlanSourceGroupRequest;
use App\Http\Requests\WeeklyPlanning\DecideWeeklyPlanSuggestionRequest;
use App\Http\Requests\WeeklyPlanning\ImportWeeklyPlanRequest;
use App\Http\Requests\WeeklyPlanning\ReparseWeeklyPlanRequest;
use App\Http\Requests\WeeklyPlanning\ReplaceWeeklyPlanSourceRequest;
use App\Http\Requests\WeeklyPlanning\ReviewWeeklyPlanEmployeeRequest;
use App\Http\Requests\WeeklyPlanning\StoreWeeklyPlanCommentRequest;
use App\Http\Requests\WeeklyPlanning\WeeklyPlanEntryRequest;
use App\Http\Resources\WeeklyPlanResource;
use App\Models\User;
use App\Services\WeeklyPlanning\WeeklyPlanService;
use App\Services\WeeklyPlanning\WeeklyPlanTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WeeklyPlanController extends Controller
{
    public function __construct(private readonly WeeklyPlanService $plans) {}

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

    public function extractSourceWithAi(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'suggestions' => $this->plans->previewAiSourceEntries($user, $id),
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
