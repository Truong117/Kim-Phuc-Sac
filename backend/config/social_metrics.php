<?php

return [
    'enabled' => (bool) env('SOCIAL_METRICS_ENABLED', false),
    'auto_sync' => (bool) env('SOCIAL_METRICS_AUTO_SYNC', false),
    'graph_version' => env('META_GRAPH_VERSION'),
    'app_secret' => env('META_APP_SECRET'),
    // Optional: enable only after checking this lifetime metric on the connected Page/version.
    'views_metric' => env('META_POST_VIEWS_METRIC'),
];
