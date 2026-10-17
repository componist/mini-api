<?php

declare(strict_types=1);

namespace Componist\MiniApi\Infrastructure;

use Componist\MiniApi\Domain\MiniApiPageRequest;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final class MiniApiQueryPager
{
    public const HEADER_TOTAL = 'X-Total-Count';

    public const HEADER_LAST_PAGE = 'X-Last-Page';

    /**
     * @return array{0: Collection<int, mixed>, 1: array<string, string>}
     */
    public static function fetch(Builder|EloquentBuilder $query, int $limit, ?MiniApiPageRequest $page, ?string $orderColumn): array
    {
        // Ohne order_by/PK keine stabile Pagination – hartes Limit, keine Meta-Header.
        if ($orderColumn === null) {
            return [$query->limit($limit)->get(), []];
        }

        // Ohne page-Parameter: kleine Tabellen unpaginiert; bei Truncation Meta-Header setzen,
        // damit Clients (Import) weitere Seiten nachladen können statt still Daten zu verlieren.
        if ($page === null) {
            $total = (clone $query)->count();
            $rows = (clone $query)->orderBy($orderColumn)->limit($limit)->get();

            if ($total <= $limit) {
                return [$rows, []];
            }

            return [$rows, [
                self::HEADER_TOTAL => (string) $total,
                self::HEADER_LAST_PAGE => (string) max(1, (int) ceil($total / $limit)),
            ]];
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy($orderColumn)->forPage($page->page, $page->perPage)->get();

        return [$rows, [
            self::HEADER_TOTAL => (string) $total,
            self::HEADER_LAST_PAGE => (string) max(1, (int) ceil($total / $page->perPage)),
        ]];
    }
}
