<?php

namespace App\Services\AI\Daily;

use App\Services\AI\WorkReportWorkTotals;
use RuntimeException;

/** Resolve typed references locally; the model never writes the authoritative quote. */
final class DailyEvidence
{
    public static function bind(array $output, array $source): array
    {
        $keys = ['work_groups', 'observations', 'comments', 'recommendations'];
        if (array_diff(array_keys($output), $keys) || array_diff($keys, array_keys($output))) {
            throw new RuntimeException('AI_INVALID_SCHEMA');
        }
        foreach ($keys as $key) {
            if (! is_array($output[$key]) || ! array_is_list($output[$key])) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
        }
        $items = array_column($source['items'], null, 'item_id');
        $resolve = static function (mixed $ref, array $expected, bool $metric = false) use ($source, $items): array {
            if (! is_array($ref) || count($ref) !== count($expected) || array_diff(array_keys($ref), $expected)
                || ! is_int($ref['item_id'] ?? null) || ! isset($items[$ref['item_id']])
                || (in_array('report_id', $expected, true) && ($ref['report_id'] ?? null) !== $source['report_id'])
                || ! in_array($ref['field'] ?? null, $metric ? ['content', 'result'] : ['content', 'result', 'note', 'issue'], true)) {
                throw new RuntimeException('AI_INVALID_EVIDENCE');
            }
            $text = $items[$ref['item_id']][$ref['field']] ?? null;
            if (! is_string($text) || trim($text) === '') {
                throw new RuntimeException('AI_INVALID_EVIDENCE');
            }
            unset($ref['field']);
            $ref['quote'] = $metric ? self::metricQuote($text, $ref, $source) : mb_strcut(trim($text), 0, 1200, 'UTF-8');

            return $ref;
        };
        foreach ($output['work_groups'] as &$group) {
            if (! is_array($group) || ! is_array($group['evidence'] ?? null) || ! array_is_list($group['evidence'])) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
            $group['evidence'] = array_map(fn ($ref) => $resolve($ref, ['report_id', 'item_id', 'field']), $group['evidence']);
        }
        unset($group);
        foreach (['comments', 'recommendations'] as $key) {
            $output[$key] = array_map(fn ($ref) => $resolve($ref, ['text', 'item_id', 'field']), $output[$key]);
        }
        $output['observations'] = array_map(fn ($ref) => $resolve($ref,
            ['metric', 'unit', 'quantity', 'basis', 'report_id', 'item_id', 'field'], true), $output['observations']);

        return $output;
    }

    private static function metricQuote(string $text, array $entry, array $source): string
    {
        // Retain the existing quote-size ceiling. Overlap and whole-word edges prevent
        // a long field or a number at a window boundary from changing the quantity.
        $candidates = strlen($text) <= 1500 ? [$text] : [];
        if ($candidates === []) {
            for ($offset = 0; $offset < strlen($text); $offset += 600) {
                $quote = mb_strcut($text, $offset, 1200, 'UTF-8');
                if ($offset > 0) {
                    $quote = preg_replace('/^\S*\s/u', '', $quote) ?? '';
                }
                if ($offset + 1200 < strlen($text)) {
                    $quote = preg_replace('/\s+\S*$/u', '', $quote) ?? '';
                }
                $candidates[] = $quote;
            }
        }
        foreach ($candidates as $quote) {
            try {
                WorkReportWorkTotals::normalize([[...$entry, 'quote' => $quote]], [$source]);

                return $quote;
            } catch (RuntimeException) {
                // Try another exact source window, never rewrite or infer a number.
            }
        }
        throw new RuntimeException('AI_INVALID_EVIDENCE');
    }
}

