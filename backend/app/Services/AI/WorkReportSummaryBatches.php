<?php

namespace App\Services\AI;

final class WorkReportSummaryBatches
{
    public function plan(array $parts, array $period, ?string $feedback, int $missing, int $limit, int $perBatch): array
    {
        $batches = [];
        $results = [];
        $ids = [];
        $input = fn (array $values): array => ['period' => $period, 'parts' => $values,
            'review_feedback' => $feedback, 'missing_units' => $missing];
        $bytes = fn (array $values): int => strlen(json_encode($input($values), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $flush = function () use (&$batches, &$results, &$ids, $input): void {
            if ($results !== []) {
                $batches[] = ['input' => $input($results), 'source_unit_ids' => array_values(array_unique($ids))];
                $results = [];
                $ids = [];
            }
        };
        $append = function (int $id, array $result) use (&$results, &$ids, $bytes, $flush, $perBatch, $limit): void {
            if ($results !== [] && (count($results) >= $perBatch || $bytes([...$results, $result]) > $limit)) {
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
            // Partition at claim boundaries. Never truncate text or evidence.
            foreach (WorkReportAnalysisOutput::SECTIONS as $section) {
                foreach ($part['result'][$section] as $claim) {
                    $result = array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []);
                    $result[$section][] = $claim;
                    $append($part['id'], $result);
                }
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
