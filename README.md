# Mini API (componist/mini-api) – Benutzungsanleitung

Vollständige Anleitung zur Installation, Konfiguration und Nutzung der config-gesteuerten Mini-API für Laravel.

---

## 1. Was ist die Mini API?

Die **Mini API** ist ein schlankes Laravel-Package, das über eine **einzige Config-Datei** REST-artige JSON-Endpoints bereitstellt:

- Du definierst **Route**, **Tabelle oder Eloquent-Model**, **Spalten** und optional **Relationen**.
- Die API liefert **ausschließlich JSON** (keine HTML-Views).
- Optional kannst du einen **API-Key** schützen (Header oder Query-Parameter).
- Ein **Config-Builder** (Web-UI) hilft beim Erzeugen der Config ohne PHP-Syntax.

**Einsatz:** Schnelle Lese-APIs für externe Systeme, Mobile Apps oder Frontends, wenn du keine volle REST-API mit Controllern pro Resource brauchst.

---

## 2. Installation

### 2.1 Package einbinden

**Über Composer (Packagist):**
```bash
composer require componist/mini-api
```

**Als lokales Package (z. B. im Monorepo):** In der `composer.json` des Projekts:
```json
{
    "repositories": [
        { "type": "path", "url": "packages/mini-api" }
    ],
    "require": {
        "componist/mini-api": "*"
    }
}
```

Dann:
```bash
composer update
```

### 2.2 Config ins Projekt übernehmen (empfohlen)

Damit du Endpoints anpassen kannst, die Config aus dem Package ins Projekt kopieren:
```bash
php artisan vendor:publish --tag=mini-api-config
```

Es wird `config/mini-api.php` angelegt (oder überschrieben). Ohne Publish nutzt die App die Default-Config aus dem Package; eigene Endpoints trägst du dann in der **publizierten** `config/mini-api.php` ein.

---

## 3. Konfiguration

Die zentrale Datei ist **`config/mini-api.php`** (nach Publish in deinem Projekt, sonst die Package-Config).

### 3.0 Benötigte `.env`-Variablen

Die Mini API benötigt **keine** Pflicht-Variablen in der `.env`. Du ergänzt sie nur, wenn du Features aktivieren willst:

| Zweck | Variable | Beispiel |
|------|----------|----------|
| API-Key-Schutz aktivieren | `MINI_API_AUTH_ENABLED` | `true` |
| API-Key setzen | `MINI_API_KEY` | `dein-geheimer-key` |
| Builder aktivieren (Dev) | `MINI_API_BUILDER_ENABLED` | `true` |
| Builder nur im Debug | `MINI_API_BUILDER_ONLY_DEV` | `true` |

Hinweis: `MINI_API_BUILDER_ONLY_DEV` ist standardmäßig `true` in der Config. Wenn du ihn explizit in der `.env` setzt, überschreibst du den Default. `APP_DEBUG=true` muss zusätzlich aktiv sein, damit der Builder erreichbar ist.

**Fertige `.env`-Vorlage (mit Defaults):**

```env
# Mini API (Defaults)
MINI_API_AUTH_ENABLED=false
MINI_API_KEY=
MINI_API_BUILDER_ENABLED=true
MINI_API_BUILDER_ONLY_DEV=true
```

### 3.1 Aufbau

| Bereich      | Bedeutung |
|-------------|-----------|
| `auth`      | Optional: API-Key-Schutz (global). |
| `endpoints` | Array aller API-Endpoints (Route, Tabelle/Model, Spalten, ggf. Relationen). |
| `builder`   | Einstellungen für den Config-Builder (nur Dev). |

### 3.2 Endpoints definieren

Jeder Eintrag unter `endpoints` wird zu einer **GET**-Route:  
`/api/<route>` → JSON mit den konfigurierten Daten.

**Pflichtangaben pro Endpoint:**

