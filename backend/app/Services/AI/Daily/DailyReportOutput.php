<?php

namespace App\Services\AI\Daily;

use App\Services\AI\WorkReportGroupedWork;
use App\Services\AI\WorkReportWorkTotals;
use RuntimeException;

final class DailyReportOutput
{
    public const VERSION = 'daily-output-v3';

    public static function validate(array $output, array $source, bool $completeInventory = true): array
    {
        if (array_diff(array_keys($output), ['observations', 'comments', 'recommendations', 'work_groups']) || array_diff(['observations', 'comments', 'recommendations'], array_keys($output))) {
            throw new RuntimeException('AI_INVALID_SCHEMA');
        }
        if (array_key_exists('work_groups', $output)) {
            WorkReportGroupedWork::validate($output['work_groups'], [$source], $completeInventory);
        }
        $items = array_column($source['items'], null, 'item_id');
        foreach (['comments', 'recommendations'] as $field) {
            if (! is_array($output[$field]) || ! array_is_list($output[$field])) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
            foreach ($output[$field] as $entry) {
                $item = is_int($entry['item_id'] ?? null) ? ($items[$entry['item_id']] ?? null) : null;
                if (! is_array($entry) || count($entry) !== 3 || ! is_string($entry['text'] ?? null) || trim($entry['text']) === ''
                    || ! is_string($entry['quote'] ?? null) || trim($entry['quote']) === '' || ! $item
                    || ! collect(['content', 'result', 'issue', 'note'])->contains(fn ($key) => str_contains($item[$key], $entry['quote']))) {
                    throw new RuntimeException('AI_INVALID_EVIDENCE');
                }
            }
        }
        if (! is_array($output['observations']) || ! array_is_list($output['observations'])) {
            throw new RuntimeException('AI_INVALID_SCHEMA');
        }
        foreach ($output['observations'] as &$observation) {
            if (! is_array($observation) || ! is_int($observation['item_id'] ?? null) || ! isset($items[$observation['item_id']])) {
                throw new RuntimeException('AI_INVALID_EVIDENCE');
            }
            // Product quantities are rebound from the saved report, including partial progress.
            // Ignore model counts for these rows; normalize() supplies the authoritative fact.
            if (is_array($items[$observation['item_id']]['task_quantity'] ?? null)) {
                continue;
            }
            $quantity = $observation['quantity'] ?? null;
            if ((! is_int($quantity) && ! is_float($quantity)) || ! is_finite((float) $quantity)
                || $quantity < 0 || $quantity > 1000000000 || abs($quantity - round($quantity, 6)) > 0.000000001) {
                throw new RuntimeException('AI_QUANTITY_PRECISION');
            }
            $item = $items[$observation['item_id']];
            // A result quantity is accepted only from completed work and its content/result text.
            if ($item['status'] !== 'COMPLETED' || ! is_string($observation['quote'] ?? null)
                || trim($observation['quote']) === ''
                || ! collect(['content', 'result'])->contains(fn ($key) => str_contains($item[$key], $observation['quote']))) {
                throw new RuntimeException('AI_INVALID_EVIDENCE');
            }
            if (($observation['report_id'] ?? null) !== $source['report_id']) {
                throw new RuntimeException('AI_INVALID_EVIDENCE');
            }
            $observation['unit'] = match (mb_strtolower(trim($observation['unit'] ?? ''))) {
                'sp', 'sản phẩm' => 'sản phẩm', 'hình', 'ảnh' => 'ảnh', 'clip', 'video' => 'video',
                default => $observation['unit'] ?? '',
            };
        }
        unset($observation);
        $reports = [['report_id' => $source['report_id'], 'items' => array_map(fn ($item) => [
            ...$item, 'note' => implode(' ', [$item['note'], $item['issue']]),
        ], $source['items'])]];
        $output['observations'] = WorkReportWorkTotals::normalize($output['observations'], $reports);

        return $output;
    }

    public static function assemble(array $results, array $snapshot): array
    {
        $output = ['observations' => [], 'comments' => [], 'recommendations' => []];
        $groups = [];
        $grouped = true;
        foreach ($results as $result) {
            $grouped = $grouped && array_key_exists('work_groups', $result);
            array_push($groups, ...($result['work_groups'] ?? []));
            foreach (array_keys($output) as $key) {
                array_push($output[$key], ...$result[$key]);
            }
        }
        if ($grouped) {
            $output['work_groups'] = $groups;
        }
        $output = self::validate($output, $snapshot, false);
        $output['work_totals'] = WorkReportWorkTotals::totals($output['observations']);
        // Daily metrics use fixed six-decimal arithmetic for accumulation (not floating-point sums).
        foreach ($output['work_totals'] as &$group) {
            if ($group['total'] !== null) {
                $micros = 0;
                foreach ($group['entries'] as $entry) {
                    $quantity = $entry['quantity'];
                    if ($quantity > 1000000000 || abs($quantity - round($quantity, 6)) > 0.000000001) {
                        throw new RuntimeException('AI_QUANTITY_PRECISION');
                    }
                    [$whole, $fraction] = explode('.', number_format($quantity, 6, '.', ''));
                    $micros += ((int) $whole * 1000000) + (int) $fraction;
                }
                $group['total'] = $micros / 1000000;
            }
        }
        unset($group);
        $output['schema_version'] = self::VERSION;

        return $output;
    }
}
