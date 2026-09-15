# AGENTS – Mini API

## Zweck

Read-only GET-API aus `config/mini-api.php` (Tabelle oder Eloquent-Model).

## Struktur

```
src/Domain/MiniApiIdentifierRules.php
src/Application/MiniApiAuthService.php
src/Infrastructure/MiniApiSchemaGuard.php
src/Http/Controllers/{MiniApiController,MiniApiBuilderController}.php
config/mini-api.php
```

## Security

- Auth default an; leerer Key = 401
- `MiniApiSchemaGuard` filtert Tabellen/Spalten
- Nie `columns: ['*']`
- Builder-Routen: Middleware `web` + `auth`
- Endpoints: `throttle:60,1`

## Config-Keys

`mini-api.auth`, `endpoints`, `blocked_tables`, `blocked_fields`, `max_rows`, `builder`

## Tests

```bash
php artisan test --compact --testsuite=MiniApi
```
