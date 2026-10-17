# Mini API

Config-gesteuerte **read-only JSON-API** unter `/api/{route}`. Endpoints (Tabelle oder Eloquent-Model, Spalten, Relationen) werden in `config/mini-api.php` definiert — optional mit API-Key-Auth.

## Installation

Im Monorepo registriert (`Componist\MiniApi\MiniApiServiceProvider`).

```bash
php artisan vendor:publish --tag=mini-api-config
```

## Konfiguration

Config-Key: `mini-api`.

| Key / `.env` | Beschreibung |
|--------------|--------------|
| `MINI_API_AUTH_ENABLED` | Auth an/aus (Standard: `true`) |
| `MINI_API_KEY` | API-Key (Header `X-Api-Key` oder Query `api_key`) |
| `MINI_API_MAX_ROWS` | Row-Limit (Standard: 500) |
| `endpoints` | Endpoint-Definitionen (explizite `columns`, kein `*`) |
| `blocked_tables` / `blocked_fields` | Blocklisten (keine generische `token`-Sperre; Secrets pro Endpoint oder echte Secret-Feldnamen) |
| `MINI_API_IMPORT_URL` / `MINI_API_IMPORT_KEY` | Live-URL und API-Key für `mini-api:import` |
| `MINI_API_IMPORT_PER_PAGE` | Zeilen pro Import-Request (Standard: 500) |
| `MINI_API_BUILDER_ENABLED` | Builder-UI (Standard: `false`) |
| `MINI_API_BUILDER_ONLY_DEV` | Builder nur bei `APP_DEBUG` |

## Nutzung

- Öffentliche Endpoints: `GET /api/{route}` (Throttle `60,1`)
- Builder (wenn aktiv): `GET /mini-api-builder` hinter `web` + `auth`

Beispiel-Endpoint in Config:

```php
'endpoints' => [
    'posts' => [
        'table' => 'posts',
        'columns' => ['id', 'title', 'created_at'],
    ],
],
```

## Pagination

Ohne Parameter liefert ein Endpoint wie bisher bis zu `limit` Zeilen. Passt die Tabelle nicht in dieses Limit, setzt die API trotzdem `X-Total-Count` und `X-Last-Page`, damit Clients weitere Seiten nachladen können (kein stiller Datenverlust). Mit `?page=N&per_page=M` wird stabil nach Primärschlüssel sortiert paginiert (`per_page` ist durch `limit`/`max_rows` gedeckelt). Die Antwort bleibt ein JSON-Array. Ohne Primärschlüssel: `'order_by' => 'spalte'` im Endpoint setzen.

## Daten der Live-Anwendung importieren

```bash
# .env (lokal): MINI_API_IMPORT_URL=https://live.example.com, MINI_API_IMPORT_KEY=<MINI_API_KEY der Live-App>
php artisan componist:import-from-live            # alle Tabellen von Live (universal)
php artisan componist:import-from-live --mode=update
php artisan mini-api:import                       # interaktiv: Endpoints + Modus wählen
php artisan mini-api:import posts --mode=update   # einzelner Endpoint
php artisan mini-api:import posts --mode=fresh --force
```

Terminal-Ausgabe (Beispiel):

```text
Starte Import von Live (3 Endpoints, Modus: fresh)...
Basis: https://live.example.com

▶ posts → posts...
  ✓ 42 Datensätze.
▶ broken → broken...
  ✗ Live-Serverfehler (HTTP 500) für 'broken' (Seite 1)
    → Live-Log prüfen. …
▶ tags → tags...
  ✓ 7 Datensätze.

Import mit Fehlern: 2/3 ok, 1 fehlgeschlagen, 49 Datensätze übernommen.
```

- Quelle sind die **lokalen** Endpoint-Definitionen (`table`, `columns`, `route`); die Live-App muss die Endpoints mit gleicher Route bereitstellen und die Pagination dieser Version kennen (sonst wird nur die erste Seite gelesen).
- **Fehler stoppen den Rest nicht** – jeder Endpoint wird einzeln verarbeitet. Mit `--fail-fast` beim ersten Fehler abbrechen.
- `update`: Upsert per Primärschlüssel (muss in `columns` stehen). Zeilen mit kollidierenden Unique-Keys (nicht PK) unter anderer ID werden vor dem Upsert entfernt, damit kein `1062 Duplicate entry` entsteht.
- `fresh`: löscht den Inhalt der gewählten Tabellen (nicht der ganzen Datenbank) und importiert neu, mit Bestätigung. Pro Tabelle in einer Transaktion: bei einem Fehler bleibt der alte Stand dieser Tabelle.
- HTTP-/DB-Fehler erscheinen mit kurzer Erklärung und Tipp (z. B. API-Key, fehlende Live-Route, Unique-Konflikt).
- Lokal fehlende oder gesperrte Spalten (`blocked_fields`, `blocked_tables`) werden übersprungen und gemeldet. Gesperrte Felder wie `password` kommen deshalb nie mit.
- Fremdschlüssel-Prüfungen sind während des Imports deaktiviert.
- Model-Endpoints und Joins werden nicht importiert. In `production` ist der Command gesperrt.
- Den Key besser über `.env` als über `--key` setzen (Shell-History).

## Commands

| Command | Beschreibung |
|---------|--------------|
| `php artisan mini-api:generate-key` | Erzeugt einen API-Key |
| `php artisan mini-api:import` | Importiert gewählte Endpoints (interaktiv). Optionen: `--fail-fast`, `--force`, `--mode`, `--url`, `--key`, `--per-page` |
| `php artisan componist:import-from-live` | Universeller Import **aller** Tabellen-Endpoints von Live (Continue-on-Error). Optionen: `--mode=fresh\|update`, `--fail-fast`, `--url`, `--key`, `--per-page` |
| `php artisan mini-api:config-from-database` | Hilft beim Generieren von Endpoint-Config aus dem Schema |

## Berechtigungen

- API: bei aktivierter Auth ohne gültigen Key → **401**
- Builder-Routen: Middleware `web` + `auth` (kein separates Admin-Gate)
- Schema-Guard filtert blockierte Tabellen/Felder; kein `SELECT *`

## Tests

```bash
php artisan test --compact --testsuite=MiniApi
```

## Hinweise

- Endpoints in Root-/published Config pflegen (Package-Default: leeres `endpoints`)
- Builder nur lokal/debug nutzen; Production: Key setzen und Builder auslassen
