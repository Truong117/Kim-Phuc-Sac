<?php

namespace App\Services\AI;

use RuntimeException;

final class WorkReportWorkTotals
{
    public static function normalize(array $observations, array $reports): array
    {
        $source = [];
        foreach ($reports as $report) {
            foreach ($report['items'] as $item) {
                $source[$report['report_id'].':'.$item['item_id']] = $item;
            }
        }
        $accepted = [];
        foreach ($observations as $observation) {
            if (! is_array($observation) || ! is_string($observation['metric'] ?? null)
                || ! is_string($observation['unit'] ?? null) || trim($observation['metric']) === '' || trim($observation['unit']) === ''
                || strlen($observation['metric']) > 300 || strlen($observation['unit']) > 100
                || ! is_int($observation['report_id'] ?? null) || ! is_int($observation['item_id'] ?? null)
                || ! is_string($observation['quote'] ?? null) || trim($observation['quote']) === '' || strlen($observation['quote']) > 1500
                || ! in_array($observation['basis'] ?? null, ['incremental', 'cumulative', 'unclear'], true)
                || (! is_int($observation['quantity'] ?? null) && ! is_float($observation['quantity'] ?? null))
                || ! is_finite((float) $observation['quantity']) || $observation['quantity'] < 0 || $observation['quantity'] > 1_000_000_000_000) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
            $item = $source[$observation['report_id'].':'.$observation['item_id']] ?? null;
            if ($item === null || ! collect(['content', 'result', 'note'])->contains(
                fn ($key) => is_string($item[$key] ?? null) && str_contains($item[$key], $observation['quote']),
            ) || ! self::hasQuantity($observation['quote'], (float) $observation['quantity'])) {
                throw new RuntimeException('AI_INVALID_EVIDENCE');
            }
            // Do not let a shortened quotation hide cumulative/repeated/planned work.
            $context = implode(' ', array_intersect_key($item, array_flip(['content', 'result', 'note'])));
            if (preg_match('/lũy kế|luỹ kế|cộng dồn|tính đến|tính từ|cả tuần|tổng tuần|nhắc lại|đã báo cáo|dự kiến|mục tiêu|chỉ tiêu/iu', $context)) {
                $observation['basis'] = 'unclear';
            }
            if (! self::hasQuantityWithUnit($observation['quote'], (float) $observation['quantity'], $observation['unit'])) {
                $observation['basis'] = 'unclear';
            }
            $observation['metric'] = trim($observation['metric']);
            $observation['unit'] = trim($observation['unit']);
            $key = hash('sha256', json_encode([$observation['metric'], $observation['unit'], $observation['report_id'],
                $observation['item_id'], $observation['quote'], $observation['quantity']], JSON_THROW_ON_ERROR));
            if (isset($accepted[$key]) && $accepted[$key]['basis'] !== $observation['basis']) {
                $observation['basis'] = 'unclear';
            }
            $accepted[$key] = $observation;
        }

        return array_values($accepted);
    }

    private static function hasQuantity(string $quote, float $quantity): bool
    {
        preg_match_all('/(?<!\pN)\d+(?:[.,]\d+)*(?!\pN)/u', $quote, $matches);
        foreach ($matches[0] as $number) {
            // Vietnamese notation: dots separate thousands, commas separate decimals.
            $value = preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $number)
                ? str_replace(',', '.', str_replace('.', '', $number)) : str_replace(',', '.', $number);
            if (is_numeric($value) && abs((float) $value - $quantity) < 0.000001) {
                return true;
            }
        }

        return false;
    }

    private static function hasQuantityWithUnit(string $quote, float $quantity, string $unit): bool
    {
        $unit = mb_strtolower(trim($unit));
        $aliases = match ($unit) {
            'sản phẩm', 'sp' => ['sản phẩm', 'sp'],
            'ảnh', 'hình' => ['ảnh', 'hình'],
            'video', 'clip' => ['video', 'clip'],
            default => [$unit],
        };
        $pattern = '/(?<!\pN)(\d+(?:[.,]\d+)*)\s*(?:'.implode('|', array_map(fn ($alias) => preg_quote($alias, '/'), $aliases)).')(?!\pL)/iu';
        preg_match_all($pattern, $quote, $matches);

        return collect($matches[1])->contains(fn ($number) => self::hasQuantity($number, $quantity));
    }

    public static function totals(array $observations): array
    {
        $groups = [];
        foreach ($observations as $observation) {
            $key = $observation['metric'].'|'.$observation['unit'];
            $groups[$key] ??= ['metric' => $observation['metric'], 'unit' => $observation['unit'], 'total' => 0, 'status' => 'confirmed', 'entries' => []];
            $entry = $observation['report_id'].':'.$observation['item_id'].':'.$observation['quantity'].':'.$observation['quote'];
            if (isset($groups[$key]['entries'][$entry])) {
                continue;
            }
            foreach ($groups[$key]['entries'] as $previous) {
                if ($previous['report_id'] === $observation['report_id'] && $previous['item_id'] === $observation['item_id']) {
                    $groups[$key]['status'] = 'needs_confirmation';
                }
            }
            $groups[$key]['entries'][$entry] = $observation;
            if ($observation['basis'] !== 'incremental') {
                $groups[$key]['status'] = 'needs_confirmation';
            }
            $groups[$key]['total'] += $observation['quantity'];
        }
        foreach ($groups as &$group) {
            $group['total'] = $group['status'] === 'confirmed' ? round($group['total'], 6) : null;
            $group['entries'] = array_values($group['entries']);
        }
        unset($group);

        return array_values($groups);
    }
}
