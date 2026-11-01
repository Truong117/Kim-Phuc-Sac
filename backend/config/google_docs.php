<?php

return [
    'application_name' => 'KPS Internal System Google Docs Reader',
    'credentials_path' => env('GOOGLE_SERVICE_ACCOUNT_CREDENTIALS_PATH'),
    'credentials_max_bytes' => (int) env('GOOGLE_SERVICE_ACCOUNT_CREDENTIALS_MAX_BYTES', 65536),
    'scope' => 'https://www.googleapis.com/auth/documents.readonly',
    'drive_metadata_scope' => 'https://www.googleapis.com/auth/drive.metadata.readonly',
    'timeout_seconds' => (int) env('GOOGLE_DOCS_TIMEOUT_SECONDS', 15),
    'max_response_bytes' => (int) env('GOOGLE_DOCS_MAX_RESPONSE_BYTES', 5242880),
    'metadata_max_response_bytes' => (int) env('GOOGLE_DRIVE_METADATA_MAX_RESPONSE_BYTES', 65536),
    'max_tabs' => (int) env('GOOGLE_DOCS_MAX_TABS', 100),
    'max_nesting_depth' => (int) env('GOOGLE_DOCS_MAX_NESTING_DEPTH', 10),
    'max_passages' => (int) env('GOOGLE_DOCS_MAX_PASSAGES', 500),
    'max_characters' => (int) env('GOOGLE_DOCS_MAX_CHARACTERS', 200000),
    'max_passage_characters' => (int) env('GOOGLE_DOCS_MAX_PASSAGE_CHARACTERS', 10000),
    'preview_per_minute' => (int) env('GOOGLE_DOCS_PREVIEW_PER_MINUTE', 4),
];
