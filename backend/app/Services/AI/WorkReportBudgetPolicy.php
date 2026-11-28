<?php

namespace App\Services\AI;

final class WorkReportBudgetPolicy
{
    public static function gateway(array $settings): array
    {
        // Sources, model, prices and output contract stay frozen. The current
        // operator's monthly cap must govern old and new calls, including reductions.
        return [...$settings['gateway'],
            'monthly_budget_usd' => (float) config('work_report_ai.gateway.monthly_budget_usd', 0),
            'api_key' => config('work_report_ai.gateway.api_key')];
    }

    public static function contentSettings(array $settings): array
    {
        unset($settings['gateway']['monthly_budget_usd']);

        return $settings;
    }

    public static function reservation(array $settings, bool $summary): int
    {
        $gateway = $settings['gateway'];

        return (int) ceil(($gateway['max_input_tokens'] * $gateway['input_usd_per_million_tokens']
            + WorkReportExecutionPolicy::outputTokens($settings, $summary) * $gateway['output_usd_per_million_tokens'])
            * $gateway['max_attempts']);
    }

    public static function noExecution(string $code): bool
    {
        return in_array($code, ['AI_BUDGET_EXCEEDED', 'AI_RUN_BUDGET_EXCEEDED', 'AI_NO_VALID_PARTS', 'AI_NOT_CONFIGURED'], true);
    }
}
