<?php

declare(strict_types=1);

namespace Componist\MiniApi\Infrastructure;

use Componist\MiniApi\Domain\MiniApiIdentifierRules;
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
