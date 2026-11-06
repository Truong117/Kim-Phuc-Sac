<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSocialMetrics;
use App\Models\DailyReportItem;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Services\KpsMembershipResolver;
use App\Services\ReportScopeService;
use App\Services\Social\FacebookMetricsClient;
use App\Services\Social\SocialMetricsRead;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SocialMetricsController extends Controller
{
    private function entry(Request $request, int $id): IntegrationEntry
    {
        $membership = app(KpsMembershipResolver::class)->activeMembershipFor($request->user());
        abort_if($membership === null, 403);

        return IntegrationEntry::query()->where('organization_id', $membership->organization_id)->where('kind', 'social')->where('platform', 'facebook')->findOrFail($id);
    }

    public function show(Request $request, int $id, SocialMetricsRead $metrics, ReportScopeService $reports): JsonResponse
    {
        $entry = $this->entry($request, $id);
        $input = $request->validate(['days' => ['nullable', 'integer', 'in:1,7,30']]);
        $end = now('Asia/Ho_Chi_Minh')->toDateString();
        $start = now('Asia/Ho_Chi_Minh')->subDays(($input['days'] ?? 7) - 1)->toDateString();
        $query = $reports->queryFor($request->user())->whereBetween('daily_reports.report_date', [$start, $end]);
        $items = DailyReportItem::query()->whereIn('daily_report_id', $query->select('daily_reports.id'))->limit(5001)->get();
        abort_if($items->count() > 5000, 422, 'Vượt giới hạn nguồn, hãy giảm kỳ theo dõi.');
        $connection = SocialConnection::query()->where('integration_entry_id', $id)->first();

        return response()->json(['data' => [
            'connection' => $connection === null ? null : ['page_id' => $connection->page_id, 'page_name' => $connection->page_name, 'status' => $connection->status, 'synced_at' => $connection->synced_at?->toIso8601String(), 'error_code' => $connection->error_code, 'sync_counts' => $connection->sync_counts],
            'posts' => $metrics->forItems($items, $start, $end, $entry->id), 'start_date' => $start, 'end_date' => $end, 'enabled' => (bool) config('social_metrics.enabled'),
        ]]);
    }

    public function connect(Request $request, int $id, FacebookMetricsClient $facebook): JsonResponse
    {
        $entry = $this->entry($request, $id);
        $input = $request->validate(['page_id' => ['required', 'string', 'regex:/^[0-9]{1,32}$/D'], 'access_token' => ['required', 'string', 'min:16', 'max:4096']]);
        try {
            $page = $facebook->get($input['access_token'], 'me', ['fields' => 'id,name,category']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => 'Không xác minh được quyền truy cập Page. Kiểm tra cấu hình và cấp quyền lại.', 'code' => $exception->getMessage()], 422);
        }
        abort_unless((string) ($page['id'] ?? '') === $input['page_id'] && is_string($page['name'] ?? null) && is_string($page['category'] ?? null), 422, 'Token không thuộc Page đã chọn.');
        try {
            DB::transaction(function () use ($entry, $input, $page, $request): void {
                IntegrationEntry::query()->whereKey($entry->id)->where('platform', 'facebook')->lockForUpdate()->firstOrFail();
                $row = SocialConnection::query()->where('integration_entry_id', $entry->id)->lockForUpdate()->first();
                $values = ['organization_id' => $entry->organization_id, 'page_id' => $input['page_id'], 'page_name' => mb_substr($page['name'], 0, 255), 'access_token' => $input['access_token'], 'connected_by' => $request->user()->id, 'status' => 'connected', 'revision' => ($row?->revision ?? 0) + 1, 'lease_token' => null, 'leased_until' => null, 'error_code' => null];
                if ($row !== null && $row->page_id !== $input['page_id']) {
                    abort(409, 'Hãy tạo mục tích hợp khác khi đổi sang Page khác để giữ lịch sử chính xác.');
                }
                $row === null ? SocialConnection::query()->create(['integration_entry_id' => $entry->id, ...$values]) : $row->update($values);
            });
        } catch (QueryException) {
            return response()->json(['message' => 'Page này đã có kết nối hoặc thông tin đã thay đổi. Tải lại và kiểm tra.'], 409);
        }

        return response()->json(['message' => 'Đã xác minh Page. Số liệu sẽ có sau khi đồng bộ.']);
    }

    public function disconnect(Request $request, int $id): JsonResponse
    {
        $this->entry($request, $id);
        DB::transaction(function () use ($id): void {
            $row = SocialConnection::query()->where('integration_entry_id', $id)->lockForUpdate()->first();
            $row?->update(['access_token' => null, 'status' => 'disconnected', 'revision' => $row->revision + 1, 'lease_token' => null, 'leased_until' => null]);
        });

        return response()->json(null, 204);
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        $entry = $this->entry($request, $id);
        abort_unless(config('social_metrics.enabled') && $entry->enabled, 422, 'Chức năng đồng bộ chưa được bật.');
        abort_unless(SocialConnection::query()->where('integration_entry_id', $id)->where('status', 'connected')->exists(), 422, 'Cần kết nối Page trước.');
        SyncSocialMetrics::dispatch($id);

        return response()->json(['message' => 'Đã đưa vào hàng đợi đồng bộ.'], 202);
    }
}
