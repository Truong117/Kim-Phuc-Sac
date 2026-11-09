<?php

$samplingEntryRaw = env('FACEBOOK_ANALYTICS_AUTO_SAMPLE_ENTRY_IDS', '');
$samplingEntryList = is_string($samplingEntryRaw) ? trim($samplingEntryRaw) : null;
$samplingEntryIds = $samplingEntryList === '' ? [] : [-1];
if (is_string($samplingEntryList) && $samplingEntryList !== '') {
    $samplingEntryParts = preg_split('/\s*,\s*/', $samplingEntryList);
    if (count(array_filter($samplingEntryParts, fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)) === count($samplingEntryParts)) {
        $samplingEntryIds = array_values(array_unique(array_map('intval', $samplingEntryParts)));
    }
}

return [
    'enabled' => (bool) env('FACEBOOK_ANALYTICS_ENABLED', false),
    'auto_sample_enabled' => (bool) env('FACEBOOK_ANALYTICS_AUTO_SAMPLE_ENABLED', false),
    'scheduler_mode' => env('FACEBOOK_ANALYTICS_SCHEDULER_MODE', 'off'),
    'auto_sample_entry_ids' => $samplingEntryIds,
    'ai_enabled' => (bool) env('FACEBOOK_ANALYTICS_AI_ENABLED', false),
    'lookback_days' => 90,
    'pages_per_job' => 2,
    'samples_per_job' => 10,
    'max_batches' => 600,
    'max_posts' => 5000,
    'max_ai_posts' => 30,
];
