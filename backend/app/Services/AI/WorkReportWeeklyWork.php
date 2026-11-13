<?php

namespace App\Services\AI;

final class WorkReportWeeklyWork
{
    public const VERSION = 'source-inventory-v1';

    public static function build(array $reports): array
    {
        $groups = [];
        $seen = [];
        foreach ($reports as $report) {
            foreach ($report['items'] as $item) {
                $source = $report['report_id'].':'.$item['item_id'];
                if (isset($seen[$source])) {
                    continue;
                }
                $seen[$source] = true;
                // Exact text groups are a reading aid, not an inferred task identity.
                $key = hash('sha256', $item['content']);
                $groups[$key] ??= ['content' => $item['content'], 'observations' => []];
                $groups[$key]['observations'][] = [
                    'report_id' => (int) $report['report_id'], 'item_id' => (int) $item['item_id'],
                    'date' => $report['date'], 'status' => $item['status'],
                    'result' => $item['result'] ?? '', 'note' => $item['note'] ?? null,
                    ...(array_key_exists('task_quantity', $item) ? ['task_quantity' => $item['task_quantity']] : []),
                ];
            }
        }
        foreach ($groups as &$group) {
            usort($group['observations'], fn ($a, $b) => [$a['date'], $a['report_id'], $a['item_id']] <=> [$b['date'], $b['report_id'], $b['item_id']]);
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => [$a['observations'][0]['date'], $a['content']] <=> [$b['observations'][0]['date'], $b['content']]);

        return $groups;
    }

    public static function reports(array $inventory): array
    {
        $reports = [];
        foreach ($inventory as $group) {
            foreach ($group['observations'] as $observation) {
                $reports[] = ['report_id' => $observation['report_id'], 'date' => $observation['date'],
                    'items' => [['item_id' => $observation['item_id'], 'content' => $group['content'],
                        'result' => $observation['result'], 'note' => $observation['note'], 'status' => $observation['status'],
                        ...(array_key_exists('task_quantity', $observation) ? ['task_quantity' => $observation['task_quantity']] : [])]]];
            }
        }

        return $reports;
    }
}
