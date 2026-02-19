<?php

use Illuminate\Support\Facades\Route;
use Componist\MiniApi\Http\Controllers\MiniApiController;

Route::prefix('api')->group(function (): void {
    $endpoints = config('mini-api.endpoints');
    if (! is_array($endpoints)) {
        return;
    }
    foreach ($endpoints as $key => $endpoint) {
        if (! is_array($endpoint)) {
            continue;
        }
        $route = $endpoint['route'] ?? $key;
        if ($route === '' || $route === null) {
            continue;
        }
        Route::get($route, [MiniApiController::class, 'show'])
            ->defaults('endpoint', $key);
    }
});
