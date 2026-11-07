<?php

namespace App\Services\AI;

use RuntimeException;

/** Translate model-selected source fields into authoritative, frozen quotations. */
final class WorkReportEvidence
{
    private const FIELDS = ['content', 'result', 'note'];

    public static function prepare(array $input, array $reports): array
    {
        $items = self::items($reports);
        $walk = function (array $value) use (&$walk, $items): array {
            if (isset($value['report_id'], $value['item_id'], $value['quote'])) {
                $item = $items[$value['report_id'].':'.$value['item_id']] ?? [];
                foreach (self::FIELDS as $field) {
                    if (is_string($item[$field] ?? null) && is_string($value['quote'])
                        && trim($value['quote']) !== '' && str_contains($item[$field], $value['quote'])) {
                        return [...$value, 'field' => $field];
                    }
                }
                throw new WorkReportEvidenceException('saved_quote_mismatch', 'input.evidence');
            }

            return array_map(fn ($entry) => is_array($entry) ? $walk($entry) : $entry, $value);
        };

        return $walk($input);
    }

    /** Fake provider uses the same wire contract; stored-result fixtures stay unchanged. */
    public static function references(array $result, array $input): array
    {
        $reports = isset($input['work_inventory']) ? WorkReportWeeklyWork::reports($input['work_inventory']) : ($input['reports'] ?? []);
        $items = self::items($reports);
        $walk = function (array $value) use (&$walk, $items): array {
            if (isset($value['report_id'], $value['item_id'], $value['quote'])) {
                if (! isset($value['field'])) {
                    $item = $items[$value['report_id'].':'.$value['item_id']] ?? [];
                    foreach (self::FIELDS as $field) {
                        if (is_string($item[$field] ?? null) && str_contains($item[$field], $value['quote'])) {
                            $value['field'] = $field;
                            break;
                        }
                    }
                }
                unset($value['quote']);

                return $value;
            }

            return array_map(fn ($entry) => is_array($entry) ? $walk($entry) : $entry, $value);
        };

        return $walk($result);
    }

