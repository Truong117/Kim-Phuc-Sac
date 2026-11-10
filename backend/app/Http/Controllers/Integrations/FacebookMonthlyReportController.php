<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Social\Analytics\FacebookAnalyticsAccess;
use App\Services\Social\Analytics\FacebookMonthlyReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacebookMonthlyReportController extends Controller
{
    public function __construct(private readonly FacebookAnalyticsAccess $access, private readonly FacebookMonthlyReport $reports) {}

    private function month(Request $request): array
    {
        return $request->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:2020-01', 'before_or_equal:'.now('Asia/Ho_Chi_Minh')->format('Y-m')]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $entry = $this->access->entry($request->user(), $id);
        abort_unless($this->reports->enabled(), 404);
        $period = $this->month($request);
        $input = $request->validate(['view' => ['sometimes', 'in:saved,live']]);

        return $this->respond($this->reports->show($request->user(), $entry, $period['month'], ($input['view'] ?? 'saved') === 'live'));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $entry = $this->access->entry($request->user(), $id);
        abort_unless($this->reports->enabled(), 404);
        $month = $this->month($request);
        $input = $request->validate([
            'entry_version' => ['required', 'integer', 'min:1'], 'source_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'notes' => ['present', 'nullable', 'string', 'max:2000'], 'acknowledge_limits' => ['required', 'boolean'],
            'actions' => ['present', 'array', 'list', 'max:3'], 'actions.*' => ['array:id,title,expected_result,metric,due_date,review_date,status,result_note'],
            'actions.*.id' => ['required', 'uuid', 'distinct:ignore_case'], 'actions.*.title' => ['required', 'string', 'max:160'],
            'actions.*.expected_result' => ['required', 'string', 'max:600'], 'actions.*.metric' => ['present', 'nullable', 'in:likes,comments,media_views'],
            'actions.*.due_date' => ['required', 'date_format:Y-m-d'], 'actions.*.review_date' => ['required', 'date_format:Y-m-d'],
            'actions.*.status' => ['required', 'in:planned'], 'actions.*.result_note' => ['present', 'nullable', 'string', 'max:1000'],
        ]);
        $input['notes'] ??= '';
        foreach ($input['actions'] as &$action) {
            $action['result_note'] ??= '';
        }
        unset($action);

        return $this->respond($this->reports->close($request->user(), $entry, [...$month, ...$input]));
    }

    private function respond(array $data): JsonResponse
    {
        abort_if(strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 4194304, 422, 'Báo cáo quá lớn.');

        return response()->json(['data' => $data]);
    }
}
