<?php

declare(strict_types=1);

namespace Componist\MiniApi\Infrastructure;

use Componist\MiniApi\Domain\MiniApiIdentifierRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MiniApiSchemaGuard
{
    public static function isValidIdentifier(string $name): bool
    {
        return MiniApiIdentifierRules::isValidIdentifier($name);
    }

    public static function isAllowedTable(string $table): bool
    {
        if (! self::isValidIdentifier($table)) {
            return false;
        }

        $blocked = array_map('strtolower', config('mini-api.blocked_tables', []));

        if (in_array(strtolower($table), $blocked, true)) {
            return false;
        }

        return Schema::hasTable($table);
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function filterColumns(string $table, array $requested): array
    {
        if (! self::isAllowedTable($table)) {
            return [];
        }

        $blocked = array_map('strtolower', config('mini-api.blocked_fields', []));
        $existing = Schema::getColumnListing($table);

        return array_values(array_filter(
            $requested,
            static function (string $column) use ($blocked, $existing): bool {
                if (! self::isValidIdentifier($column)) {
                    return false;
                }
                if (in_array(Str::lower($column), $blocked, true)) {
                    return false;
                }

                return in_array($column, $existing, true);
            }
        ));
    }

    public static function isBlockedTable(string $table): bool
    {
        return in_array(strtolower($table), array_map('strtolower', config('mini-api.blocked_tables', [])), true);
    }

    /**
     * Join-Spalten dürfen nur aus der Join-Tabelle selbst kommen und unterliegen denselben Blocklisten wie Hauptspalten.
     */
    public static function isAllowedJoinColumn(string $expression, string $joinTable): bool
    {
        $parsed = MiniApiIdentifierRules::parseJoinColumn($expression);
        if ($parsed === null || ($parsed['table'] !== null && strtolower($parsed['table']) !== strtolower($joinTable))) {
            return false;
        }

        return self::filterColumns($joinTable, [$parsed['column']]) !== [];
    }

    /**
     * Jede Relation im Pfad (`a.b`) muss eine echte Relation sein und auf eine erlaubte Tabelle zeigen.
     */
    public static function isAllowedRelationPath(Model $model, string $path): bool
    {
        foreach (explode('.', $path) as $name) {
            if (! self::isRelationMethod($model, $name)) {
                return false;
            }

            $model = $model->{$name}()->getRelated();
            if (self::isBlockedTable($model->getTable())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripBlockedFields(array $data): array
    {
        $blocked = array_map('strtolower', config('mini-api.blocked_fields', []));
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::stripBlockedFields($value) : $value;
        }

        return $clean;
    }

    private static function isRelationMethod(Model $model, string $name): bool
    {
        if (! self::isValidIdentifier($name) || ! method_exists($model, $name)) {
            return false;
        }

        $returnType = (new \ReflectionMethod($model, $name))->getReturnType();

        return $returnType instanceof \ReflectionNamedType && is_a($returnType->getName(), Relation::class, true);
    }

    /**
     * @return list<string>
     */
    public static function primaryKeyColumns(string $table): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (! empty($index['primary'])) {
                return array_values($index['columns']);
            }
        }

        return [];
    }

    /**
     * Stabile Sortierung ist Voraussetzung für Pagination: konfigurierte `order_by`-Spalte, sonst erste Primärschlüssel-Spalte.
     */
    public static function resolveOrderColumn(string $table, mixed $configured): ?string
    {
        $candidates = is_string($configured) && $configured !== ''
            ? [$configured]
            : array_slice(self::primaryKeyColumns($table), 0, 1);

        $allowed = self::filterColumns($table, $candidates);

        return $allowed === [] ? null : "{$table}.{$allowed[0]}";
    }

    public static function resolveModelOrderColumn(Model $model, mixed $configured): string
    {
        if (is_string($configured) && self::filterModelColumns([$configured]) !== []) {
            return $model->qualifyColumn($configured);
        }

        return $model->getQualifiedKeyName();
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function filterModelColumns(array $columns): array
    {
        $blocked = array_map('strtolower', config('mini-api.blocked_fields', []));

        return array_values(array_filter(
            $columns,
            static function (string $column) use ($blocked): bool {
                if (! self::isValidIdentifier($column)) {
                    return false;
                }

                return ! in_array(Str::lower($column), $blocked, true);
            }
        ));
    }
}
