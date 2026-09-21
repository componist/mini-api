<?php

declare(strict_types=1);

namespace Componist\MiniApi\Infrastructure;

use Componist\MiniApi\Domain\MiniApiImportException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MiniApiRemoteClient
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 200;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $perPage,
        private readonly string $keyHeader = 'X-Api-Key',
    ) {}

    /**
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function pages(string $route): Generator
    {
        $page = 1;

        do {
            $response = $this->fetchPage($route, $page);

            if ($response->failed()) {
                throw new MiniApiImportException("Live-API antwortete mit HTTP {$response->status()} für '{$route}'.");
            }

            $rows = $response->json();
            if (! is_array($rows)) {
                throw new MiniApiImportException("Live-API lieferte kein JSON-Array für '{$route}'.");
            }

            yield $page => array_values($rows);

            // Ältere Server ignorieren `page` — ohne Header nur die erste Seite lesen, sonst Endlosschleife.
            $lastPage = (int) $response->header(MiniApiQueryPager::HEADER_LAST_PAGE);
            $page++;
        } while ($page <= $lastPage);
    }

    private function fetchPage(string $route, int $page): Response
    {
        try {
            return $this->request()->get($this->url($route), ['page' => $page, 'per_page' => $this->perPage]);
        } catch (ConnectionException $exception) {
            throw new MiniApiImportException("Live-API nicht erreichbar: {$exception->getMessage()}", 0, $exception);
        }
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([$this->keyHeader => $this->apiKey])
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->retry(
                self::RETRY_TIMES,
                self::RETRY_SLEEP_MS,
                static fn (\Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );
    }

    private function url(string $route): string
    {
        return rtrim($this->baseUrl, '/').'/api/'.ltrim($route, '/');
    }
}
