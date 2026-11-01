<?php

return [
    'timezone' => env('WEEKLY_PLAN_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    // Schema and model support may land before V3 business behavior. Keep all
    // creation and mutation paths on V2 until a later rollout explicitly opts in.
    'workflow_v3_enabled' => (bool) env('WEEKLY_PLAN_WORKFLOW_V3_ENABLED', false),
    'v3_review_max_decisions_per_batch' => (int) env(
        'WEEKLY_PLAN_V3_REVIEW_MAX_DECISIONS_PER_BATCH',
        100,
    ),
    'v3_review_max_employee_snapshot_bytes' => (int) env(
        'WEEKLY_PLAN_V3_REVIEW_MAX_EMPLOYEE_SNAPSHOT_BYTES',
        524288,
    ),
    'v3_review_max_batch_snapshot_bytes' => (int) env(
        'WEEKLY_PLAN_V3_REVIEW_MAX_BATCH_SNAPSHOT_BYTES',
        2097152,
    ),
    'storage_disk' => env('WEEKLY_PLAN_STORAGE_DISK', 'local'),
    'max_file_kb' => (int) env('WEEKLY_PLAN_MAX_FILE_KB', 5120),
    'max_archive_entries' => (int) env('WEEKLY_PLAN_MAX_ARCHIVE_ENTRIES', 250),
    'max_uncompressed_bytes' => (int) env('WEEKLY_PLAN_MAX_UNCOMPRESSED_BYTES', 52428800),
    'max_compression_ratio' => (int) env('WEEKLY_PLAN_MAX_COMPRESSION_RATIO', 100),
    'max_rows' => (int) env('WEEKLY_PLAN_MAX_ROWS', 1000),
    'max_sheets' => (int) env('WEEKLY_PLAN_MAX_SHEETS', 10),
    'max_source_passages' => (int) env('WEEKLY_PLAN_MAX_SOURCE_PASSAGES', 500),
    'max_source_characters' => (int) env('WEEKLY_PLAN_MAX_SOURCE_CHARACTERS', 200000),
    'max_source_passage_characters' => (int) env('WEEKLY_PLAN_MAX_SOURCE_PASSAGE_CHARACTERS', 10000),
    'max_source_passages_per_entry' => (int) env('WEEKLY_PLAN_MAX_SOURCE_PASSAGES_PER_ENTRY', 20),
    'max_source_entries_per_request' => (int) env('WEEKLY_PLAN_MAX_SOURCE_ENTRIES_PER_REQUEST', 20),
    'document_ai_driver' => env('WEEKLY_PLAN_DOCUMENT_AI_DRIVER', 'none'),
    'ai_max_passages' => (int) env('WEEKLY_PLAN_AI_MAX_PASSAGES', 200),
    'ai_max_context_bytes' => (int) env('WEEKLY_PLAN_AI_MAX_CONTEXT_BYTES', 60000),
    // AI response safety boundary. Runtime validation also caps this at
    // max_source_entries_per_request so one preview always fits one atomic apply.
    'ai_max_tasks_per_response' => (int) env('WEEKLY_PLAN_AI_MAX_TASKS_PER_RESPONSE', 20),
    // Nested planning items use the existing entry text conventions:
    // title <= 255 characters and nullable content <= 10,000 characters.
    'ai_max_items_per_task' => (int) env('WEEKLY_PLAN_AI_MAX_ITEMS_PER_TASK', 20),
    'ai_max_total_items_per_response' => (int) env('WEEKLY_PLAN_AI_MAX_TOTAL_ITEMS_PER_RESPONSE', 100),
    'ai_max_output_tokens' => (int) env('WEEKLY_PLAN_AI_MAX_OUTPUT_TOKENS', 3000),
    'ai_reasoning_effort' => env('WEEKLY_PLAN_AI_REASONING_EFFORT'),
];
