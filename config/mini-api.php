<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API-Key Auth
    |--------------------------------------------------------------------------
    | Bei enabled=true muss MINI_API_KEY gesetzt sein – sonst 401.
    */
    'auth' => [
        'enabled' => env('MINI_API_AUTH_ENABLED', true),
        'key' => env('MINI_API_KEY'),
        'header' => 'X-Api-Key',
        'query' => 'api_key',
    ],

    'max_rows' => (int) env('MINI_API_MAX_ROWS', 500),

    'blocked_tables' => [
        'password_reset_tokens',
        'sessions',
        'personal_access_tokens',
        'migrations',
        'failed_jobs',
        'job_batches',
        'jobs',
        'cache',
        'cache_locks',
        'apps',
        'users_apps',
        'user_auth_tokens',
        'database_connectings',
    ],

    'blocked_fields' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_code',
        'two_factor_expires_at',
        'token',
        'auth_token',
        'secret_token',
        'database_password',
    ],

    /*
    | Endpoints: immer explizite columns setzen (kein *).
    | Demo-Endpoint absichtlich leer – in Root-Config pflegen.
    */
    'endpoints' => [],

    /*
    | Import (mini-api:import): zieht Daten per HTTP aus der Mini-API der Live-Anwendung.
    | Nur außerhalb von production nutzbar. Key getrennt vom eigenen MINI_API_KEY halten.
    */
    'import' => [
        'url' => env('MINI_API_IMPORT_URL'),
        'key' => env('MINI_API_IMPORT_KEY'),
        'per_page' => (int) env('MINI_API_IMPORT_PER_PAGE', 500),
    ],

    'builder' => [
        'enabled' => env('MINI_API_BUILDER_ENABLED', false),
        'only_dev' => env('MINI_API_BUILDER_ONLY_DEV', true),
        'route' => 'mini-api-builder',
    ],
];
