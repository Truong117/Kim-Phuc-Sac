<?php

return [
    'enabled' => (bool) env('DAILY_REPORT_AI_ENABLED', false),
    'driver' => env('DAILY_REPORT_AI_DRIVER', 'fake'),
    'model' => 'gpt-6-luna',
    'queue_connection' => env('DAILY_REPORT_AI_QUEUE_CONNECTION', 'daily_report_ai'),
    'auto_dispatch' => (bool) env('DAILY_REPORT_AI_AUTO_DISPATCH', false),
    'dispatch_user_id' => (int) env('DAILY_REPORT_AI_DISPATCH_USER_ID', 0),
    'max_document_budget_usd' => (float) env('DAILY_REPORT_AI_MAX_DOCUMENT_BUDGET_USD', 1),
    // A ceiling on the EXISTING environment/provider monthly pool, not a separate allowance.
    'gateway' => [
        'model' => 'gpt-6-luna', 'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'monthly_budget_usd' => (float) env('DAILY_REPORT_AI_MONTHLY_BUDGET_USD', 0),
        'input_usd_per_million_tokens' => (float) env('DAILY_REPORT_AI_INPUT_USD_PER_MILLION_TOKENS', 0),
        'output_usd_per_million_tokens' => (float) env('DAILY_REPORT_AI_OUTPUT_USD_PER_MILLION_TOKENS', 0),
        'max_input_bytes' => 65000, 'max_input_tokens' => 65000,
        'max_output_tokens' => min(32000, max(1000, (int) env('DAILY_REPORT_AI_OUTPUT_TOKENS', 16000))), 'max_attempts' => 1,
        'timeout_seconds' => 120, 'connect_timeout_seconds' => 10, 'stale_after_seconds' => 480,
    ],
    'unit_bytes' => 16000,
    'max_snapshot_bytes' => 1000000,
    'max_parts' => 100,
    'max_attempts' => 2,
];
