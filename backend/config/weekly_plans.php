<?php

return [
    'timezone' => env('WEEKLY_PLAN_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'storage_disk' => env('WEEKLY_PLAN_STORAGE_DISK', 'local'),
    'max_file_kb' => (int) env('WEEKLY_PLAN_MAX_FILE_KB', 5120),
    'max_archive_entries' => (int) env('WEEKLY_PLAN_MAX_ARCHIVE_ENTRIES', 250),
    'max_uncompressed_bytes' => (int) env('WEEKLY_PLAN_MAX_UNCOMPRESSED_BYTES', 52428800),
    'max_compression_ratio' => (int) env('WEEKLY_PLAN_MAX_COMPRESSION_RATIO', 100),
    'max_rows' => (int) env('WEEKLY_PLAN_MAX_ROWS', 1000),
    'max_sheets' => (int) env('WEEKLY_PLAN_MAX_SHEETS', 10),
];
