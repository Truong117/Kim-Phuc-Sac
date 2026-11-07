<?php

return [
    'enabled' => (bool) env('FACEBOOK_ANALYTICS_ENABLED', false),
    'ai_enabled' => (bool) env('FACEBOOK_ANALYTICS_AI_ENABLED', false),
    'lookback_days' => 90,
    'pages_per_job' => 2,
    'samples_per_job' => 10,
    'max_posts' => 5000,
    'max_ai_posts' => 30,
];