- **`route`** – URL-Teil nach `/api/` (z. B. `users` → `GET /api/users`).
- **`table`** ODER **`model`** – Datenquelle (Tabelle = Query Builder, Model = Eloquent).
- **`columns`** – Array der Spalten (bei Model optional, Standard `['*']`).

**Optionen:**

- **`relations`** – bei **Model**: Eloquent-Relationen (z. B. `['company', 'company.country']`); bei **table**: Joins (Array mit `type`, `table`, `foreign_key`, `columns`, ggf. `alias`).
- **`auth`** – optional: eigener API-Key nur für diesen Endpoint (überschreibt globales `auth`).

---

### 3.3 Beispiel: Nur Tabelle (Query Builder)

```php
'endpoints' => [
    'users' => [
        'route'   => 'users',
        'table'   => 'users',
        'columns' => ['id', 'name', 'email', 'created_at'],
    ],
],
```

- **Aufruf:** `GET /api/users`
- **Antwort:** JSON-Array mit Objekten, die nur diese Spalten haben (z. B. ohne `password`).

---

### 3.4 Beispiel: Eloquent-Model mit Relationen

```php
'endpoints' => [
    'job_offers' => [
        'route'     => 'job-offers',
        'model'     => \App\Models\JobOffer::class,
        'columns'   => ['id', 'title', 'slug', 'company_id', 'created_at'],
        'relations' => ['company', 'company.country'],
    ],
],
```

- **Aufruf:** `GET /api/job-offers`
- **Antwort:** JSON mit verschachtelten Objekten `company` und `company.country` (Eager Loading).

Relationen können auch mit Spalten-Einschränkung angegeben werden:

```php
'relations' => [
    'company',
    'applications' => ['id', 'status'],  // nur diese Spalten der Relation
],
```

---

### 3.5 Beispiel: Joins (ohne Model)

Wenn du keine Eloquent-Models nutzen willst, kannst du mit **Joins** verknüpfte Tabellen einbinden:

```php
'job_offers' => [
    'route'   => 'job-offers',
    'table'   => 'job_offers',
    'columns' => ['id', 'title', 'slug', 'company_id', 'created_at'],
    'relations' => [
        [
            'type'         => 'join',
            'table'        => 'companies',
            'foreign_key'  => 'company_id',
            'columns'      => ['name as company_name', 'slug as company_slug'],
            'alias'        => 'company',   // optional: Unterobjekt im JSON
        ],
        [
            'type'         => 'left_join',
            'table'        => 'categories',
            'foreign_key'  => 'category_id',
            'columns'      => ['name as category_name'],
        ],
    ],
],
```

- **Ohne `alias`:** Spalten erscheinen flach (z. B. `company_name`, `company_slug`).
- **Mit `alias`:** Spalten werden in ein Unterobjekt gepackt (z. B. `company: { company_name, company_slug }`).

---

### 3.6 API-Key (Auth)

Wenn die API nur mit gültigem Key erreichbar sein soll:

**1. Key erzeugen und in `.env` eintragen:**

```bash
php artisan mini-api:generate-key
```

Der Befehl schreibt `MINI_API_KEY=<generierter-Key>` in die `.env`. Beim ersten Mal wird zudem `MINI_API_AUTH_ENABLED=true` gesetzt.

**2. In der Config (bereits Standard nach Publish):**

```php
'auth' => [
    'enabled' => env('MINI_API_AUTH_ENABLED', false),
    'key'     => env('MINI_API_KEY'),
    'header'  => 'X-Api-Key',
    'query'   => 'api_key',
],
```

- **`enabled`** und **`key`** gesetzt → jeder Request muss den Key mitliefern.
- **Key per Header:** z. B. `X-Api-Key: dein-geheimer-key`.
- **Key per Query:** z. B. `GET /api/users?api_key=dein-geheimer-key`.
- Reihenfolge: Zuerst Header, falls nicht vorhanden dann Query.

