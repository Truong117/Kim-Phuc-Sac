<?php

namespace App\Services\AI;

use App\Models\DailyReportAiDocument;
use App\Services\AI\Daily\DailyReportOutput;
use App\Services\AI\Daily\DailyReportSource;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Freeze a verified daily-document revision; never reread live daily data in a worker. */
final class WorkReportDailyDocuments
{
    public static function capture(Collection $reports, callable $redact): array
    {
        $documents = DailyReportAiDocument::query()->whereIn('daily_report_id', $reports->modelKeys())
            ->where('status', 'completed')->orderByDesc('id')->get()->groupBy('daily_report_id');
        $ready = [];
        $missing = [];
        foreach ($reports as $report) {
            $source = DailyReportSource::capture($report);
            $hash = DailyReportSource::hash($source);
            $selected = null;
            foreach ($documents->get($report->id, collect()) as $document) {
                if ($document->organization_id !== $report->organization_id || $document->source_hash !== $hash
                    || ($document->result['schema_version'] ?? null) !== DailyReportOutput::VERSION
                    || ($document->analysis_config['schema'] ?? null) !== DailyReportOutput::VERSION
                    || (config('work_report_ai.driver') !== 'fake' && $document->analysis_config['driver'] !== 'openai')) {
                    continue;
                }
                // Fail closed on corrupted saved data, not a raw-source fallback.
                $result = $document->result;
                WorkReportGroupedWork::validate($result['work_groups'] ?? null, [$source]);
                $validated = DailyReportOutput::validate(array_intersect_key($result, array_flip(['observations', 'comments', 'recommendations', 'work_groups'])), $source);
                $selected = ['document_id' => (int) $document->id, 'report_id' => (int) $report->id,
                    'employee_id' => (int) $report->user_id, 'date' => $source['date'], 'source_hash' => $hash,
                    'schema_version' => DailyReportOutput::VERSION,
                    'work_groups' => $validated['work_groups'], 'observations' => $validated['observations']];
                break;
            }
            if ($selected === null) {
                $missing[] = (int) $report->id;
            } else {
                // Apply the same privacy transform as weekly raw sources, including evidence quotes.
                $transform = function (mixed $value) use (&$transform, $redact): mixed {
                    return is_string($value) ? $redact($value) : (is_array($value) ? array_map($transform, $value) : $value);
                };
                $selected['work_groups'] = $transform($selected['work_groups']);
                $selected['observations'] = $transform($selected['observations']);
                $ready[] = $selected;
            }
        }

        return ['daily_documents' => $ready, 'daily_coverage' => [
            'mode' => 'daily-documents-v1', 'ready_reports' => count($ready), 'total_reports' => $reports->count(),
            'missing_report_ids' => $missing,
        ]];
    }

    public static function inputs(array $snapshot, array $settings, ?string $feedback): array
    {
        if (! isset($snapshot['daily_coverage']) || $snapshot['daily_coverage']['missing_report_ids'] !== []) {
            throw ValidationException::withMessages(['daily_documents' => 'Chưa đủ bản tổng hợp ngày hợp lệ, đã hoàn tất và khớp nguồn. Hãy xử lý các ngày thiếu/lỗi trước khi tạo báo cáo tuần.']);
        }
        $reports = array_column($snapshot['reports'], null, 'report_id');
        $limit = min(24000, max(2000, (int) $settings['unit_input_bytes']));
        $chunks = [];
        // Partition at whole thematic groups; do not cut summaries, evidence or quantitative facts.
        foreach ($snapshot['daily_documents'] as $document) {
            $report = $reports[$document['report_id']];
            foreach ($document['work_groups'] as $group) {
                $ids = array_column($group['evidence'], 'item_id');
                $fragment = [...$document, 'work_groups' => [$group], 'observations' => array_values(array_filter(
                    $document['observations'], fn ($o) => in_array($o['item_id'], $ids, true),
                ))];
                $id = $document['employee_id'];
                $entry = [...$report, 'items' => array_values(array_filter($report['items'], fn ($item) => in_array($item['item_id'], $ids, true)))];
                $base = ['employee_id' => $id, 'period' => $snapshot['period'], 'review_feedback' => $feedback];
                $size = fn (array $docs): int => strlen(json_encode([...$base, 'analysis_stage' => 'employee_review', 'daily_documents' => $docs], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if ($size([$fragment]) > $limit) {
                    throw ValidationException::withMessages(['daily_documents' => 'Một nhóm công việc ngày vượt kích thước xử lý tuần; cần tổng hợp lại ngày thành các nhóm nhỏ hơn. Không có nội dung bị cắt.']);
                }
                $last = count($chunks[$id] ?? []) - 1;
                if ($last < 0 || $size([...$chunks[$id][$last]['daily_documents'], $fragment]) > $limit) {
                    $chunks[$id][] = [...$base, 'daily_documents' => [], 'reports' => []];
                    $last++;
                }
                $chunks[$id][$last]['daily_documents'][] = $fragment;
                $chunks[$id][$last]['reports'][] = $entry;
            }
        }
        $inputs = array_merge([], ...array_values($chunks));
        if ($inputs === [] || count($inputs) + 1 > min(200, max(2, (int) $settings['max_units']))) {
            throw ValidationException::withMessages(['daily_documents' => 'Không có đầu việc để phân tích hoặc vượt số phần xử lý tuần.']);
        }

        return $inputs;
    }
}
