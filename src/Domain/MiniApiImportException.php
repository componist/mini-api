<?php

declare(strict_types=1);

namespace Componist\MiniApi\Domain;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use Throwable;

final class MiniApiImportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $hint = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromThrowable(Throwable $throwable, string $table, string $route): self
    {
        if ($throwable instanceof self) {
            return $throwable;
        }

        if ($throwable instanceof UniqueConstraintViolationException) {
            return new self(
                "Unique-Konflikt in '{$table}' (Route {$route}): {$throwable->getMessage()}",
                'Tipp: `--mode=fresh` leert die Tabelle vorher. Bei update werden kollidierende Unique-Keys unter anderer ID entfernt – prüfe fehlende Spalten im Endpoint.',
                previous: $throwable,
            );
        }

        if ($throwable instanceof QueryException) {
            return new self(
                "Datenbankfehler beim Schreiben von '{$table}': {$throwable->getMessage()}",
                'Tipp: Schema/Migrationen lokal prüfen, Spalten in config/mini-api.php abgleichen, bei FK-Problemen `--mode=fresh` für abhängige Tabellen nutzen.',
                previous: $throwable,
            );
        }

        return new self(
            "Unerwarteter Fehler bei '{$table}' (Route {$route}): {$throwable->getMessage()}",
            'Details stehen im Laravel-Log (storage/logs).',
            previous: $throwable,
        );
    }
}
