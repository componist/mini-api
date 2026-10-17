<?php

declare(strict_types=1);

namespace Componist\MiniApi\Console;

use Componist\MiniApi\Application\MiniApiImportService;
use Componist\MiniApi\Domain\MiniApiImportException;
use Componist\MiniApi\Domain\MiniApiImportMode;
use Componist\MiniApi\Infrastructure\MiniApiRemoteClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Universeller, nicht-interaktiver Import aller Tabellen-Endpoints von einer Live-Instanz.
 */
class ImportFromLiveCommand extends Command
{
    protected $signature = 'componist:import-from-live
                            {--url= : Basis-URL der Live-Anwendung (Standard: mini-api.import.url)}
                            {--key= : API-Key der Live-Anwendung (Standard: mini-api.import.key)}
                            {--mode=fresh : fresh oder update}
                            {--per-page= : Zeilen pro Request}
                            {--fail-fast : Beim ersten Fehler abbrechen}';

    protected $description = 'Universeller Mini-API-Import: alle Tabellen von Live holen (Continue-on-Error)';

    public function __construct(private readonly MiniApiImportService $importService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Import ist in der Produktionsumgebung gesperrt.');

            return self::FAILURE;
        }

        $client = $this->makeClient();
        if ($client === null) {
            return self::FAILURE;
        }

        $mode = MiniApiImportMode::tryFrom((string) $this->option('mode'));
        if ($mode === null) {
            $this->error("Ungültiger Modus '{$this->option('mode')}'. Erlaubt: fresh, update.");

            return self::FAILURE;
        }

        $endpoints = $this->resolveOrderedEndpoints();
        if ($endpoints === []) {
            $this->error('Keine importierbaren Endpoints in config/mini-api.php (nur table-Endpoints).');

            return self::FAILURE;
        }

        return $this->runImport($endpoints, $mode, $client);
    }

    private function makeClient(): ?MiniApiRemoteClient
    {
        $url = (string) ($this->option('url') ?: config('mini-api.import.url'));
        $key = (string) ($this->option('key') ?: config('mini-api.import.key'));

        if ($url === '' || $key === '') {
            $this->error('URL und API-Key der Live-Anwendung fehlen.');
            $this->line('  Setze MINI_API_IMPORT_URL / MINI_API_IMPORT_KEY oder --url / --key.');

            return null;
        }

        return new MiniApiRemoteClient(
            $url,
            $key,
            (int) ($this->option('per-page') ?: config('mini-api.import.per_page', 500)),
            (string) config('mini-api.auth.header', 'X-Api-Key'),
            max(0, (int) config('mini-api.import.request_delay_ms', 1100)),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveOrderedEndpoints(): array
    {
        /** @var array<string, array<string, mixed>> $available */
        $available = array_filter(
            (array) config('mini-api.endpoints', []),
            fn (mixed $config): bool => is_array($config) && isset($config['table']) && ! isset($config['model']),
        );

        if ($available === []) {
            return [];
        }

        $byTable = [];
        foreach ($available as $key => $config) {
            $byTable[(string) $config['table']] = [$key, $config];
        }

        $ordered = [];
        /** @var list<string> $preferred */
        $preferred = array_values(array_filter(
            (array) config('mini-api.import.table_order', []),
            static fn (mixed $table): bool => is_string($table) && $table !== '',
        ));

        foreach ($preferred as $table) {
            if (! isset($byTable[$table])) {
                continue;
            }

            [$key, $config] = $byTable[$table];
            $ordered[$key] = $config;
            unset($byTable[$table]);
        }

        ksort($byTable);
        foreach ($byTable as [$key, $config]) {
            $ordered[$key] = $config;
        }

        return $ordered;
    }

    /**
     * @param  array<string, array<string, mixed>>  $endpoints
     */
    private function runImport(array $endpoints, MiniApiImportMode $mode, MiniApiRemoteClient $client): int
    {
        $total = count($endpoints);
        $ok = 0;
        $failed = [];
        $rowsTotal = 0;

        $this->info("Starte Import von Live ({$total} Endpoints, Modus: {$mode->value})...");
        $this->line('Basis: '.$client->baseUrl());
        $this->newLine();

        foreach ($endpoints as $key => $config) {
            $route = (string) ($config['route'] ?? $key);
            $table = (string) ($config['table'] ?? $key);
            $this->line("▶ {$route} → {$table}...");

            try {
                $result = $this->importService->import($route, $config, $mode, $client);
                $ok++;
                $rowsTotal += $result->rows;
                $this->info("  ✓ {$result->rows} Datensätze.");

                if ($result->skippedColumns !== []) {
                    $this->line('  ⚠ Übersprungene Spalten: '.implode(', ', $result->skippedColumns));
                }
            } catch (MiniApiImportException $exception) {
                $failed[$key] = $exception;
                $this->error('  ✗ '.$exception->getMessage());
                if ($exception->hint) {
                    $this->line('    → '.$exception->hint);
                }

                if ($this->option('fail-fast')) {
                    $this->line('  Abbruch (--fail-fast).');
                    break;
                }
            } catch (Throwable $throwable) {
                $exception = MiniApiImportException::fromThrowable($throwable, $table, $route);
                $failed[$key] = $exception;
                $this->error('  ✗ '.$exception->getMessage());
                if ($exception->hint) {
                    $this->line('    → '.$exception->hint);
                }
                report($throwable);

                if ($this->option('fail-fast')) {
                    $this->line('  Abbruch (--fail-fast).');
                    break;
                }
            }
        }

        $this->newLine();

        if ($failed === []) {
            $this->info("Import abgeschlossen: {$ok}/{$total} Endpoints ok, {$rowsTotal} Datensätze.");

            return self::SUCCESS;
        }

        $this->error('Import mit Fehlern: '.$ok.'/'.$total.' ok, '.count($failed).' fehlgeschlagen, '.$rowsTotal.' Datensätze übernommen.');
        foreach ($failed as $key => $exception) {
            $this->line("  • {$key}: ".$exception->getMessage());
        }
        $this->line('Hinweis: Erfolgreiche Endpoints bleiben erhalten. Bei HTTP 429 kurz warten und erneut ausführen.');

        return self::FAILURE;
    }
}
