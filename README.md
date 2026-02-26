# Mini API (componist/mini-api)

**Expose your system's data easily—via simple GET requests.**

Config-driven read-only API for Laravel: GET only, maximum flexibility through configuration.

---

## Purpose

This package was built to **expose data from a system**—simply and with minimal effort:

- You want to make certain data (tables, models) **available to other systems, apps, or frontends**.
- Instead of writing controllers and routes for each resource, you define in **one config file** what is returned as JSON under which URL.
- The API deliberately limits itself to **simple GET requests**: no write access, no complex REST logic—just **expose and read data**.

Typical use cases: providing data to external partners, feeding mobile apps or frontends with read-only data, small export or feed interfaces without extra code.

---

## Important: GET only (read-only)

The Mini API is intentionally **read-only**:

- **Only the HTTP method GET is supported.**  
  There are no POST, PUT, PATCH, or DELETE endpoints.
- Each configured endpoint returns data as **JSON**; **no data is modified**.
- Ideal for: fetching data by external systems, mobile apps, frontends, feeds, or simple export interfaces—without write access to the database.

If you need a full REST API with Create/Update/Delete, use Laravel API Resources or your own controllers. The Mini API does not replace them—it exists solely to **expose a system's data via simple GET requests**.

---

## What is the Mini API?

The **Mini API** is a lean Laravel package that provides JSON endpoints through **a single config file**:

- You define **route**, **table or Eloquent model**, **columns**, and optionally **relations**.
- The API responds **only with JSON** (no HTML views).
- Optionally, an **API key** protects the endpoints (header or query parameter).
- A **Config Builder** (web UI) generates the config without writing PHP.

**Use case:** Expose a system's data simply and in a controlled way—for external systems, mobile apps, or frontends—without a full REST API and without write operations.

---

## Features at a glance

| Feature                           | Description                                                                                                                    |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| **GET only**                      | All endpoints are reachable only via GET; no write operations.                                                                 |
| **Config-driven**                 | No dedicated controller per endpoint—everything in `config/mini-api.php`.                                                      |
| **Table or model**                | Data source: **Query Builder** (table + columns) or **Eloquent model** (including relations).                                  |
| **Column selection**              | Per endpoint, define exactly which columns are returned (e.g. excluding `password`).                                           |
| **Eloquent relations**            | With a model: eager loading via `relations` (e.g. `user`, `user.role`).                                                        |
| **Relations with columns**        | Relations can be limited to specific columns: `'comments' => ['id', 'body']`.                                                  |
| **Joins without model**           | Without Eloquent: joins (including left join) with `foreign_key`, `columns`, and optional **alias** for nested objects.        |
| **Alias for joins**               | Join columns can be grouped into a nested object (e.g. `author: { name, email }`).                                             |
| **API key auth**                  | Optional: access only with a valid key (header `X-Api-Key` or query `api_key`).                                                |
| **Auth per endpoint**             | Global API key or per-endpoint key (endpoint config overrides global).                                                         |
| **Artisan: generate key**         | `php artisan mini-api:generate-key`—generates a key and writes it to `.env` (options: `--show`, `--force`, `--length`).        |
| **Config from database**          | `php artisan mini-api:config-from-database`—generates endpoints from all tables (options: `--exclude`, `--columns=list\|all`). |
| **Config Builder (web UI)**       | UI to build endpoint configs: pick tables/columns/models/relations, preview, copy, or write directly to config.                |
| **Multiple endpoints in builder** | Collect endpoints and add them together to `config/mini-api.php`.                                                              |
| **MySQL, SQLite, PostgreSQL**     | Support for common Laravel database drivers (including for builder and config-from-database).                                  |
| **JSON response**                 | Always `Content-Type: application/json`; 200 (data), 401 (invalid key), 404 (unknown endpoint).                                |

---

## Quick setup

1. **Install the package**

   ```bash
   composer require componist/mini-api
   ```

2. **Publish the config**

   ```bash
   php artisan vendor:publish --tag=mini-api-config
   ```

   This creates `config/mini-api.php` in your project.

3. **Generate endpoint config from database (optional)**  
   Pre-configure endpoints for all tables:
   ```bash
   php artisan mini-api:config-from-database
   ```
   Options: `--exclude=tables` to exclude tables, `--columns=list|all` for column selection. Then review and adjust the generated config in `config/mini-api.php`.

