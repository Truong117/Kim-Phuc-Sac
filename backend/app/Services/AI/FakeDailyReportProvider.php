<?php

namespace App\Services\AI;

class FakeDailyReportProvider implements DailyReportAiProvider
{
    public function analyze(array $context, array $requestMetadata = []): array
    {
        $counts = $context['work_counts'];
        $attention = [];
        $completedReportIds = [];

        foreach ($context['reports'] as $report) {
            foreach ($report['items'] as $item) {
                if ($item['status'] === 'COMPLETED') {
                    $completedReportIds[] = $report['report_id'];
                }
                if ($item['status'] === 'BLOCKED' && count($attention) < 5) {
                    $attention[] = [
                        'text' => 'Công việc bị chặn: '.$item['content'],
                        'report_ids' => [$report['report_id']],
                    ];
                }
            }
        }

        $blockedReportIds = [];
        foreach ($attention as $item) {
            $blockedReportIds = array_merge($blockedReportIds, $item['report_ids']);
        }

        return [
            'provider' => 'fake',
            'model' => 'deterministic-v1',
            'input_tokens' => null,
            'output_tokens' => null,
            'result' => [
                'overview' => "Có {$counts['reports']} báo cáo, {$counts['items']} đầu việc; {$counts['completed']} hoàn thành, {$counts['in_progress']} đang thực hiện, {$counts['blocked']} bị chặn.",
                'highlights' => $counts['completed'] > 0 ? [[
                    'text' => "Có {$counts['completed']} đầu việc được nhân viên báo cáo hoàn thành (chưa xác minh nghiệm thu).",
                    'report_ids' => array_values(array_unique($completedReportIds)),
                ]] : [],
                'attention' => $attention,
                'recommendations' => $counts['blocked'] > 0 ? [[
                    'text' => 'Người quản lý cần xác minh nguyên nhân và hỗ trợ tháo gỡ các đầu việc bị chặn.',
                    'report_ids' => array_values(array_unique($blockedReportIds)),
                ]] : [],
            ],
        ];
    }
}
