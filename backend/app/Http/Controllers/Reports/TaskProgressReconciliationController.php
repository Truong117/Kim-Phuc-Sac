<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\ReportTaskProgress;
use App\Services\TaskProgressReconciliation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TaskProgressReconciliationController extends Controller
{
    public function index(int $id, Request $request, TaskProgressReconciliation $service)
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);

        return response()->json($service->candidates($request->user(), $id, $data))->header('Cache-Control', 'private, no-store');
    }

    public function store(int $id, Request $request, TaskProgressReconciliation $service)
    {
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'], 'unit' => ['required', Rule::in(ReportTaskProgress::UNITS)],
            'confirmed' => ['required', 'accepted'], 'confirmation_note' => ['required', 'string', 'min:5', 'max:1000'],
            'entries' => ['required', 'array', 'min:1', 'max:20'], 'entries.*' => ['required', 'array:report_item_id,source_hash,outputs'],
            'entries.*.report_item_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'entries.*.source_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'entries.*.outputs' => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.outputs.*' => ['required', 'array:name,reference'],
            'entries.*.outputs.*.name' => ['required', 'string', 'max:200'],
            'entries.*.outputs.*.reference' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
        ]);

        return response()->json(['data' => $service->apply($request->user(), $id, $data)])->header('Cache-Control', 'private, no-store');
    }
}
