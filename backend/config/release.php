<?php

return [
    'revision' => env('RENDER_GIT_COMMIT', env('KPS_RELEASE_SHA')),
    'environment' => env('KPS_DATABASE_ENVIRONMENT', 'unknown'),
];
