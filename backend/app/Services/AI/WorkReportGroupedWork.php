<?php

namespace App\Services\AI;

use RuntimeException;

/** A thematic reading layer; source references retain every underlying work item. */
final class WorkReportGroupedWork
{
    public const VERSION = 'grouped-work-v1';

    public static function schema(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string'], 'text' => ['type' => 'string'],
            'evidence' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'report_id' => ['type' => 'integer'], 'item_id' => ['type' => 'integer'], 'quote' => ['type' => 'string'],
            ], 'required' => ['report_id', 'item_id', 'quote'], 'additionalProperties' => false]],
        ], 'required' => ['title', 'text', 'evidence'], 'additionalProperties' => false]];
    }

    public static function validate(mixed $groups, array $reports, bool $complete = true): void
    {
        if (! is_array($groups) || ! array_is_list($groups)) {
            throw new RuntimeException('AI_INVALID_SCHEMA');
        }
        $items = [];
        foreach ($reports as $report) {
            foreach ($report['items'] as $item) {
                $items[$report['report_id'].':'.$item['item_id']] = $item;
            }
        }
        $seen = [];
        foreach ($groups as $group) {
            if (! is_array($group) || count($group) !== 3 || ! is_string($group['title'] ?? null)
                || trim($group['title']) === '' || ! is_string($group['text'] ?? null) || trim($group['text']) === ''
                || ! is_array($group['evidence'] ?? null) || ! array_is_list($group['evidence']) || $group['evidence'] === []) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
            foreach ($group['evidence'] as $ref) {
                if (! is_array($ref) || count($ref) !== 3 || ! is_int($ref['report_id'] ?? null) || ! is_int($ref['item_id'] ?? null)
                    || ! is_string($ref['quote'] ?? null) || trim($ref['quote']) === '') {
                    throw new RuntimeException('AI_INVALID_EVIDENCE');
                }
                $key = $ref['report_id'].':'.$ref['item_id'];
                $item = $items[$key] ?? null;
                if (! $item || isset($seen[$key]) || ! collect(['content', 'result', 'note', 'issue'])->contains(
                    fn ($field) => is_string($item[$field] ?? null) && str_contains($item[$field], $ref['quote']),
                )) {
                    throw new RuntimeException('AI_INVALID_EVIDENCE');
                }
                $seen[$key] = true;
            }
        }
        if ($complete && count($seen) !== count($items)) {
            throw new RuntimeException('AI_INCOMPLETE_INVENTORY');
        }
    }

    public static function fake(array $reports): array
    {
        $groups = [];
        $seen = [];
        foreach ($reports as $report) {
            foreach ($report['items'] as $item) {
                $key = $report['report_id'].':'.$item['item_id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $hash = hash('sha256', $item['content']);
                $groups[$hash] ??= ['title' => $item['content'], 'text' => '', 'evidence' => []];
                $groups[$hash]['text'] .= ($groups[$hash]['text'] === '' ? '' : "\n")
                    .($report['date'] ?? '').' · '.$item['status'].' · '.($item['result'] ?? '').' '.($item['issue'] ?? $item['note'] ?? '');
                $groups[$hash]['evidence'][] = ['report_id' => (int) $report['report_id'], 'item_id' => (int) $item['item_id'], 'quote' => $item['content']];
            }
        }

        return array_values($groups);
    }
}
