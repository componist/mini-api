<?php

namespace Componist\MiniApi\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class MiniApiController extends Controller
{
    /** Erlaubte Zeichen für Tabellen-/Spaltennamen (Identifier). */
    private const SAFE_IDENTIFIER_PATTERN = '/^[a-zA-Z0-9_.\s]+$/';

    public function show(Request $request, string $endpoint): JsonResponse
    {
        $config = config("mini-api.endpoints.{$endpoint}");
        if (! $config || (! isset($config['table']) && ! isset($config['model']))) {
            abort(404);
        }

        // Auth: API-Key prüfen (global oder pro Endpoint)
        $auth = array_merge(config('mini-api.auth', []), $config['auth'] ?? []);
        if (! empty($auth['enabled']) && ! empty($auth['key'])) {
            $provided = $request->header($auth['header'] ?? 'X-Api-Key')
                ?? $request->query($auth['query'] ?? 'api_key');
            if ($provided !== $auth['key']) {
                return response()->json(['error' => 'Invalid or missing API key'], 401);
            }
        }

        try {
            $columns = $config['columns'] ?? ['*'];
            if (! is_array($columns) || $columns === []) {
                $columns = ['*'];
            }

            if (isset($config['model'])) {
                return $this->respondWithModel($config, $columns);
            }

            return $this->respondWithTable($config, $columns);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(
                ['error' => 'An error occurred while fetching data.'],
                500
            );
        }
    }

    /**
     * Daten über Eloquent-Model auslesen und als JSON liefern.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $columns
     */
    protected function respondWithModel(array $config, array $columns): JsonResponse
    {
        $modelClass = $config['model'];
        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return response()->json(['error' => 'Invalid or missing model configuration.'], 500);
        }

        $query = $modelClass::query()->select($columns);
        $relations = $this->normalizeRelations($config['relations'] ?? []);
        if (! empty($relations)) {
            $query->with($relations);
        }
        $data = $query->get();

        // Nur konfigurierte Spalten ausgeben (Relationen bleiben erhalten)
        if ($columns !== ['*']) {
            $allowedKeys = array_flip($columns);
            $data = $data->map(function (Model $model) use ($allowedKeys) {
                $arr = $model->toArray();
                $main = array_intersect_key($arr, $allowedKeys);
                $relations = array_diff_key($arr, $allowedKeys);
                return array_merge($main, $relations);
            });
        }

