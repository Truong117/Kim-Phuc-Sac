<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\IndexTasksRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function index(IndexTasksRequest $request, TaskService $tasks): AnonymousResourceCollection
    {
        $result = $tasks->paginate($request->user(), $request->validated());

        return TaskResource::collection($result['paginator'])->additional([
            'summary' => $result['summary'],
            'actions' => ['can_create' => $result['can_create']],
        ]);
    }

    public function references(Request $request, TaskService $tasks): JsonResponse
    {
        return response()->json([
            'data' => $tasks->references($request->user()),
        ]);
    }

    public function store(StoreTaskRequest $request, TaskService $tasks): JsonResponse
    {
        return TaskResource::make(
            $tasks->create($request->user(), $request->validated()),
        )->response()->setStatusCode(201);
    }

    public function show(int $id, Request $request, TaskService $tasks): TaskResource
    {
        return TaskResource::make($tasks->findVisible($request->user(), $id));
    }

    public function update(
        int $id,
        UpdateTaskRequest $request,
        TaskService $tasks,
    ): TaskResource {
        return TaskResource::make(
            $tasks->update($request->user(), $id, $request->validated()),
        );
    }

    public function updateStatus(
        int $id,
        UpdateTaskStatusRequest $request,
        TaskService $tasks,
    ): TaskResource {
        return TaskResource::make(
            $tasks->updateStatus(
                $request->user(),
                $id,
                TaskStatus::from($request->validated('status')),
            ),
        );
    }
}
