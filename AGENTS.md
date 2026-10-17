# AGENTS – Mini API

## Zweck

Read-only GET-API aus `config/mini-api.php` (Tabelle oder Eloquent-Model).

## Struktur

```
src/Domain/{MiniApiIdentifierRules,MiniApiPageRequest,MiniApiImportMode,MiniApiImportResult,MiniApiImportException}.php
src/Application/{MiniApiAuthService,MiniApiImportService}.php
src/Infrastructure/{MiniApiSchemaGuard,MiniApiQueryPager,MiniApiJoinQueryBuilder,MiniApiRemoteClient}.php
src/Console/{ImportMiniApiDataCommand,ImportFromLiveCommand}.php
src/Http/Controllers/{MiniApiController,MiniApiBuilderController}.php
config/mini-api.php
```

## Security

- Auth default an; leerer Key = 401
- `MiniApiSchemaGuard` filtert Tabellen/Spalten
- Nie `columns: ['*']`
- Join-Spalten nur aus der Join-Tabelle, mit denselben Blocklisten (`isAllowedJoinColumn`)
- Model-Relationen: nur echte Relationen (Return-Type) auf nicht gesperrte Tabellen; `blocked_fields` werden aus der Relation-Ausgabe entfernt
- Builder-Routen: Middleware `web` + `auth`
- Endpoints: `throttle:60,1`

## Pagination & Import

- `?page`/`per_page` aktivieren Pagination (Sortierung: Primärschlüssel oder `order_by`); Header `X-Total-Count`, `X-Last-Page`. Ohne `page`: bis `max_rows`; bei Truncation trotzdem Meta-Header setzen.
- `mini-api:import` holt Seiten per HTTP von der Live-API (`MiniApiRemoteClient`) und schreibt in die lokale Tabelle des Endpoints (`MiniApiImportService`).
- Import: nur Table-Endpoints, Guard-Filter auch lokal, Transaktion pro Tabelle, in production gesperrt. `fresh` nutzt `delete` (kein `truncate`), sonst greift der Rollback nicht. `update` entfernt vor Upsert Unique-Konflikte (anderer PK, gleicher Unique-Key).
- UX: `▶ route → table` / `✓ N Datensätze` / `✗ Fehler` + Hint. Standard: Continue-on-Error; `--fail-fast` bricht ab. Summary am Ende.
- Fehler: `MiniApiImportException` mit optionalem `hint`; DB/HTTP werden in freundliche Meldungen gewrappt (`fromThrowable`, RemoteClient-Statuscodes).
- `componist:import-from-live`: universeller Import aller table-Endpoints; Reihenfolge `import.table_order`; Defaults `import.column_defaults` (z. B. `password` → `__hash:import-placeholder__`).
- App-Wrapper dürfen nur projektspezifische Nacharbeiten enthalten – Import-Kern bleibt im Package.

## Config-Keys

`mini-api.auth`, `endpoints`, `blocked_tables`, `blocked_fields` (kein generisches `token`), `max_rows`, `import`, `builder`

## Tests

```bash
php artisan test --compact --testsuite=MiniApi
```
