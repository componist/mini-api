<?php

declare(strict_types=1);

namespace Componist\MiniApi\Console;

use Componist\MiniApi\Application\MiniApiImportService;
use Componist\MiniApi\Domain\MiniApiImportException;
use Componist\MiniApi\Domain\MiniApiImportMode;
use Componist\MiniApi\Infrastructure\MiniApiRemoteClient;
use Illuminate\Console\Command;

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
                            {--force : Bestätigung im Fresh-Modus überspringen}';

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
            $this->error('URL und API-Key der Live-Anwendung fehlen (MINI_API_IMPORT_URL / MINI_API_IMPORT_KEY oder --url / --key).');

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
        $requested = (array) $this->argument('endpoint');

        if ($requested === []) {
            $requested = $available === [] ? [] : multiselect(
                'Welche Endpoints sollen importiert werden?',
                array_map(fn (array $config): string => (string) $config['table'], $available),
                required: true,
            );
        }

        foreach (array_diff($requested, array_keys($available)) as $unknown) {
            $this->warn("Endpoint '{$unknown}' übersprungen: nicht vorhanden oder kein Tabellen-Endpoint.");
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
        $failed = false;

        foreach ($endpoints as $key => $config) {
            try {
                $result = $this->importService->import((string) ($config['route'] ?? $key), $config, $mode, $client);
                $this->info("{$result->table}: {$result->rows} Zeilen verarbeitet.");
                if ($result->skippedColumns !== []) {
                    $this->warn('  Übersprungene Spalten (lokal nicht vorhanden oder gesperrt): '.implode(', ', $result->skippedColumns));
                }
            } catch (MiniApiImportException $exception) {
                $failed = true;
                $this->error("{$key}: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
