<?php

declare(strict_types=1);

namespace Componist\MiniApi\Domain;

final readonly class MiniApiImportResult
{
    /**
     * @param  list<string>  $skippedColumns
     */
    public function __construct(
        public string $table,
        public int $rows,
        public array $skippedColumns,
    ) {}
}
