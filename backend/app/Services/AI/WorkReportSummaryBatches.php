<?php

namespace App\Services\AI;

final class WorkReportSummaryBatches
{
    public function plan(array $parts, array $period, ?string $feedback, int $missing, int $limit, int $perBatch): array
    {
        $batches = [];
        $results = [];
        $ids = [];
        $weekly = ($period['days'] ?? null) === 7;
        $input = fn (array $values, string $scope = 'partial'): array => ['period' => $period, 'parts' => $values,
            'analysis_stage' => 'company_summary', 'review_feedback' => $feedback, 'missing_units' => $missing,
            ...($weekly ? ['source_scope' => $scope] : [])];
        // A single planned batch runs as the terminal summary and can use the longer scope label.
        $bytes = fn (array $values): int => strlen(json_encode($input($values, 'complete'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $flush = function () use (&$batches, &$results, &$ids, $input): void {
            if ($results !== []) {
                $batches[] = ['input' => $input($results), 'source_unit_ids' => array_values(array_unique($ids))];
                $results = [];
                $ids = [];
            }
        };
        $append = function (int $id, array $result) use (&$results, &$ids, $bytes, $flush, $perBatch, $limit): void {
            if ($results !== [] && (count(array_unique([...$ids, $id])) > $perBatch || $bytes([...$results, $result]) > $limit)) {
                $flush();
            }
            if ($bytes([$result]) > $limit) {
                throw new AiGatewayException('AI_REDUCE_SCOPE', 'One evidence-backed claim exceeds the input budget.');
            }
            $results[] = $result;
            $ids[] = $id;
        };
        foreach ($parts as $part) {
            if ($bytes([$part['result']]) <= $limit) {
                $append($part['id'], $part['result']);

                continue;
            }
            $metadata = array_intersect_key($part['result'], array_flip(['employee_id', 'employee_name', 'employee_complete']));
            $pendingTotals = $part['result']['work_totals'] ?? [];
            if (! $weekly && $pendingTotals !== []) {
                $append($part['id'], [...array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []), ...$metadata, 'work_totals' => $pendingTotals]);
                $pendingTotals = [];
            }
            // For weekly runs, co-locate each subtotal with source content. A totals-only
            // batch would have nothing the reviewer may cite, even if the number is valid.
            $appendFragment = function (array $fragment) use ($append, $part, $bytes, $limit, &$pendingTotals): void {
                foreach ($pendingTotals as $index => $total) {
                    $candidate = $fragment;
                    $candidate['work_totals'][] = $total;
                    if ($bytes([$candidate]) <= $limit) {
                        $fragment = $candidate;
                        unset($pendingTotals[$index]);
                    }
                }
                $append($part['id'], $fragment);
            };
            foreach ($part['result']['work_groups'] ?? [] as $group) {
                $appendFragment([...array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []), ...$metadata, 'work_groups' => [$group]]);
            }
            // Preserve source inventory before partitioning the employee's AI assessment.
            foreach ($part['result']['work_inventory'] ?? [] as $group) {
                $fragment = [...array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []), ...$metadata, 'work_inventory' => [$group]];
                // Splitting a dense inventory by its existing observations can leave
                // enough room for a subtotal while keeping every observation exactly once.
                if ($bytes([$fragment]) <= $limit && ($pendingTotals === [] || count($group['observations']) < 2
                    || $bytes([[...$fragment, 'work_totals' => array_values($pendingTotals)]]) <= $limit)) {
                    $appendFragment($fragment);

                    continue;
                }
                foreach ($group['observations'] as $observation) {
                    $fragment['work_inventory'] = [['content' => $group['content'], 'observations' => [$observation]]];
                    $appendFragment($fragment);
                }
            }
            // Partition at whole claim boundaries. Never truncate text or evidence.
            foreach (WorkReportAnalysisOutput::SECTIONS as $section) {
                foreach ($part['result'][$section] as $claim) {
                    $result = [...array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []), ...$metadata];
                    $result[$section][] = $claim;
                    $appendFragment($result);
                }
            }
            if ($pendingTotals !== []) {
                throw new AiGatewayException('AI_REDUCE_SCOPE', 'A weekly subtotal cannot fit with source evidence in the input budget.');
            }
        }
        $flush();

        return $batches;
    }

    public function merge(array $results): array
    {
        $merged = array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []);
        $seen = [];
        foreach ($results as $result) {
            foreach (WorkReportAnalysisOutput::SECTIONS as $section) {
                foreach ($result[$section] as $claim) {
                    $key = $section.':'.hash('sha256', json_encode($claim, JSON_THROW_ON_ERROR));
                    if (! isset($seen[$key])) {
                        $merged[$section][] = $claim;
                        $seen[$key] = true;
                    }
                }
            }
        }

        return $merged;
    }
}