---

## Installation

### Add the package

**Via Composer (Packagist):**

```bash
composer require componist/mini-api
```

**As a local package (e.g. in a monorepo):** In your project's `composer.json`:

```json
{
  "repositories": [{ "type": "path", "url": "packages/mini-api" }],
  "require": {
    "componist/mini-api": "*"
  }
}
```

Then:

```bash
composer update
```

### Publish the config (recommended)

To customize endpoints, copy the package config into your project:

```bash
php artisan vendor:publish --tag=mini-api-config
```

This creates (or overwrites) `config/mini-api.php`. Without publishing, the app uses the default config from the package; add your own endpoints in the **published** `config/mini-api.php`.

---

## Configuration

The central file is **`config/mini-api.php`** (in your project after publishing, otherwise the package config).

### Required `.env` variables

The Mini API does **not** require any mandatory `.env` variables. You only add them when you want to enable features:

| Purpose                   | Variable                    | Example           |
| ------------------------- | --------------------------- | ----------------- |
| Enable API key protection | `MINI_API_AUTH_ENABLED`     | `true`            |
| Set API key               | `MINI_API_KEY`              | `your-secret-key` |
| Enable builder (dev)      | `MINI_API_BUILDER_ENABLED`  | `true`            |
| Builder only in debug     | `MINI_API_BUILDER_ONLY_DEV` | `true`            |

Note: `MINI_API_BUILDER_ONLY_DEV` defaults to `true` in the config. If you set it explicitly in `.env`, you override that default. `APP_DEBUG=true` must also be set for the builder to be reachable.

**Sample `.env` snippet (with defaults):**

```env
# Mini API (defaults)
MINI_API_AUTH_ENABLED=false
MINI_API_KEY=
MINI_API_BUILDER_ENABLED=true
MINI_API_BUILDER_ONLY_DEV=true
```

### Config structure

| Section     | Meaning                                                                       |
| ----------- | ----------------------------------------------------------------------------- |
| `auth`      | Optional: API key protection (global).                                        |
| `endpoints` | Array of all API endpoints (route, table/model, columns, optional relations). |
| `builder`   | Settings for the Config Builder (dev only).                                   |

### Defining endpoints

Each entry under `endpoints` becomes **one GET route**:  
`GET /api/<route>` → JSON with the configured data.

**Required per endpoint:**

- **`route`** – URL segment after `/api/` (e.g. `users` → `GET /api/users`).
- **`table`** OR **`model`** – Data source (table = Query Builder, model = Eloquent).
- **`columns`** – Array of columns (optional for model, default `['*']`).

**Options:**

- **`relations`** – With **model**: Eloquent relation names (e.g. `['user', 'user.role']`); with **table**: joins (array with `type`, `table`, `foreign_key`, `columns`, optional `alias`).
- **`auth`** – Optional: per-endpoint API key (overrides global `auth`).

---

### Example: Table only (Query Builder)

```php
'endpoints' => [
    'users' => [
        'route'   => 'users',
        'table'   => 'users',
        'columns' => ['id', 'name', 'email', 'created_at'],
    ],
],
```

- **Request:** `GET /api/users`
- **Response:** JSON array of objects with only these columns (e.g. without `password`).

---

### Example: Eloquent model with relations

```php
'endpoints' => [
    'posts' => [
        'route'     => 'posts',
        'model'     => \App\Models\Post::class,
        'columns'   => ['id', 'title', 'slug', 'user_id', 'created_at'],
        'relations' => ['user', 'user.role'],
    ],
],
```

- **Request:** `GET /api/posts`
- **Response:** JSON with nested objects `user` and `user.role` (eager loaded).

Relations can also be limited to specific columns:

```php
'relations' => [
    'user',
    'comments' => ['id', 'body'],  // only these columns for the relation
],
```

---

### Example: Joins (without model)

If you don't use Eloquent models, you can use **joins** to include related tables:

