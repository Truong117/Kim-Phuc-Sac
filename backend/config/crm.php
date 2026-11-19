<?php

return [
    // The first rollout is on staging. Production is enabled explicitly after acceptance.
    'enabled' => env('CRM_ENABLED', env('APP_ENV') === 'staging'),
];
