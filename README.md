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

## Commands

| Command | Beschreibung |
|---------|--------------|
| `php artisan mini-api:generate-key` | Erzeugt einen API-Key |
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
