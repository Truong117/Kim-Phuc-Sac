<?php

namespace App\Services\AI\Daily;

use App\Models\DailyReport;
use Illuminate\Validation\ValidationException;

final class DailyReportSource
{
    public const VERSION = 'daily-source-v2';

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function capture(DailyReport $report): array
    {
        $report->loadMissing('items');
        $snapshot = [
            'version' => self::VERSION, 'report_id' => (int) $report->id,
            'organization_id' => (int) $report->organization_id, 'employee_id' => (int) $report->user_id,
            'date' => $report->report_date->toDateString(), 'is_makeup' => (bool) $report->is_makeup,
            'submitted_at' => $report->created_at->toIso8601String(),
            'next_day_plan' => array_values(array_filter(array_map('trim', preg_split('/\R/u', $report->next_day_plan ?? '')), fn ($line) => $line !== '')),
            'items' => $report->items->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()->map(fn ($item) => [
                'item_id' => (int) $item->id, 'status' => $item->status->value,
                'content' => $item->content, 'result' => $item->result ?? '', 'note' => $item->note ?? '',
                'issue' => $item->issue ?? '', 'evidence_links' => $item->evidence_links ?? [],
                'evidence_status' => 'unverified',
            ])->all(),
        ];
        if (strlen(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > config('daily_report_ai.max_snapshot_bytes')) {
            throw ValidationException::withMessages(['report' => 'Báo cáo vượt kích thước nguồn được hỗ trợ; không cắt bỏ dữ liệu.']);
        }

        return $snapshot;
    }

    public static function parts(array $snapshot, int $limit, int $maxParts): array
    {
        if ($limit < 512 || $maxParts < 1) {
            throw ValidationException::withMessages(['configuration' => 'Giới hạn phần AI không hợp lệ.']);
        }
        $base = array_diff_key($snapshot, ['items' => true, 'next_day_plan' => true]);
        // Plans are kept in the authoritative snapshot; they are never treated as achieved output.
        $parts = [];
        $current = [...$base, 'items' => []];
        foreach ($snapshot['items'] as $item) {
            $candidate = [...$current, 'items' => [...$current['items'], $item]];
            if (strlen(json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $limit) {
                if ($current['items'] !== []) {
                    $parts[] = $current;
                }
                $current = [...$base, 'items' => [$item]];
                if (strlen(json_encode($current, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $limit) {
                    throw ValidationException::withMessages(['report' => 'Một đầu việc vượt kích thước phần AI; cần chia nhỏ, không tự cắt nội dung.']);
                }
            } else {
                $current = $candidate;
            }
        }
        if ($current['items'] !== [] || $parts === []) {
            $parts[] = $current;
        }
        if (count($parts) > $maxParts) {
            throw ValidationException::withMessages(['report' => 'Báo cáo vượt số phần AI được hỗ trợ.']);
        }

        return $parts;
    }
}