```php
'posts' => [
    'route'   => 'posts',
    'table'   => 'posts',
    'columns' => ['id', 'title', 'slug', 'user_id', 'category_id', 'created_at'],
    'relations' => [
        [
            'type'         => 'join',
            'table'        => 'users',
            'foreign_key'  => 'user_id',
            'columns'      => ['name as author_name', 'email as author_email'],
            'alias'        => 'author',   // optional: nested object in JSON
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

- **Without `alias`:** Columns appear at the top level (e.g. `author_name`, `author_email`).
- **With `alias`:** Columns are grouped into a nested object (e.g. `author: { author_name, author_email }`).

---

### API key (auth)

To allow API access only with a valid key:

**1. Generate key and add to `.env`:**

```bash
php artisan mini-api:generate-key
```

The command writes `MINI_API_KEY=<generated-key>` to `.env`. On first run it also sets `MINI_API_AUTH_ENABLED=true`.

**2. In the config (already the default after publishing):**

```php
'auth' => [
    'enabled' => env('MINI_API_AUTH_ENABLED', false),
    'key'     => env('MINI_API_KEY'),
    'header'  => 'X-Api-Key',
    'query'   => 'api_key',
],
```

- **`enabled`** and **`key`** set → every request must include the key.
- **Key via header:** e.g. `X-Api-Key: your-secret-key`.
- **Key via query:** e.g. `GET /api/users?api_key=your-secret-key`.
- Order: header is checked first, then query if header is missing.

**On invalid or missing key:** Response **401 Unauthorized** with `{"error": "Invalid or missing API key"}`.

**Different auth per endpoint:** An endpoint can have its own `auth` (e.g. different key), merged with global `auth`; endpoint config takes precedence.

---

## Calling the API (GET only)

### URLs

- Base: **`/api/`** + the **`route`** value from the config.
- **Only GET** is supported—reading the configured data. POST, PUT, PATCH, DELETE are not supported and result in 405 or are handled differently by Laravel.

Examples (when `route` is `users` or `posts`):

- `GET https://your-domain.com/api/users`
- `GET https://your-domain.com/api/posts`

### Without API key

```bash
curl -X GET "https://your-domain.com/api/users"
```

### With API key (header)

```bash
curl -X GET "https://your-domain.com/api/users" \
  -H "X-Api-Key: your-secret-key"
```

### With API key (query parameter)

```bash
curl "https://your-domain.com/api/users?api_key=your-secret-key"
```

### Response format

- **Success (200):** JSON array of objects with the configured columns (and relations).
- **401:** API key missing or invalid → `{"error": "Invalid or missing API key"}`.
- **404:** Unknown endpoint (route not in `endpoints` or invalid config).

---

## Artisan commands

### Generate API key: `mini-api:generate-key`

| Option        | Meaning                                                                                               |
| ------------- | ----------------------------------------------------------------------------------------------------- |
| (none)        | Generate new key and add to `.env`. If `MINI_API_KEY` already exists, command fails unless `--force`. |
| `--show`      | Output key to console only, **do not** write to `.env`.                                               |
| `--force`     | Overwrite existing `MINI_API_KEY` in `.env`.                                                          |
| `--length=64` | Key length (32–128 characters).                                                                       |

**Examples:**

```bash
# Generate key and write to .env (first run also sets MINI_API_AUTH_ENABLED=true)
php artisan mini-api:generate-key

# Show key only (e.g. for manual entry)
php artisan mini-api:generate-key --show

# Replace existing key
php artisan mini-api:generate-key --force

# Custom length
php artisan mini-api:generate-key --length=128
```

---

### Generate config from database: `mini-api:config-from-database`

Generates endpoint entries in `config/mini-api.php` for **all tables** in the current database. Useful to quickly have many GET endpoints without writing them by hand.

| Option           | Meaning                                                             |
| ---------------- | ------------------------------------------------------------------- |
| `--exclude=`     | Exclude tables (comma-separated, e.g. `migrations,sessions,cache`). |
| `--columns=list` | Per table, list all column names as array (default).                |
| `--columns=all`  | Per table, use `['*']` (all columns).                               |

**Prerequisite:** `config/mini-api.php` must exist (e.g. after `php artisan vendor:publish --tag=mini-api-config`).

**Examples:**

```bash
# All tables as endpoints, columns listed
php artisan mini-api:config-from-database

# Exclude specific tables
php artisan mini-api:config-from-database --exclude=migrations,sessions,jobs,failed_jobs

# All columns with [*]
php artisan mini-api:config-from-database --columns=all
```

