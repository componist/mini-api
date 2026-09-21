<?php

declare(strict_types=1);

namespace Componist\MiniApi\Infrastructure;

use Componist\MiniApi\Domain\MiniApiIdentifierRules;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MiniApiJoinQueryBuilder
{
    /**
     * @param  list<string>  $columns  bereits gefilterte Hauptspalten
     * @param  array<int|string, mixed>  $relations
     */
    public static function build(string $table, array $columns, array $relations): Builder
    {
        // Qualifiziert, weil Join-Tabellen gleichnamige Spalten (z. B. `id`) haben.
        $query = DB::table($table)->select(array_map(
            static fn (string $column): string => "{$table}.{$column}",
            $columns,
        ));

        foreach ($relations as $relation) {
            if (is_array($relation)) {
                self::applyJoin($query, $table, $relation);
            }
        }

        return $query;
    }

    /**
     * Gruppiert Join-Spalten unter ihrem `alias` und verwirft alles, was weder Hauptspalte noch Alias ist.
     *
     * @param  Collection<int, mixed>  $rows
     * @param  list<string>  $columns
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, array<string, mixed>>
     */
    public static function reshape(Collection $rows, array $columns, array $relations): Collection
    {
        $groups = self::aliasGroups($relations);
        $mainKeys = array_flip($columns);

        return $rows->map(function (mixed $row) use ($groups, $mainKeys): array {
            $record = (array) $row;
            $shaped = [];

            foreach ($groups as $alias => $keys) {
                foreach (array_intersect($keys, array_keys($record)) as $key) {
                    $shaped[$alias][$key] = $record[$key];
                    unset($record[$key]);
                }
            }

            return $shaped + array_intersect_key($record, $mainKeys);
        });
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    private static function applyJoin(Builder $query, string $table, array $relation): void
    {
        $joinTable = MiniApiIdentifierRules::sanitizeIdentifier((string) ($relation['table'] ?? ''));
        $foreignKey = MiniApiIdentifierRules::sanitizeIdentifier((string) ($relation['foreign_key'] ?? ''));
        if ($joinTable === '' || $foreignKey === '' || ! MiniApiSchemaGuard::isAllowedTable($joinTable)) {
            return;
        }

        $method = ($relation['type'] ?? 'join') === 'left_join' ? 'leftJoin' : 'join';
        $query->{$method}($joinTable, "{$table}.{$foreignKey}", '=', "{$joinTable}.id");

        foreach ((array) ($relation['columns'] ?? []) as $column) {
            if (is_string($column) && MiniApiSchemaGuard::isAllowedJoinColumn($column, $joinTable)) {
                $query->addSelect($column);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return array<string, list<string>> Alias => Ergebnis-Schlüssel der Join-Spalten
     */
    private static function aliasGroups(array $relations): array
    {
        $groups = [];

        foreach ($relations as $relation) {
            if (! is_array($relation) || empty($relation['alias'])) {
                continue;
            }

            foreach ((array) ($relation['columns'] ?? []) as $column) {
                $parsed = is_string($column) ? MiniApiIdentifierRules::parseJoinColumn($column) : null;
                if ($parsed !== null) {
                    $groups[(string) $relation['alias']][] = $parsed['alias'] ?? $parsed['column'];
                }
            }
        }

        return $groups;
    }
}
