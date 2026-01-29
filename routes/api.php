<?php

use Illuminate\Support\Facades\Route;
use Componist\MiniApi\Http\Controllers\MiniApiController;

Route::prefix('api')->group(function (): void {
    foreach (config('mini-api.endpoints', []) as $key => $endpoint) {
        $route = $endpoint['route'] ?? $key;
        Route::get($route, [MiniApiController::class, 'show'])
            ->defaults('endpoint', $key);
    }
});
