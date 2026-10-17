<?php

declare(strict_types=1);

namespace Componist\MiniApi\Console;

use Componist\MiniApi\Application\MiniApiImportService;
use Componist\MiniApi\Domain\MiniApiImportException;
use Componist\MiniApi\Domain\MiniApiImportMode;
use Componist\MiniApi\Infrastructure\MiniApiRemoteClient;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class ImportMiniApiDataCommand extends Command
{
    protected $signature = 'mini-api:import
                            {endpoint?* : Endpoint-Keys aus config/mini-api.php (ohne Angabe: Auswahl)}
                            {--url= : Basis-URL der Live-Anwendung (Standard: mini-api.import.url)}
                            {--key= : API-Key der Live-Anwendung (Standard: mini-api.import.key)}
                            {--mode= : fresh oder update}
                            {--per-page= : Zeilen pro Request}
                            {--force : Bestätigung im Fresh-Modus überspringen}
                            {--fail-fast : Beim ersten Fehler abbrechen (Standard: weiter mit nächstem Endpoint)}';

    protected $description = 'Importiert Daten der Live-Anwendung über deren Mini-API anhand der lokalen Endpoint-Config';

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

        $endpoints = $this->resolveEndpoints();
        $mode = $this->resolveMode();
        if ($endpoints === [] || $mode === null || ! $this->confirmFresh($mode, $endpoints)) {
            $this->warn('Import abgebrochen.');

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
            $this->line('  Setze MINI_API_IMPORT_URL / MINI_API_IMPORT_KEY in der .env oder nutze --url / --key.');

            return null;
        }

        return new MiniApiRemoteClient(
            $url,
            $key,
            (int) ($this->option('per-page') ?: config('mini-api.import.per_page', 500)),
            (string) config('mini-api.auth.header', 'X-Api-Key'),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveEndpoints(): array
    {
        $available = $this->importableEndpoints();
        if ($available === []) {
            $this->error('Keine importierbaren Endpoints in config/mini-api.php.');
            $this->line('  Nur Einträge mit `table` (ohne `model`) werden importiert.');

            return [];
        }

        $requested = array_values(array_filter(
            (array) $this->argument('endpoint'),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        if ($requested === []) {
            $options = [];
            foreach ($available as $key => $config) {
                $options[$key] = $key.' → '.(string) $config['table'];
            }

            $requested = multiselect(
                'Welche Endpoints sollen importiert werden?',
                $options,
                required: true,
            );
        }

        foreach (array_diff($requested, array_keys($available)) as $unknown) {
            $this->warn("  ⚠ Endpoint '{$unknown}' übersprungen: nicht vorhanden oder kein Tabellen-Endpoint.");
        }

        return array_intersect_key($available, array_flip($requested));
    }

    /**
     * Model-Endpoints lassen sich nicht 1:1 einer Tabelle zuordnen.
     *
     * @return array<string, array<string, mixed>>
     */
    private function importableEndpoints(): array
    {
        return array_filter(
            (array) config('mini-api.endpoints', []),
            fn (mixed $config): bool => is_array($config) && isset($config['table']) && ! isset($config['model']),
        );
    }

    private function resolveMode(): ?MiniApiImportMode
    {
        $option = $this->option('mode');
        if ($option === null) {
            return MiniApiImportMode::from(select(
                'Wie soll importiert werden?',
                collect(MiniApiImportMode::cases())
                    ->mapWithKeys(fn (MiniApiImportMode $mode): array => [$mode->value => $mode->label()])
                    ->all(),
                default: MiniApiImportMode::Update->value,
            ));
        }

        $mode = MiniApiImportMode::tryFrom((string) $option);
        if ($mode === null) {
            $this->error("Ungültiger Modus '{$option}'. Erlaubt: fresh, update.");
        }

        return $mode;
    }

    /**
     * @param  array<string, array<string, mixed>>  $endpoints
     */
    private function confirmFresh(MiniApiImportMode $mode, array $endpoints): bool
    {
        if ($mode !== MiniApiImportMode::Fresh || $this->option('force')) {
            return true;
        }

        $tables = implode(', ', array_column($endpoints, 'table'));

        return confirm("Fresh löscht den kompletten Inhalt von: {$tables}. Fortfahren?", default: false);
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

        $this->info("Starte Mini-API-Import ({$total} Endpoints, Modus: {$mode->value})...");
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
                    $this->warn('  ⚠ Übersprungene Spalten (lokal fehlend/gesperrt): '.implode(', ', $result->skippedColumns));
                }
            } catch (MiniApiImportException $exception) {
                $failed[$key] = $exception;
                $this->renderFailure($exception);

                if ($this->option('fail-fast')) {
                    $this->warn('  Abbruch (--fail-fast).');
                    break;
                }
            } catch (Throwable $throwable) {
                $exception = MiniApiImportException::fromThrowable($throwable, $table, $route);
                $failed[$key] = $exception;
                $this->renderFailure($exception);
                report($throwable);

                if ($this->option('fail-fast')) {
                    $this->warn('  Abbruch (--fail-fast).');
                    break;
                }
            }
        }

        return $this->renderSummary($ok, $failed, $rowsTotal, $total);
    }

    private function renderFailure(MiniApiImportException $exception): void
    {
        $this->error('  ✗ '.$exception->getMessage());
        if ($exception->hint !== null && $exception->hint !== '') {
            $this->line('    → '.$exception->hint);
        }
    }

    /**
     * @param  array<string, MiniApiImportException>  $failed
     */
    private function renderSummary(int $ok, array $failed, int $rowsTotal, int $total): int
    {
        $this->newLine();

        if ($failed === []) {
            $this->info("Import abgeschlossen: {$ok}/{$total} Endpoints ok, {$rowsTotal} Datensätze.");

            return self::SUCCESS;
        }

        $failCount = count($failed);
        $this->warn("Import mit Fehlern: {$ok}/{$total} ok, {$failCount} fehlgeschlagen, {$rowsTotal} Datensätze übernommen.");
        $this->line('Fehlgeschlagen:');
        foreach ($failed as $key => $exception) {
            $this->line("  • {$key}: ".$exception->getMessage());
        }

        if (! $this->option('fail-fast') && $ok > 0) {
            $this->line('Hinweis: Erfolgreiche Endpoints bleiben erhalten. Fehlende erneut mit z. B. `php artisan mini-api:import <key> --mode=…` nachziehen.');
        }

        return self::FAILURE;
    }
}
