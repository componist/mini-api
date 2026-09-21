<?php

declare(strict_types=1);

namespace Componist\MiniApi\Domain;

final readonly class MiniApiPageRequest
{
    public function __construct(
        public int $page,
        public int $perPage,
    ) {}

    /**
     * Ohne `page`-Parameter bleibt die Antwort unpaginiert (Abwärtskompatibilität).
     */
    public static function fromQuery(mixed $page, mixed $perPage, int $maxPerPage): ?self
    {
        if ($page === null) {
            return null;
        }

        return new self(
            max(1, (int) $page),
            max(1, min((int) ($perPage ?? $maxPerPage), $maxPerPage)),
        );
    }
}
