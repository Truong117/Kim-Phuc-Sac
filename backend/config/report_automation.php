<?php

return [
    // Separate generation and delivery switches permit a generation-only rollout.
    'enabled' => (bool) env('REPORT_AUTOMATION_ENABLED', false),
    'mail_enabled' => (bool) env('REPORT_AUTOMATION_MAIL_ENABLED', false),
    'personal_weekly_mail_enabled' => (bool) env('REPORT_PERSONAL_WEEKLY_MAIL_ENABLED', false),
    'personal_weekly_mail_start_date' => env('REPORT_PERSONAL_WEEKLY_MAIL_START_DATE'),
    'scheduler_mode' => env('REPORT_AUTOMATION_SCHEDULER_MODE', 'off'),
    'actor_user_id' => (int) env('REPORT_AUTOMATION_ACTOR_USER_ID', 0),
    // Explicit activation boundary: never surprise operators with historical mail.
    'start_date' => env('REPORT_AUTOMATION_START_DATE'),
    'recipient_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('REPORT_AUTOMATION_RECIPIENT_USER_IDS', ''))))),
    'max_cycles_per_tick' => 14,
];