**Bei falschem oder fehlendem Key:** Antwort **401 Unauthorized** mit `{"error": "Invalid or missing API key"}`.

**Pro Endpoint anderes Auth:** Ein Endpoint kann eigenes `auth` haben (z. B. anderer Key), das mit dem globalen `auth` zusammengeführt wird; Endpoint-Config hat Vorrang.

---

## 4. API aufrufen

### 4.1 URLs

- Basis: **`/api/`** + der in der Config angegebene **`route`**-Wert.
- Nur **GET** wird unterstützt (Lesen der konfigurierten Daten).

Beispiele (wenn `route` = `users` bzw. `job-offers`):

- `GET https://deine-domain.de/api/users`
- `GET https://deine-domain.de/api/job-offers`

### 4.2 Ohne API-Key

```bash
curl -X GET "https://deine-domain.de/api/users"
```

### 4.3 Mit API-Key (Header)

```bash
curl -X GET "https://deine-domain.de/api/users" \
  -H "X-Api-Key: dein-geheimer-key"
```

### 4.4 Mit API-Key (Query-Parameter)

```bash
curl "https://deine-domain.de/api/users?api_key=dein-geheimer-key"
```

### 4.5 Antwortformat

- **Erfolg (200):** JSON-Array von Objekten mit den konfigurierten Spalten (und Relationen).
- **401:** API-Key fehlt oder ist falsch → `{"error": "Invalid or missing API key"}`.
- **404:** Unbekannter Endpoint (Route nicht in `endpoints` oder Config ungültig).

---

## 5. API-Key generieren (Artisan)

Befehl: **`php artisan mini-api:generate-key`**

| Option     | Bedeutung |
|------------|-----------|
| (keine)    | Neuen Key erzeugen und in `.env` eintragen. Existiert `MINI_API_KEY` bereits, Fehler ohne `--force`. |
| `--show`  | Key nur in der Konsole ausgeben, **nicht** in `.env` schreiben. |
| `--force` | Vorhandenen `MINI_API_KEY` in `.env` überschreiben. |
| `--length=64` | Länge des Keys (32–128 Zeichen). |

**Beispiele:**

```bash
# Key erzeugen und in .env schreiben (beim ersten Mal + MINI_API_AUTH_ENABLED=true)
php artisan mini-api:generate-key

# Key nur anzeigen (z. B. zum manuellen Eintragen)
php artisan mini-api:generate-key --show

# Bestehenden Key ersetzen
php artisan mini-api:generate-key --force

# Länge anpassen
php artisan mini-api:generate-key --length=128
```

---

## 6. Config-Builder (Web-UI)

Der **Config-Builder** ist eine Weboberfläche, mit der du Endpoint-Configs per Klick erzeugst (Tabellen/Spalten/Relationen wählen, PHP-Array anzeigen oder direkt in `config/mini-api.php` schreiben).

### 6.1 Builder aktivieren

In der **`.env`** (nur für Entwicklung empfohlen):

```env
MINI_API_BUILDER_ENABLED=true
APP_DEBUG=true
```

Ist `MINI_API_BUILDER_ONLY_DEV=true` (Standard), ist der Builder nur bei `APP_DEBUG=true` erreichbar.

### 6.2 Builder aufrufen

Im Browser die konfigurierte Route aufrufen (Standard):

**`/mini-api-builder`**

(z. B. `http://localhost:8000/mini-api-builder`)

Die Route ist in `config/mini-api.php` unter `builder.route` änderbar.

### 6.3 Ablauf im Builder

