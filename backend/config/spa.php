<?php

return [
    // Enable only after the catalog migration has been applied to this environment.
    'catalog_enabled' => env('SPA_CATALOG_ENABLED', false),
    'booking_enabled' => env('SPA_BOOKING_ENABLED', false),
];
