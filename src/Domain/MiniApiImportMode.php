<?php

declare(strict_types=1);

namespace Componist\MiniApi\Domain;

enum MiniApiImportMode: string
{
    case Fresh = 'fresh';
    case Update = 'update';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'fresh – Inhalt der gewählten Tabellen löschen und neu importieren',
            self::Update => 'update – vorhandene Zeilen aktualisieren, neue einfügen',
        };
    }
}