    public static function bind(array $output, array $reports, array $input): array
    {
        $keys = ['work_groups', 'quantity_observations', ...WorkReportAnalysisOutput::SECTIONS];
        if (array_diff(array_keys($output), $keys) || array_diff($keys, array_keys($output))) {
            throw new RuntimeException('AI_INVALID_SCHEMA');
        }
        foreach ($keys as $key) {
            if (! is_array($output[$key]) || ! array_is_list($output[$key])) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
        }
        $items = self::items($reports);
        // Later stages may only cite source fields/windows actually supplied in their input.
        $limited = isset($input['parts']) || isset($input['daily_documents']);
        $windows = [];
        $collect = function (array $value) use (&$collect, &$windows): void {
            if (isset($value['report_id'], $value['item_id'], $value['field'], $value['quote'])) {
                $windows[$value['report_id'].':'.$value['item_id']][$value['field']][] = $value['quote'];
            }
            if (isset($value['work_inventory'])) {
                foreach (WorkReportWeeklyWork::reports($value['work_inventory']) as $report) {
                    foreach ($report['items'] as $item) {
                        foreach (self::FIELDS as $field) {
                            if (is_string($item[$field] ?? null) && trim($item[$field]) !== '') {
                                $windows[$report['report_id'].':'.$item['item_id']][$field][] = $item[$field];
                            }
                        }
                    }
                }
            }
            foreach ($value as $entry) {
                if (is_array($entry)) {
                    $collect($entry);
                }
            }
        };
        if ($limited) {
            $collect($input);
        }
        $resolve = static function (mixed $ref, string $location, ?array $metric = null) use ($items, $windows, $limited): array {
            if (! is_array($ref) || count($ref) !== 3 || array_diff(array_keys($ref), ['report_id', 'item_id', 'field'])
                || ! is_int($ref['report_id'] ?? null) || ! is_int($ref['item_id'] ?? null)
                || ! in_array($ref['field'] ?? null, self::FIELDS, true)) {
                throw new WorkReportEvidenceException('invalid_reference_shape', $location);
            }
            $key = $ref['report_id'].':'.$ref['item_id'];
            $item = $items[$key] ?? null;
            if ($item === null) {
                throw new WorkReportEvidenceException('source_item_not_allowed', $location);
            }
            $text = $item[$ref['field']] ?? null;
            if (! is_string($text) || trim($text) === '') {
                throw new WorkReportEvidenceException('source_field_empty', $location);
            }
            $candidates = $limited ? ($windows[$key][$ref['field']] ?? []) : [$text];
            if ($candidates === []) {
                throw new WorkReportEvidenceException('source_field_not_provided', $location);
            }
            unset($ref['field']);
            foreach ($candidates as $candidate) {
                if (! is_string($candidate) || trim($candidate) === '' || ! str_contains($text, $candidate)) {
                    throw new WorkReportEvidenceException('saved_quote_mismatch', $location);
                }
                if ($metric === null) {
                    return [...$ref, 'quote' => mb_strcut(trim($candidate), 0, 1200, 'UTF-8')];
                }
                foreach (self::metricWindows($candidate) as $quote) {
                    try {
                        WorkReportWorkTotals::normalize([[...$metric, ...$ref, 'quote' => $quote]], [
                            ['report_id' => $ref['report_id'], 'items' => [$item]],
                        ]);

                        return [...$ref, 'quote' => $quote];
                    } catch (RuntimeException $exception) {
                        if ($exception->getMessage() !== 'AI_INVALID_EVIDENCE') {
                            throw $exception;
                        }
                    }
                }
            }
            throw new WorkReportEvidenceException('quantity_not_in_source', $location);
        };
        foreach (['work_groups', ...WorkReportAnalysisOutput::SECTIONS] as $section) {
            foreach ($output[$section] as $index => &$claim) {
                if (! is_array($claim) || ! is_array($claim['evidence'] ?? null) || ! array_is_list($claim['evidence'])) {
                    throw new RuntimeException('AI_INVALID_SCHEMA');
                }
                $claim['evidence'] = array_map(fn ($ref, $i) => $resolve($ref, $section.'.'.$index.'.evidence.'.$i),
                    $claim['evidence'], array_keys($claim['evidence']));
            }
            unset($claim);
        }
        foreach ($output['quantity_observations'] as $index => &$observation) {
            if (! is_array($observation) || array_diff(array_keys($observation), ['metric', 'unit', 'quantity', 'basis', 'report_id', 'item_id', 'field'])) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
            $ref = array_intersect_key($observation, array_flip(['report_id', 'item_id', 'field']));
            $bound = $resolve($ref, 'quantity_observations.'.$index, array_diff_key($observation, $ref));
            unset($observation['field']);
            $observation = [...$observation, ...$bound];
        }
        unset($observation);
        $output['work_groups'] = self::mergeGroups($output['work_groups']);

        return $output;
    }

    private static function items(array $reports): array
    {
        $items = [];
        foreach ($reports as $report) {
            foreach ($report['items'] as $item) {
                $items[$report['report_id'].':'.$item['item_id']] = $item;
            }
        }

        return $items;
    }

    private static function metricWindows(string $text): array
    {
        if (strlen($text) <= 1500) {
            return [$text];
        }
        $windows = [];
        for ($offset = 0; $offset < strlen($text); $offset += 600) {
            $quote = mb_strcut($text, $offset, 1200, 'UTF-8');
            if ($offset > 0) {
                $quote = preg_replace('/^\S*\s/u', '', $quote) ?? '';
            }
            if ($offset + 1200 < strlen($text)) {
                $quote = preg_replace('/\s+\S*$/u', '', $quote) ?? '';
            }
            $windows[] = $quote;
        }

        return $windows;
    }

    private static function mergeGroups(array $groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            if (count($group) !== 3 || ! is_string($group['title'] ?? null) || trim($group['title']) === ''
                || ! is_string($group['text'] ?? null) || trim($group['text']) === '' || $group['evidence'] === []) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
            $refs = [];
            foreach ($group['evidence'] as $ref) {
                $refs[$ref['report_id'].':'.$ref['item_id']] ??= $ref;
            }
            $titles = [$group['title']];
            $texts = [$group['text']];
            foreach ($merged as $index => $previous) {
                if (array_intersect_key($refs, $previous['refs']) !== []) {
                    $refs = $previous['refs'] + $refs;
                    $titles = [...$previous['titles'], ...$titles];
                    $texts = [...$previous['texts'], ...$texts];
                    unset($merged[$index]);
                }
            }
            $merged[] = ['refs' => $refs, 'titles' => $titles, 'texts' => $texts];
        }

        return array_values(array_map(fn ($group) => [
            'title' => implode(' / ', array_unique($group['titles'])),
            'text' => implode("\n\n", array_unique($group['texts'])), 'evidence' => array_values($group['refs']),
        ], $merged));
    }
}
