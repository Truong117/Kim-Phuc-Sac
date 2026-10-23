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

    /*
    | Local development may only use a non-loopback database after an explicit
    | acknowledgement. This flag must remain false for ordinary local setup.
    */
    'allow_remote_database' => env('KPS_ALLOW_REMOTE_DATABASE', false) === true,

    'expected_database_host' => env('KPS_EXPECTED_DATABASE_HOST'),

    'expected_database_name' => env('KPS_EXPECTED_DATABASE_NAME'),
];
