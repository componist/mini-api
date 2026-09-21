<?php

declare(strict_types=1);

namespace Componist\MiniApi\Application;

use Componist\MiniApi\Domain\MiniApiIdentifierRules;
use Componist\MiniApi\Domain\MiniApiImportException;
use Componist\MiniApi\Domain\MiniApiImportMode;
use Componist\MiniApi\Domain\MiniApiImportResult;
use Componist\MiniApi\Infrastructure\MiniApiRemoteClient;
use Componist\MiniApi\Infrastructure\MiniApiSchemaGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MiniApiImportService
{
    // SQLite erlaubt je nach Build nur 999 gebundene Parameter pro Statement.
    private const MAX_BOUND_PARAMETERS = 900;

    /**
     * @param  array<string, mixed>  $config  Endpoint-Definition aus config/mini-api.php
     */
    public function import(string $route, array $config, MiniApiImportMode $mode, MiniApiRemoteClient $client): MiniApiImportResult
    {
        $table = MiniApiIdentifierRules::sanitizeIdentifier((string) ($config['table'] ?? ''));
        $requested = array_values(array_filter((array) ($config['columns'] ?? []), 'is_string'));
        $columns = MiniApiSchemaGuard::filterColumns($table, $requested);

        if ($columns === []) {
            throw new MiniApiImportException("Tabelle '{$table}' ist nicht erlaubt oder hat keine importierbaren Spalten.");
        }

        $primaryKey = $this->resolvePrimaryKey($table, $columns, $mode);
        $rows = 0;

        Schema::withoutForeignKeyConstraints(function () use ($route, $table, $columns, $primaryKey, $mode, $client, &$rows): void {
            DB::transaction(function () use ($route, $table, $columns, $primaryKey, $mode, $client, &$rows): void {
                if ($mode === MiniApiImportMode::Fresh) {
                    // delete statt truncate: TRUNCATE committet implizit und würde den Rollback aushebeln.
                    DB::table($table)->delete();
                }

                foreach ($client->pages($route) as $pageRows) {
                    $rows += $this->write($table, $pageRows, $columns, $primaryKey);
                }
            });
        });

        return new MiniApiImportResult($table, $rows, array_values(array_diff($requested, $columns)));
    }

    /**
     * @param  list<string>  $columns
     * @return list<string> Leer im Fresh-Modus, dort wird nur eingefügt.
     */
    private function resolvePrimaryKey(string $table, array $columns, MiniApiImportMode $mode): array
    {
        if ($mode === MiniApiImportMode::Fresh) {
            return [];
        }

        $primaryKey = MiniApiSchemaGuard::primaryKeyColumns($table);
        if ($primaryKey === [] || array_diff($primaryKey, $columns) !== []) {
            throw new MiniApiImportException("Update-Modus benötigt den Primärschlüssel von '{$table}' in den Endpoint-Spalten.");
        }

        return $primaryKey;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @param  list<string>  $primaryKey
     */
    private function write(string $table, array $rows, array $columns, array $primaryKey): int
    {
        $records = array_map(fn (mixed $row): array => $this->toRecord((array) $row, $columns), $rows);
        $chunkSize = max(1, intdiv(self::MAX_BOUND_PARAMETERS, count($columns)));

        foreach (array_chunk($records, $chunkSize) as $chunk) {
            $this->persist($table, $chunk, $columns, $primaryKey);
        }

        return count($records);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $columns
     * @param  list<string>  $primaryKey
     */
    private function persist(string $table, array $records, array $columns, array $primaryKey): void
    {
        if ($primaryKey === []) {
            DB::table($table)->insert($records);

            return;
        }

        $updatable = array_values(array_diff($columns, $primaryKey));
        if ($updatable === []) {
            DB::table($table)->insertOrIgnore($records);

            return;
        }

        DB::table($table)->upsert($records, $primaryKey, $updatable);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function toRecord(array $row, array $columns): array
    {
        $record = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            $record[$column] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        }

        return $record;
    }
}