1. **Tabelle wählen** – Liste aller Datenbanktabellen (nur echte Tabellen, keine Views).
2. **Spalten** – Checkboxen für die Spalten; „Alle auswählen“ / „Alle abwählen“.
3. **Optional: Model** – Falls ein Eloquent-Model zur Tabelle existiert, kannst du es auswählen (dann sind Relationen möglich).
4. **Optional: Relationen** – Bei gewähltem Model: Relationen als Checkboxen (inkl. verschachtelt, z. B. `company.country`).
5. **Endpoint-Key & Route** – Config-Key (z. B. `users`) und API-Pfad (z. B. `users` → `/api/users`).
6. **Aktionen:**
   - **Endpoint zur Liste hinzufügen** – aktuellen Endpoint in eine Liste legen (für mehrere Endpoints).
   - **Vorschau** – erzeugtes PHP-Array anzeigen.
   - **Kopieren** – PHP-Array in die Zwischenablage.
   - **In Config schreiben** – Endpoint(s) in `config/mini-api.php` eintragen (nur wenn die Config bereits publiziert wurde).

**Mehrere Endpoints:** Konfiguration für den ersten Endpoint auswählen → „Zur Liste hinzufügen“ → nächste Tabelle/Spalten/Route wählen → wieder „Zur Liste hinzufügen“ → am Ende „In Config schreiben“ für alle.

### 6.4 Hinweis

Der Builder schreibt nur in eine **bereits vorhandene** `config/mini-api.php` (z. B. nach `php artisan vendor:publish --tag=mini-api-config`). Existiert die Datei nicht, erscheint eine Fehlermeldung; du kannst die **Vorschau** nutzen und den Inhalt manuell in die Config übernehmen.

---

## 7. Übersicht Konfigurationsoptionen

### 7.1 Global: `auth`

| Schlüssel   | Bedeutung |
|------------|-----------|
| `enabled`  | `true` = API-Key prüfen. |
| `key`      | Erwarteter Key (z. B. aus `env('MINI_API_KEY')`). |
| `header`   | HTTP-Header für den Key (Standard: `X-Api-Key`). |
| `query`    | Query-Parameter für den Key (Standard: `api_key`). |

### 7.2 Pro Endpoint

| Schlüssel    | Pflicht? | Bedeutung |
|-------------|----------|-----------|
| `route`    | ja       | URL-Pfad nach `/api/`. |
| `table`    | ja*      | Datenbanktabelle (*wenn kein `model`). |
| `model`    | ja*      | Eloquent-Model-Klasse (*wenn keine `table`). |
| `columns`  | nein     | Spalten-Array; bei Model Standard `['*']`. |
| `relations`| nein     | Eloquent: Relation-Namen (inkl. Punkt für verschachtelt); Joins: Array mit `type`, `table`, `foreign_key`, `columns`, optional `alias`. |
| `auth`     | nein     | Eigenes Auth für diesen Endpoint (überschreibt global). |

### 7.3 Builder: `builder`

| Schlüssel   | Bedeutung |
|------------|-----------|
| `enabled`  | Builder-Route und APIs aktivieren. |
| `only_dev` | `true` = nur bei `APP_DEBUG=true` erreichbar. |
| `route`    | URL-Pfad des Builders (z. B. `mini-api-builder`). |

---

## 8. Kurzreferenz

| Ziel | Aktion |
|------|--------|
| Package einbinden | `composer require componist/mini-api` bzw. path-Repo + `composer update` |
| Config anpassen | `php artisan vendor:publish --tag=mini-api-config` |
| Endpoints definieren | In `config/mini-api.php` unter `endpoints` eintragen |
| API aufrufen | `GET /api/<route>` (optional Header `X-Api-Key` oder `?api_key=...`) |
| API-Key erzeugen | `php artisan mini-api:generate-key` (Optionen: `--show`, `--force`, `--length=64`) |
| Config per UI bauen | `.env`: `MINI_API_BUILDER_ENABLED=true`, dann `/mini-api-builder` im Browser |

---

## 9. Weitere Dokumentation

- **[KONZEPT.md](KONZEPT.md)** – Technisches Konzept, Config-Schema, Relationen (Joins/Eloquent), Auth.
- **[KONZEPT-STATUS.md](KONZEPT-STATUS.md)** – Umsetzungsstand Konzept vs. Implementierung.
