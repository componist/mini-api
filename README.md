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
| `blocked_tables` / `blocked_fields` | Blocklisten |
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

Ohne Parameter liefert ein Endpoint wie bisher bis zu `limit` Zeilen. Mit `?page=N&per_page=M` wird stabil nach Primärschlüssel sortiert paginiert (`per_page` ist durch `limit`/`max_rows` gedeckelt). Die Antwort bleibt ein JSON-Array, die Meta-Infos stehen in den Headern `X-Total-Count` und `X-Last-Page`. Ohne Primärschlüssel: `'order_by' => 'spalte'` im Endpoint setzen.

## Daten der Live-Anwendung importieren

```bash
# .env (lokal): MINI_API_IMPORT_URL=https://live.example.com, MINI_API_IMPORT_KEY=<MINI_API_KEY der Live-App>
php artisan mini-api:import                       # interaktiv: Endpoints + Modus wählen
php artisan mini-api:import posts --mode=update   # nicht interaktiv
php artisan mini-api:import posts --mode=fresh --force
```

- Quelle sind die **lokalen** Endpoint-Definitionen (`table`, `columns`, `route`); die Live-App muss die Endpoints mit gleicher Route bereitstellen und die Pagination dieser Version kennen (sonst wird nur die erste Seite gelesen).
- `update`: Upsert per Primärschlüssel (muss in `columns` stehen), nichts wird gelöscht.
- `fresh`: löscht den Inhalt der gewählten Tabellen (nicht der ganzen Datenbank) und importiert neu, mit Bestätigung. Pro Tabelle in einer Transaktion: bei einem Fehler bleibt der alte Stand.
- Lokal fehlende oder gesperrte Spalten (`blocked_fields`, `blocked_tables`) werden übersprungen und gemeldet. Gesperrte Felder wie `password` kommen deshalb nie mit.
- Fremdschlüssel-Prüfungen sind während des Imports deaktiviert.
- Model-Endpoints und Joins werden nicht importiert. In `production` ist der Command gesperrt.
- Den Key besser über `.env` als über `--key` setzen (Shell-History).

## Commands

| Command | Beschreibung |
|---------|--------------|
| `php artisan mini-api:generate-key` | Erzeugt einen API-Key |
| `php artisan mini-api:import` | Importiert Live-Daten über die Mini-API (fresh/update) |
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
