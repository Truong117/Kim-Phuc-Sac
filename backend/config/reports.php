<?php

return [
    'timezone' => env('REPORT_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'cutoff_time' => env('REPORT_CUTOFF_TIME', '23:00'),

    // ISO-8601 weekday numbers: Monday = 1, Sunday = 7.
    'working_days' => [1, 2, 3, 4, 5, 6],
];
