<?php

return [
    'enabled' => (bool) env('DAILY_REPORT_AI_ENABLED', false),
    // This milestone supports fixture processing only; paid provider integration follows separately.
    'driver' => env('DAILY_REPORT_AI_DRIVER', 'fake'),
    'model' => 'gpt-6-luna',
    'queue_connection' => env('DAILY_REPORT_AI_QUEUE_CONNECTION', 'daily_report_ai'),
    'unit_bytes' => 16000,
    'max_snapshot_bytes' => 1000000,
    'max_parts' => 100,
    'max_attempts' => 2,
];
