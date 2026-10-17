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

    /*
    | Globale Spalten-Blockliste (Export + Import-Filter).
    | Kein generisches `token`: zu viele fachliche Token (Public-Links, Transaktionen).
    | Geheimnisse pro Endpoint steuern (columns weglassen) oder hier nur echte Secrets.
    */
    'blocked_fields' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_code',
        'two_factor_expires_at',
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
    | Import (mini-api:import / componist:import-from-live):
    | zieht Daten per HTTP aus der Mini-API der Live-Anwendung.
    | Nur außerhalb von production nutzbar. Key getrennt vom eigenen MINI_API_KEY halten.
    |
    | table_order: bevorzugte Tabellen-Reihenfolge (Rest alphabetisch)
    | column_defaults: fehlende Spalten beim Schreiben (z. B. password → __hash:import-placeholder__)
    */
    'import' => [
        'url' => env('MINI_API_IMPORT_URL'),
        'key' => env('MINI_API_IMPORT_KEY'),
        'per_page' => (int) env('MINI_API_IMPORT_PER_PAGE', 500),
        // Pause zwischen Requests (ms), schützt vor throttle:60,1 auf älteren Live-Servern.
        'request_delay_ms' => (int) env('MINI_API_IMPORT_REQUEST_DELAY_MS', 1100),
        'table_order' => [],
        // Beispiele: 'users' => ['password' => '__hash:import-placeholder__'], 'links' => ['token' => '__random:40__']
        'column_defaults' => [],
    ],

    'builder' => [
        'enabled' => env('MINI_API_BUILDER_ENABLED', false),
        'only_dev' => env('MINI_API_BUILDER_ONLY_DEV', true),
        'route' => 'mini-api-builder',
    ],
];