        return response()->json($data);
    }

    /**
     * Daten über Query Builder (Tabelle + optionale Joins) auslesen und als JSON liefern.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $columns
     */
    protected function respondWithTable(array $config, array $columns): JsonResponse
    {
        $table = $this->sanitizeTableName((string) ($config['table'] ?? ''));
        if ($table === '') {
            return response()->json(['error' => 'Invalid or missing table configuration.'], 500);
        }

        $query = DB::table($table)->select($columns);
        $relations = $config['relations'] ?? [];

        foreach ($relations as $rel) {
            if (! is_array($rel)) {
                continue;
            }
            $joinTable = $this->sanitizeTableName((string) ($rel['table'] ?? ''));
            $foreignKey = $this->sanitizeIdentifier((string) ($rel['foreign_key'] ?? ''));
            if ($joinTable === '' || $foreignKey === '') {
                continue;
            }
            $type = ($rel['type'] ?? 'join') === 'left_join' ? 'left_join' : 'join';
            $mainTable = $table;

            if ($type === 'left_join') {
                $query->leftJoin(
                    $joinTable,
                    "{$mainTable}.{$foreignKey}",
                    '=',
                    "{$joinTable}.id"
                );
            } else {
                $query->join(
                    $joinTable,
                    "{$mainTable}.{$foreignKey}",
                    '=',
                    "{$joinTable}.id"
                );
            }

            $relColumns = $rel['columns'] ?? [];
            foreach ((array) $relColumns as $col) {
                if (is_string($col) && $this->isSafeJoinColumnExpression($col)) {
                    $query->addSelect(DB::raw($col));
                }
            }
        }

        $data = $query->get();
        $data = $this->groupJoinAliases($data, $relations);

        // Nur konfigurierte Spalten der Haupttabelle ausgeben (Join-Aliase bleiben erhalten)
        if ($columns !== ['*']) {
            $allowedMainKeys = array_flip($columns);
            $aliasNames = $this->getJoinAliasNames($relations);
            $data = $data->map(function ($row) use ($allowedMainKeys, $aliasNames) {
                $arr = (array) $row;
                $out = [];
                foreach ($arr as $k => $v) {
                    if (isset($allowedMainKeys[$k])) {
                        $out[$k] = $v;
                    } elseif (isset($aliasNames[$k])) {
                        $out[$k] = $v;
                    }
                }
                return is_object($row) ? (object) $out : $out;
            });
        }

        return response()->json($data);
    }

    /**
     * Tabellenname auf sichere Zeichen beschränken (SQL-Injection vermeiden).
     */
    protected function sanitizeTableName(string $table): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

        return $sanitized ?? '';
    }

    /**
     * Einzelnen Identifier (z. B. foreign_key) bereinigen.
     */
    protected function sanitizeIdentifier(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $value);

        return $sanitized ?? '';
    }

    /**
     * Join-Spaltenausdruck erlauben nur wenn er wie "col" oder "col as alias" aussieht (kein rohes SQL).
     */
    protected function isSafeJoinColumnExpression(string $col): bool
    {
        return (bool) preg_match(self::SAFE_IDENTIFIER_PATTERN, $col);
    }

    /**
     * Normalisiert relations für Eloquent with(): Strings und 'relation' => ['col1','col2'].
     *
     * @param  array<int|string, mixed>  $relations
     * @return array<int|string, mixed>
     */
    protected function normalizeRelations(array $relations): array
    {
        $normalized = [];
        foreach ($relations as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $normalized[] = $value;
            } elseif (is_string($key) && is_array($value)) {
                $normalized[$key] = fn ($q) => $q->select($value);
            } else {
                $normalized[] = $value;
            }
        }
        return $normalized;
    }

    /**
     * Liefert die Alias-Namen der Joins (für Filterung: diese Keys beibehalten).
     *
     * @param  array  $relations
     * @return array<string, true>
     */
    protected function getJoinAliasNames(array $relations): array
    {
        $aliases = [];
        foreach ($relations as $rel) {
            if (is_array($rel) && ! empty($rel['alias'])) {
                $aliases[$rel['alias']] = true;
            }
        }
        return $aliases;
    }

    /**
     * Gruppiert Join-Spalten in Unterobjekte wenn alias gesetzt.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @param  array  $relations
     * @return \Illuminate\Support\Collection
     */
    protected function groupJoinAliases($rows, array $relations)
    {
        $aliases = [];
        foreach ($relations as $rel) {
            if (is_array($rel) && ! empty($rel['alias'])) {
                $aliases[$rel['alias']] = $rel['columns'] ?? [];
            }
        }
        if (empty($aliases)) {
            return $rows;
        }
        return $rows->map(function ($row) use ($aliases) {
            $arr = (array) $row;
            $out = [];
            $used = [];
            foreach ($aliases as $alias => $cols) {
                foreach ($cols as $col) {
                    $parts = preg_split('/\s+as\s+/i', $col, 2);
                    $key = isset($parts[1]) ? trim($parts[1]) : trim($parts[0]);
                    if (array_key_exists($key, $arr)) {
                        $used[$key] = true;
                        $out[$alias][$key] = $arr[$key];
                    }
                }
            }
            foreach ($arr as $k => $v) {
                if (! isset($used[$k])) {
                    $out[$k] = $v;
                }
            }
            return $out;
        });
    }
}
