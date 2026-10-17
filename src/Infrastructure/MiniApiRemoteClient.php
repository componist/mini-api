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

    private const MAX_ATTEMPTS = 8;

    private const MAX_PAGES = 500;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $perPage,
        private readonly string $keyHeader = 'X-Api-Key',
        private readonly int $requestDelayMs = 0,
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
        $lastPage = 1;
        $previousFingerprint = null;

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

            /** @var list<array<string, mixed>> $list */
            $list = array_values(array_filter($rows, 'is_array'));
            if ($list === []) {
                break;
            }

            $fingerprint = $this->pageFingerprint($list);
            if ($previousFingerprint !== null && $fingerprint === $previousFingerprint) {
                // Server ignoriert page – sonst Endlosschleife mit identischen Daten.
                break;
            }
            $previousFingerprint = $fingerprint;

            yield $page => $list;

            $lastPageHeader = $response->header(MiniApiQueryPager::HEADER_LAST_PAGE);
            if ($lastPageHeader !== '') {
                $lastPage = max(1, (int) $lastPageHeader);
                $page++;

                continue;
            }

            // Ohne Header: volle Seite ⇒ weitere Seite versuchen; sonst fertig.
            if (count($list) < $this->perPage) {
                break;
            }

            $page++;
            $lastPage = $page;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);
    }

    private function fetchPage(string $route, int $page): Response
    {
        $attempt = 0;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            try {
                $response = $this->request()->get($this->url($route), [
                    'page' => $page,
                    'per_page' => $this->perPage,
                ]);
            } catch (ConnectionException $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new MiniApiImportException(
                        "Live-API nicht erreichbar ({$this->baseUrl()}): {$exception->getMessage()}",
                        'URL, DNS und Firewall prüfen (MINI_API_IMPORT_URL / --url).',
                        previous: $exception,
                    );
                }

                usleep(500_000 * $attempt);

                continue;
            }

            $status = $response->status();
            if (in_array($status, [429, 502, 503, 504], true) && $attempt < self::MAX_ATTEMPTS) {
                // Live throttle ist oft 60/min – bei 429 länger warten.
                $sleepSeconds = $status === 429
                    ? min(90, 10 * $attempt)
                    : min(30, 2 * $attempt);
                sleep($sleepSeconds);

                continue;
            }

            if ($this->requestDelayMs > 0) {
                usleep($this->requestDelayMs * 1000);
            }

            return $response;
        }

        throw new MiniApiImportException(
            "Live-API nach ".self::MAX_ATTEMPTS." Versuchen nicht erreichbar für '{$route}'.",
            'Rate-Limit oder Verbindungsprobleme – später erneut versuchen.',
        );
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
                'Kurz warten und erneut versuchen. Optional mini-api.import.request_delay_ms erhöhen.',
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
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function pageFingerprint(array $rows): string
    {
        $ids = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            $ids[] = (string) ($row['id'] ?? '');
        }

        return implode(',', $ids).':'.count($rows);
    }

    private function url(string $route): string
    {
        return $this->baseUrl().'/api/'.ltrim($route, '/');
    }
}
