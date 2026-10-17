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
use JsonException;
use Throwable;

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
        if ($table === '') {
            throw new MiniApiImportException(
                "Endpoint ohne gültige Tabelle (Route {$route}).",
                'In config/mini-api.php ein `table`-Feld setzen (Model-Endpoints sind nicht importierbar).',
            );
        }

        $requested = array_values(array_filter((array) ($config['columns'] ?? []), 'is_string'));
        if ($requested === []) {
            throw new MiniApiImportException(
                "Endpoint '{$route}' hat keine Spalten definiert.",
                'In config/mini-api.php `columns` als Liste setzen (kein *).',
            );
        }

        $columns = MiniApiSchemaGuard::filterColumns($table, $requested);

        if ($columns === []) {
            throw new MiniApiImportException(
                "Tabelle '{$table}' ist nicht erlaubt oder hat keine importierbaren Spalten.",
                'blocked_tables / blocked_fields prüfen, Migrationen ausführen, Spaltennamen abgleichen.',
            );
        }

        $primaryKey = $this->resolvePrimaryKey($table, $columns, $mode);
        $rows = 0;

        try {
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
        } catch (MiniApiImportException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw MiniApiImportException::fromThrowable($throwable, $table, $route);
        }

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
            $needed = $primaryKey === [] ? 'id (Primärschlüssel)' : implode(', ', $primaryKey);

            throw new MiniApiImportException(
                "Update-Modus benötigt den Primärschlüssel von '{$table}' in den Endpoint-Spalten ({$needed}).",
                'Spalten in config/mini-api.php ergänzen oder `--mode=fresh` verwenden.',
            );
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
        try {
            $records = array_map(fn (mixed $row): array => $this->toRecord((array) $row, $columns), $rows);
        } catch (JsonException $exception) {
            throw new MiniApiImportException(
                "JSON in Spalten von '{$table}' konnte nicht kodiert werden: {$exception->getMessage()}",
                'Array-/JSON-Felder in der Live-Antwort prüfen.',
                previous: $exception,
            );
        }

        $chunkSize = max(1, intdiv(self::MAX_BOUND_PARAMETERS, max(1, count($columns))));

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
        if ($records === []) {
            return;
        }

        if ($primaryKey === []) {
            DB::table($table)->insert($records);

            return;
        }

        // Verhindert 1062, wenn Unique-Keys (nicht PK) lokal unter anderer ID liegen.
        $this->clearUniqueConflicts($table, $records, $primaryKey);

        $updatable = array_values(array_diff($columns, $primaryKey));
        if ($updatable === []) {
            DB::table($table)->insertOrIgnore($records);

            return;
        }

        DB::table($table)->upsert($records, $primaryKey, $updatable);
    }

    /**
     * Löscht lokale Zeilen, die denselben Unique-Key wie eine Import-Zeile haben, aber einen anderen PK.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $primaryKey
     */
    private function clearUniqueConflicts(string $table, array $records, array $primaryKey): void
    {
        $uniqueIndexes = MiniApiSchemaGuard::uniqueIndexColumns($table);
        if ($uniqueIndexes === []) {
            return;
        }

        foreach ($records as $record) {
            foreach ($uniqueIndexes as $columns) {
                $query = DB::table($table);

                foreach ($columns as $column) {
                    if (! array_key_exists($column, $record)) {
                        continue 2;
                    }

                    $query->where($column, $record[$column]);
                }

                foreach ($primaryKey as $pkColumn) {
                    if (! array_key_exists($pkColumn, $record)) {
                        continue 2;
                    }

                    $query->where($pkColumn, '!=', $record[$pkColumn]);
                }

                $query->delete();
            }
        }
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
