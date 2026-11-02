<?php

namespace App\Services\AI;

final class WorkReportExecutionPolicy
{
    // Two provider attempts of at most 120s, plus backoff and persistence.
    public const JOB_TIMEOUT = 270;

    public const LEASE_SECONDS = 420;

    public const QUEUE_RETRY_AFTER = 480;

    public static function outputTokens(array $settings, bool $summary): int
    {
        // Historical snapshots retain their original execution limits.
        return (int) ($settings[$summary ? 'summary_output_tokens' : 'employee_output_tokens']
            ?? $settings['gateway']['max_output_tokens']);
    }

    public static function summaryInputLimit(array $settings): int
    {
        $overhead = strlen(WorkReportAnalysisOutput::instructions())
            + strlen(json_encode(WorkReportAnalysisOutput::schema(), JSON_THROW_ON_ERROR)) + 256;

        return max(1, min(
            (int) ($settings['summary_input_bytes'] ?? $settings['unit_input_bytes']),
            (int) $settings['gateway']['max_input_bytes'] - $overhead,
            (int) $settings['gateway']['max_input_tokens'] - $overhead,
        ));
    }
}
