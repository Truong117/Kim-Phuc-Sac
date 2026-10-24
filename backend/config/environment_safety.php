<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Environment safety checks
    |--------------------------------------------------------------------------
    |
    | Runtime validation is mandatory. Only narrowly scoped, data-free bootstrap
    | commands may defer validation until their isolated configuration exists.
    |
    */
    'database_environment' => env('KPS_DATABASE_ENVIRONMENT'),
    'staging_ephemeral_files' => (bool) env('KPS_STAGING_EPHEMERAL_FILES', false),

];
