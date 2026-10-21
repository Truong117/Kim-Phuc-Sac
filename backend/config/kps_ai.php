<?php

return [
    'driver' => env('KPS_AI_DRIVER', 'fake'), // fake is the safe development default.
    'model' => env('KPS_AI_MODEL', 'gpt-4.1-mini'),
    'api_key' => env('OPENAI_API_KEY'),
    'max_reports' => 100,
    'max_items' => 300,
    'max_context_bytes' => 60000,
];
