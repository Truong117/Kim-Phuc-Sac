<?php

return [
    'driver' => env('KPS_AI_DRIVER', 'fake'), // fake is the safe development default.
    'model' => env('KPS_AI_MODEL', 'gpt-4.1-mini'),
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'monthly_budget_usd' => (float) env('KPS_AI_MONTHLY_BUDGET_USD', 0),
    'input_usd_per_million_tokens' => (float) env('KPS_AI_INPUT_USD_PER_MILLION_TOKENS', 0),
    'output_usd_per_million_tokens' => (float) env('KPS_AI_OUTPUT_USD_PER_MILLION_TOKENS', 0),
    'timeout_seconds' => (int) env('KPS_AI_TIMEOUT_SECONDS', 60),
    'connect_timeout_seconds' => (int) env('KPS_AI_CONNECT_TIMEOUT_SECONDS', 10),
    'max_attempts' => (int) env('KPS_AI_MAX_ATTEMPTS', 2),
    'retry_delay_ms' => (int) env('KPS_AI_RETRY_DELAY_MS', 250),
    'max_retry_delay_ms' => (int) env('KPS_AI_MAX_RETRY_DELAY_MS', 2000),
    'stale_after_seconds' => (int) env('KPS_AI_STALE_AFTER_SECONDS', 180),
    'max_input_bytes' => (int) env('KPS_AI_MAX_INPUT_BYTES', 65000),
    'max_input_tokens' => (int) env('KPS_AI_MAX_INPUT_TOKENS', 65000),
    'max_output_tokens' => (int) env('KPS_AI_MAX_OUTPUT_TOKENS', 3000),
    'max_reports' => 100,
    'max_items' => 300,
    'max_context_bytes' => 60000,
];
