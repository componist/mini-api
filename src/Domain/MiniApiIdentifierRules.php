<?php

declare(strict_types=1);

namespace Componist\MiniApi\Domain;

final class MiniApiIdentifierRules
{
    public static function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    /** Join-Spalten: nur `col` oder `table.col as alias`. */
    public const SAFE_JOIN_COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?(\s+[Aa][Ss]\s+[A-Za-z_][A-Za-z0-9_]*)?$/';

    public static function isSafeJoinColumnExpression(string $column): bool
    {
        return (bool) preg_match(self::SAFE_JOIN_COLUMN_PATTERN, $column);
    }

    /**
     * @return array{table: ?string, column: string, alias: ?string}|null
     */
    public static function parseJoinColumn(string $expression): ?array
    {
        if (! self::isSafeJoinColumnExpression($expression)) {
            return null;
        }

        [$reference, $alias] = array_pad(preg_split('/\s+as\s+/i', trim($expression), 2), 2, null);
        [$table, $column] = str_contains($reference, '.') ? explode('.', $reference, 2) : [null, $reference];

        return ['table' => $table, 'column' => $column, 'alias' => $alias];
    }

    public static function sanitizeIdentifier(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $value);

        return $sanitized ?? '';
    }
}
