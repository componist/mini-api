<?php

namespace Componist\MiniApi;

use Componist\MiniApi\Console\GenerateMiniApiConfigFromDatabaseCommand;
use Componist\MiniApi\Console\GenerateMiniApiKeyCommand;
use Componist\MiniApi\Http\Controllers\MiniApiBuilderController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MiniApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mini-api.php',
            'mini-api'
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'mini-api');
        $this->registerCommands();
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->registerBuilderRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mini-api.php' => config_path('mini-api.php'),
            ], 'mini-api-config');
        }
    }

    protected function registerBuilderRoutes(): void
    {
        $enabled = config('mini-api.builder.enabled', false);
        $onlyDev = config('mini-api.builder.only_dev', true);
        if (! $enabled || ($onlyDev && ! config('app.debug'))) {
            return;
        }

        $prefix = config('mini-api.builder.route', 'mini-api-builder');
        Route::middleware('web')->prefix($prefix)->group(function () use ($prefix): void {
            Route::get('/', [MiniApiBuilderController::class, 'index'])->name('mini-api.builder');
            Route::get('/api/tables', [MiniApiBuilderController::class, 'tables']);
            Route::get('/api/tables/{table}/columns', [MiniApiBuilderController::class, 'columns']);
            Route::get('/api/models', [MiniApiBuilderController::class, 'models']);
            Route::get('/api/models/{model}/relations', [MiniApiBuilderController::class, 'relations']);
            Route::post('/api/config', [MiniApiBuilderController::class, 'storeConfig']);
        });
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateMiniApiKeyCommand::class,
                GenerateMiniApiConfigFromDatabaseCommand::class,
            ]);
        }
    }
}
