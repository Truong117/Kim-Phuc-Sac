<?php

use App\Services\AI\WorkReportExecutionPolicy;

return [
    'enabled' => (bool) env('WORK_REPORT_AI_ENABLED', false),
    'driver' => env('WORK_REPORT_AI_DRIVER', 'fake'),
    'queue_connection' => env('WORK_REPORT_AI_QUEUE_CONNECTION'),
    'max_days_back' => (int) env('WORK_REPORT_AI_MAX_DAYS_BACK', 365),
    'max_reports' => (int) env('WORK_REPORT_AI_MAX_REPORTS', 3000),
    'max_snapshot_bytes' => (int) env('WORK_REPORT_AI_MAX_SNAPSHOT_BYTES', 1_000_000),
    'unit_input_bytes' => (int) env('WORK_REPORT_AI_UNIT_INPUT_BYTES', 24000),
    'use_daily_documents' => (bool) env('WORK_REPORT_AI_USE_DAILY_DOCUMENTS', false),
    'summary_strategy' => 'batched-v1',
    'work_inventory_version' => 'source-inventory-v1',
    'summary_input_bytes' => (int) env('WORK_REPORT_AI_SUMMARY_INPUT_BYTES', 50000),
    'summary_parts_per_batch' => (int) env('WORK_REPORT_AI_SUMMARY_PARTS_PER_BATCH', 2),
    'employee_output_tokens' => (int) env('WORK_REPORT_AI_EMPLOYEE_OUTPUT_TOKENS', 6000),
    'summary_output_tokens' => (int) env('WORK_REPORT_AI_SUMMARY_OUTPUT_TOKENS', 16000),
    'max_units' => (int) env('WORK_REPORT_AI_MAX_UNITS', 200),
    'unit_attempts' => (int) env('WORK_REPORT_AI_UNIT_ATTEMPTS', 2),
    'lease_seconds' => WorkReportExecutionPolicy::LEASE_SECONDS,
    'max_run_budget_usd' => (float) env('WORK_REPORT_AI_MAX_RUN_BUDGET_USD', 2),
    // Same internal OpenAI Gateway, with isolated feature settings. Never mutate kps_ai/Weekly Planning.
    'gateway' => [
        'model' => env('WORK_REPORT_AI_MODEL', env('KPS_AI_MODEL', 'gpt-4.1-mini')),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'monthly_budget_usd' => (float) env('WORK_REPORT_AI_MONTHLY_BUDGET_USD', 0),
        'input_usd_per_million_tokens' => (float) env('WORK_REPORT_AI_INPUT_USD_PER_MILLION_TOKENS', 0),
        'output_usd_per_million_tokens' => (float) env('WORK_REPORT_AI_OUTPUT_USD_PER_MILLION_TOKENS', 0),
        'timeout_seconds' => 120, 'connect_timeout_seconds' => 10,
        'max_attempts' => 2, 'retry_delay_ms' => 250, 'max_retry_delay_ms' => 2000,
        'stale_after_seconds' => 300, 'max_input_bytes' => 65000,
        'max_input_tokens' => 65000, 'max_output_tokens' => 32000,
    ],
];
