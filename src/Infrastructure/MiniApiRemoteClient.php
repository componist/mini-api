<?php

declare(strict_types=1);

namespace Componist\MiniApi\Infrastructure;

use Componist\MiniApi\Domain\MiniApiImportException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MiniApiRemoteClient
{
    private const TIMEOUT_SECONDS = 180;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 200;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $perPage,
        private readonly string $keyHeader = 'X-Api-Key',
    ) {}

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function pages(string $route): Generator
    {
        $page = 1;

        do {
            $response = $this->fetchPage($route, $page);

            if ($response->failed()) {
                throw $this->httpFailure($route, $page, $response);
            }

            $rows = $response->json();
            if (! is_array($rows)) {
                throw new MiniApiImportException(
                    "Live-API lieferte kein JSON-Array für '{$route}' (Seite {$page}).",
                    'Erwartet wird ein JSON-Array von Objekten. Prüfe, ob die Live-Route zur Mini-API gehört.',
                );
            }

            // Assoziative Fehler-Payloads ({"error":"..."}) sind kein Datensatz-Array.
            if ($rows !== [] && ! array_is_list($rows)) {
                $message = is_string($rows['error'] ?? null) ? $rows['error'] : 'unerwartetes Objekt';

                throw new MiniApiImportException(
                    "Live-API lieferte kein Listen-JSON für '{$route}': {$message}",
                    'Endpoint-Config auf Live und lokal abgleichen (route, table, columns).',
                );
            }

            yield $page => array_values(array_filter($rows, 'is_array'));

            // Ältere Server ignorieren `page` — ohne Header nur die erste Seite lesen, sonst Endlosschleife.
            $lastPageHeader = $response->header(MiniApiQueryPager::HEADER_LAST_PAGE);
            if ($lastPageHeader === '') {
                break;
            }

            $lastPage = max(1, (int) $lastPageHeader);
            $page++;
        } while ($page <= $lastPage);
    }

    private function fetchPage(string $route, int $page): Response
    {
        try {
            return $this->request()->get($this->url($route), ['page' => $page, 'per_page' => $this->perPage]);
        } catch (ConnectionException $exception) {
            throw new MiniApiImportException(
                "Live-API nicht erreichbar ({$this->baseUrl()}): {$exception->getMessage()}",
                'URL, DNS und Firewall prüfen (MINI_API_IMPORT_URL / --url).',
                previous: $exception,
            );
        }
    }

    private function httpFailure(string $route, int $page, Response $response): MiniApiImportException
    {
        $status = $response->status();
        $bodyHint = $this->bodyHint($response);
        $suffix = $bodyHint !== null ? " – {$bodyHint}" : '';

        [$message, $hint] = match (true) {
            $status === 401, $status === 403 => [
                "Live-API verweigert Zugriff (HTTP {$status}) für '{$route}'{$suffix}",
                'API-Key prüfen (MINI_API_IMPORT_KEY / --key) und Header X-Api-Key. Auth muss auf Live aktiv und Key identisch sein.',
            ],
            $status === 404 => [
                "Live-Endpoint nicht gefunden (HTTP 404) für '{$route}'{$suffix}",
                'Route in der Live-config/mini-api.php prüfen – lokal und Live müssen dieselbe route haben.',
            ],
            $status === 429 => [
                "Live-API Rate-Limit (HTTP 429) für '{$route}' (Seite {$page}){$suffix}",
                'Kurz warten und erneut versuchen, oder --per-page erhöhen und weniger parallel laufen lassen.',
            ],
            $status >= 500 => [
                "Live-Serverfehler (HTTP {$status}) für '{$route}' (Seite {$page}){$suffix}",
                'Live-Log prüfen. Große Tabellen brauchen Pagination (Mini-API ab Version mit X-Last-Page).',
            ],
            default => [
                "Live-API antwortete mit HTTP {$status} für '{$route}' (Seite {$page}){$suffix}",
                'Antwort und Endpoint-Config auf Live prüfen.',
            ],
        };

        return new MiniApiImportException($message, $hint);
    }

    private function bodyHint(Response $response): ?string
    {
        $json = $response->json();
        if (is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '') {
            return $json['error'];
        }

        $body = trim((string) $response->body());
        if ($body === '') {
            return null;
        }

        return Str::limit(preg_replace('/\s+/', ' ', $body) ?? $body, 120);
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
        return $this->baseUrl().'/api/'.ltrim($route, '/');
    }
}
