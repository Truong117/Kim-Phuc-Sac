<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\SyncFacebookAnalytics;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Services\Social\Analytics\FacebookAnalyticsAccess;
use App\Services\Social\Analytics\FacebookAnalyticsAi;
use App\Services\Social\Analytics\FacebookAnalyticsRead;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacebookAnalyticsController extends Controller
{
    public function __construct(private readonly FacebookAnalyticsAccess $access) {}

    public function pages(Request $request): JsonResponse
    {
        abort_unless($this->access->enabled(), 404);
        $entries = $this->access->entries($request->user())->with('responsible:id,name')->orderBy('name')->limit(101)->get();
        abort_if($entries->count() > 100, 422, 'Số Page vượt giới hạn hiển thị.');

        return response()->json(['data' => $entries->map(function ($entry) {
            $connection = SocialConnection::query()->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->first();

            return ['id' => (int) $entry->id, 'name' => $connection?->page_name ?? $entry->name, 'responsible_name' => $entry->responsible?->name, 'status' => $connection?->status ?? 'disconnected'];
        }), 'can_manage' => $this->access->broad($request->user())]);
    }

    private function period(Request $request): array
    {
        $data = $request->validate(['from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:'.now('Asia/Ho_Chi_Minh')->toDateString()]]);
        if (CarbonImmutable::parse($data['from_date'])->diffInDays(CarbonImmutable::parse($data['to_date'])) > 89) {
            throw ValidationException::withMessages(['from_date' => 'Chọn tối đa 90 ngày cho mỗi lần phân tích.']);
        }

        return $data;
    }

    public function show(Request $request, int $id, FacebookAnalyticsRead $read, FacebookAnalyticsAi $ai): JsonResponse
    {
        $entry = $this->access->entry($request->user(), $id);
        $input = $this->period($request);
        $data = $read->show($request->user(), $entry, $input['from_date'], $input['to_date']);
        $connection = SocialConnection::query()->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->first();
        $data['analysis'] = $connection === null ? null : $ai->latest($entry, $data);

        abort_if(strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 4194304, 422, 'Kết quả quá lớn. Hãy giảm khoảng ngày.');

        return response()->json(['data' => $data]);
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        $entry = $this->access->entry($request->user(), $id);
        $connection = $this->access->connection($entry);
        abort_unless($connection->status === 'connected' && $this->access->principalAvailable($connection), 422, 'Cần kết nối Page hợp lệ trước khi đồng bộ.');
        $queue = DB::transaction(function () use ($connection): bool {
            IntegrationEntry::query()->whereKey($connection->integration_entry_id)->lockForUpdate()->firstOrFail();
            $state = FacebookAnalyticsState::query()->firstOrCreate(['social_connection_id' => $connection->id]);
            $state = FacebookAnalyticsState::query()->whereKey($state->id)->lockForUpdate()->firstOrFail();
            if ($state->leased_until?->isFuture() || ($state->status === 'queued' && $state->updated_at->gt(now()->subMinutes(6)))) {
                return false;
            }
            $state->update(['status' => 'queued', 'error_code' => null]);

            return true;
        });
        if ($queue) {
            SyncFacebookAnalytics::dispatch($id, $request->user()->id);
        }

        return response()->json(['message' => 'Đã xếp hàng đồng bộ danh mục và số liệu Page.'], 202);
    }

    public function topic(Request $request, int $id, int $post): JsonResponse
    {
        $entry = $this->access->entry($request->user(), $id);
        $connection = $this->access->connection($entry);
        $input = $request->validate(['topic' => ['nullable', 'in:education,product,company,recruitment,other']]);
        $row = DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)->where('organization_id', $entry->organization_id)->where('id', $post)->first();
        abort_if($row === null, 404);
        DB::table('facebook_analytics_posts')->where('id', $post)->update(['topic' => $input['topic'] ?? null, 'updated_at' => now()]);

        return response()->json(null, 204);
    }

    public function analyze(Request $request, int $id, FacebookAnalyticsRead $read, FacebookAnalyticsAi $ai): JsonResponse
    {
        $entry = $this->access->entry($request->user(), $id);
        $input = $this->period($request);
        $data = $read->show($request->user(), $entry, $input['from_date'], $input['to_date']);
        $row = $ai->request($request->user(), $entry, $data);

        return response()->json(['data' => ['id' => $row->id, 'status' => $row->status]], $row->status === 'completed' ? 200 : 202);
    }
}
