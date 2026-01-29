<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API-Key Auth (optional)
    |--------------------------------------------------------------------------
    | Wenn enabled und key gesetzt: nur bei korrektem Key werden Daten ausgegeben.
    | Key per: php artisan mini-api:generate-key (schreibt in .env)
    */
    'auth' => [
        'enabled' => env('MINI_API_AUTH_ENABLED', false),
        'key' => env('MINI_API_KEY'),
        'header' => 'X-Api-Key',
        'query' => 'api_key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    | Jeder Eintrag = eine API-Route. route = URL-Pfad (ohne api/), table oder model,
    | columns = Spalten, optional relations (Eloquent with oder Joins).
    |
    | Beispiel mit Tabelle (Query Builder):
    |   'users' => [
    |       'route'   => 'users',
    |       'table'   => 'users',
    |       'columns' => ['id', 'name', 'email'],
    |   ],
    |
    | Beispiel mit Model (Eloquent + Relationen):
    |   'job_offers' => [
    |       'route'     => 'job-offers',
    |       'model'     => \App\Models\JobOffer::class,
    |       'columns'   => ['id', 'title', 'slug'],
    |       'relations' => ['company', 'company.country'],
    |   ],
    */
    'endpoints' => [
        'users' => [
            'route' => 'users',
            'table' => 'users',
            // 'columns' => ['id', 'name', 'email', 'created_at'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Config-Builder (optional, nur Dev)
    |--------------------------------------------------------------------------
    */
    'builder' => [
        'enabled' => env('MINI_API_BUILDER_ENABLED', false),
        'only_dev' => env('MINI_API_BUILDER_ONLY_DEV', true),
        'route' => 'mini-api-builder',
    ],
];