Supported databases: MySQL, SQLite, PostgreSQL (and others if Laravel's schema API provides table names).

---

## Config Builder (web UI)

The **Config Builder** is a web UI to create endpoint configs with a few clicks (select tables/columns/relations, show PHP array, or write directly to `config/mini-api.php`).

### Enable the builder

In **`.env`** (recommended for development only):

```env
MINI_API_BUILDER_ENABLED=true
APP_DEBUG=true
```

If `MINI_API_BUILDER_ONLY_DEV=true` (default), the builder is only reachable when `APP_DEBUG=true`.

### Open the builder

In your browser, open the configured route (default):

**`/mini-api-builder`**

(e.g. `http://localhost:8000/mini-api-builder`)

The route can be changed in `config/mini-api.php` under `builder.route`.

### Builder workflow

1. **Select table** – List of all database tables (real tables only, no views).
2. **Columns** – Checkboxes for columns; “Select all” / “Deselect all”.
3. **Optional: Model** – If an Eloquent model exists for the table, you can select it (then relations are available).
4. **Optional: Relations** – With a model selected: relations as checkboxes (including nested, e.g. `user.role`).
5. **Endpoint key & route** – Config key (e.g. `users`) and API path (e.g. `users` → `/api/users`).
6. **Actions:**
   - **Add endpoint to list** – Add current endpoint to a list (for multiple endpoints).
   - **Preview** – Show generated PHP array.
   - **Copy** – Copy PHP array to clipboard.
   - **Write to config** – Insert endpoint(s) into `config/mini-api.php` (only if config was already published).

**Multiple endpoints:** Configure the first endpoint → “Add to list” → choose next table/columns/route → “Add to list” again → finally “Write to config” for all.

### Note

The builder only writes to an **existing** `config/mini-api.php` (e.g. after `php artisan vendor:publish --tag=mini-api-config`). If the file does not exist, an error is shown; you can use **Preview** and copy the content into the config manually.

---

## Configuration options reference

### Global: `auth`

| Key       | Meaning                                           |
| --------- | ------------------------------------------------- |
| `enabled` | `true` = require API key.                         |
| `key`     | Expected key (e.g. from `env('MINI_API_KEY')`).   |
| `header`  | HTTP header for the key (default: `X-Api-Key`).   |
| `query`   | Query parameter for the key (default: `api_key`). |

### Per endpoint

| Key         | Required? | Meaning                                                                                                                             |
| ----------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `route`     | yes       | URL path after `/api/` (GET only).                                                                                                  |
| `table`     | yes\*     | Database table (\*if no `model`).                                                                                                   |
| `model`     | yes\*     | Eloquent model class (\*if no `table`).                                                                                             |
| `columns`   | no        | Array of columns; for model default is `['*']`.                                                                                     |
| `relations` | no        | Eloquent: relation names (including dot for nested); Joins: array with `type`, `table`, `foreign_key`, `columns`, optional `alias`. |
| `auth`      | no        | Per-endpoint auth (overrides global).                                                                                               |

### Builder: `builder`

| Key        | Meaning                                            |
| ---------- | -------------------------------------------------- |
| `enabled`  | Enable builder route and APIs.                     |
| `only_dev` | `true` = only reachable when `APP_DEBUG=true`.     |
| `route`    | URL path of the builder (e.g. `mini-api-builder`). |

---

## Quick reference

| Goal                       | Action                                                                             |
| -------------------------- | ---------------------------------------------------------------------------------- | ----- |
| Add package                | `composer require componist/mini-api` or path repo + `composer update`             |
| Customize config           | `php artisan vendor:publish --tag=mini-api-config`                                 |
| Define endpoints           | Add entries under `endpoints` in `config/mini-api.php`                             |
| Call API (GET only)        | `GET /api/<route>` (optional header `X-Api-Key` or `?api_key=...`)                 |
| Generate API key           | `php artisan mini-api:generate-key` (options: `--show`, `--force`, `--length=64`)  |
| Generate endpoints from DB | `php artisan mini-api:config-from-database` (options: `--exclude`, `--columns=list | all`) |
| Build config via UI        | `.env`: `MINI_API_BUILDER_ENABLED=true`, then open `/mini-api-builder` in browser  |

---

## License

MIT (see [LICENSE](LICENSE)).
