<?php

namespace Componist\MiniApi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenerateMiniApiConfigFromDatabaseCommand extends Command
{
    protected $signature = 'mini-api:config-from-database
                            {--exclude= : Tabellen ausschließen (kommasepariert, z. B. migrations,sessions)}
                            {--columns=list : Spalten pro Tabelle: list = alle Spalten, all = [\'*\']}';

    protected $description = 'Erzeugt config/mini-api.php Endpoints aus allen Tabellen der aktuellen Datenbank';

    public function handle(): int
    {
        $configPath = config_path('mini-api.php');
        if (! file_exists($configPath)) {
            $this->error('config/mini-api.php nicht gefunden. Bitte zuerst ausführen: php artisan vendor:publish --tag=mini-api-config');
            return self::FAILURE;
        }

        $exclude = $this->option('exclude');
        $excludeTables = is_array($exclude) ? $exclude : array_filter(array_map('trim', explode(',', (string) $exclude)));
        $columnsMode = $this->option('columns') === 'all' ? 'all' : 'list';

        $tables = $this->getDatabaseTableNamesOnly();
        $tables = array_values(array_diff($tables, $excludeTables));

        if (empty($tables)) {
            $this->warn('Keine Tabellen gefunden (oder alle ausgeschlossen).');
            return self::SUCCESS;
        }

        $endpoints = [];
        foreach ($tables as $table) {
            $key = Str::slug($table, '_');
            $route = Str::slug($table, '-');
            $columns = $columnsMode === 'all' ? ['*'] : array_values(array_unique((array) Schema::getColumnListing($table)));
            $endpoints[] = [
                'key' => $key,
                'route' => $route,
                'table' => $table,
                'columns' => $columns,
            ];
        }

        $content = file_get_contents($configPath);
        $newBlock = $this->buildEndpointsPhp($endpoints);

        $posEndpoints = strpos($content, "'endpoints' => [");
        if ($posEndpoints === false) {
            $this->error('Config-Struktur unerwartet: \'endpoints\' nicht gefunden.');
            return self::FAILURE;
        }

        $posAfterEndpoints = $posEndpoints + strlen("'endpoints' => [");
        $posBuilder = strpos($content, "'builder' =>", $posAfterEndpoints);
        $searchEnd = $posBuilder !== false ? $posBuilder : strlen($content);
        $section = substr($content, 0, $searchEnd);
        $posClosing = strrpos($section, "    ],");
        if ($posClosing === false || $posClosing < $posAfterEndpoints) {
            $this->error('Config-Struktur unerwartet: Endpoints-Block konnte nicht ermittelt werden.');
            return self::FAILURE;
        }

        $before = substr($content, 0, $posAfterEndpoints);
        $after = substr($content, $posClosing + 6);

        if (! file_put_contents($configPath, $before . $newBlock . $after)) {
            $this->error('config/mini-api.php konnte nicht geschrieben werden.');
            return self::FAILURE;
        }

        $this->info(count($endpoints) . ' Endpoint(s) in config/mini-api.php geschrieben.');
        $this->line('Tabellen: ' . implode(', ', $tables));

        return self::SUCCESS;
    }

    protected function getDatabaseTableNamesOnly(): array
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $result = DB::select(
                "SELECT table_name AS name FROM information_schema.tables " .
                "WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
                [$database]
            );
            return array_values(array_unique(array_map(fn ($r) => $r->name, $result)));
        }

        if ($driver === 'sqlite') {
            $result = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );
            return array_values(array_unique(array_map(fn ($r) => $r->name, $result)));
        }

        if ($driver === 'pgsql') {
            $result = DB::select(
                "SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
            );
            return array_values(array_unique(array_map(fn ($r) => $r->name, $result)));
        }

        $schema = Schema::getConnection()->getSchemaBuilder();
        if (method_exists($schema, 'getTableListing')) {
            $names = $schema->getTableListing(null, false);
            return array_values(array_unique((array) $names));
        }

        return [];
    }

    /**
     * Erzeugt den PHP-Code für die Endpoints im gleichen Aufbau wie config/mini-api.php:
     * 'key' => [
     *     'route'   => 'route-name',
     *     'table'   => 'table_name',
     *     'columns' => ['id', 'name', ...],
     * ],
     */
    protected function buildEndpointsPhp(array $endpoints): string
    {
        $lines = [];
        foreach ($endpoints as $ep) {
            $key = addslashes($ep['key']);
            $route = addslashes($ep['route']);
            $table = addslashes($ep['table']);
            $cols = $ep['columns'];
            $colsPhp = $cols === ['*']
                ? "'*'"
                : '[' . implode(', ', array_map(fn ($c) => "'" . addslashes($c) . "'", $cols)) . ']';
            $lines[] = "        '{$key}' => [";
            $lines[] = "            'route'   => '{$route}',";
            $lines[] = "            'table'   => '{$table}',";
            $lines[] = "            'columns' => {$colsPhp},";
            $lines[] = "        ],";
        }
        return implode("\n", $lines) . "\n    ],";
    }
}
