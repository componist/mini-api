<?php

declare(strict_types=1);

namespace Componist\MiniApi\Http\Controllers;

use Componist\MiniApi\Application\MiniApiAuthService;
use Componist\MiniApi\Domain\MiniApiIdentifierRules;
use Componist\MiniApi\Infrastructure\MiniApiSchemaGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class MiniApiController extends Controller
{
    public function show(Request $request, string $endpoint): JsonResponse
    {
        $config = config("mini-api.endpoints.{$endpoint}");
        if (! $config || (! isset($config['table']) && ! isset($config['model']))) {
            abort(404);
        }

        $authResponse = MiniApiAuthService::authorizeRequest($request, $config);
        if ($authResponse !== null) {
            return $authResponse;
        }

        try {
            $columns = $config['columns'] ?? null;
            if (! is_array($columns) || $columns === [] || in_array('*', $columns, true)) {
                return response()->json([
                    'error' => 'Endpoint muss explizite Spalten definieren (kein *).',
                ], 500);
            }

            if (isset($config['model'])) {
                return $this->respondWithModel($config, $columns);
            }

            return $this->respondWithTable($config, $columns);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(
                ['error' => 'Beim Abrufen der Daten ist ein Fehler aufgetreten.'],
                500
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $columns
     */
    protected function respondWithModel(array $config, array $columns): JsonResponse
    {
        $modelClass = $config['model'];
        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return response()->json(['error' => 'Ungültige Model-Konfiguration.'], 500);
        }

        $columns = MiniApiSchemaGuard::filterModelColumns($columns);
        if ($columns === []) {
            return response()->json(['error' => 'Keine erlaubten Spalten konfiguriert.'], 500);
        }

        $limit = $this->resolveLimit($config);
        $query = $modelClass::query()->select($columns);
        $relations = $this->normalizeRelations($config['relations'] ?? []);
        if ($relations !== []) {
            $query->with($relations);
        }
        $data = $query->limit($limit)->get();

        $allowedKeys = array_flip($columns);
        $data = $data->map(function (Model $model) use ($allowedKeys) {
            $arr = $model->toArray();
            $main = array_intersect_key($arr, $allowedKeys);
            $relations = array_diff_key($arr, $allowedKeys);

            return array_merge($main, $relations);
        });

        return response()->json($data);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $columns
     */
    protected function respondWithTable(array $config, array $columns): JsonResponse
    {
        $table = $this->sanitizeTableName((string) ($config['table'] ?? ''));
        if ($table === '' || ! MiniApiSchemaGuard::isAllowedTable($table)) {
            return response()->json(['error' => 'Tabelle ist nicht erlaubt.'], 403);
        }

        $columns = MiniApiSchemaGuard::filterColumns($table, $columns);
        if ($columns === []) {
            return response()->json(['error' => 'Keine erlaubten Spalten konfiguriert.'], 500);
        }

        $limit = $this->resolveLimit($config);
        $query = DB::table($table)->select($columns);
        $relations = $config['relations'] ?? [];

        foreach ($relations as $rel) {
            if (! is_array($rel)) {
                continue;
            }
            $joinTable = $this->sanitizeTableName((string) ($rel['table'] ?? ''));
            $foreignKey = $this->sanitizeIdentifier((string) ($rel['foreign_key'] ?? ''));
            if ($joinTable === '' || $foreignKey === '' || ! MiniApiSchemaGuard::isAllowedTable($joinTable)) {
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

        $data = $query->limit($limit)->get();
        $data = $this->groupJoinAliases($data, $relations);

        $allowedMainKeys = array_flip($columns);
        $aliasNames = $this->getJoinAliasNames($relations);
        $data = $data->map(function ($row) use ($allowedMainKeys, $aliasNames) {
            $arr = (array) $row;
            $out = [];
            foreach ($arr as $k => $v) {
                if (isset($allowedMainKeys[$k]) || isset($aliasNames[$k])) {
                    $out[$k] = $v;
                }
            }

            return is_object($row) ? (object) $out : $out;
        });

        return response()->json($data);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveLimit(array $config): int
    {
        $configured = (int) ($config['limit'] ?? config('mini-api.max_rows', 500));

        return max(1, min($configured, (int) config('mini-api.max_rows', 500)));
    }

    protected function sanitizeTableName(string $table): string
    {
        return MiniApiIdentifierRules::sanitizeIdentifier($table);
    }

    protected function sanitizeIdentifier(string $value): string
    {
        return MiniApiIdentifierRules::sanitizeIdentifier($value);
    }

    protected function isSafeJoinColumnExpression(string $col): bool
    {
        return MiniApiIdentifierRules::isSafeJoinColumnExpression($col);
    }

    /**
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
                $normalized[$key] = fn ($q) => $q->select(MiniApiSchemaGuard::filterModelColumns($value));
            } else {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return array<string, true>
     */
    protected function getJoinAliasNames(array $relations): array
    {
        $aliases = [];
        foreach ($relations as $rel) {
            if (is_array($rel) && ! empty($rel['alias'])) {
                $aliases[(string) $rel['alias']] = true;
            }
        }

        return $aliases;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $rows
     * @param  array<int|string, mixed>  $relations
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    protected function groupJoinAliases($rows, array $relations)
    {
        $aliases = [];
        foreach ($relations as $rel) {
            if (is_array($rel) && ! empty($rel['alias'])) {
                $aliases[$rel['alias']] = $rel['columns'] ?? [];
            }
        }
        if ($aliases === []) {
            return $rows;
        }

        return $rows->map(function ($row) use ($aliases) {
            $arr = (array) $row;
            $out = [];
            $used = [];
            foreach ($aliases as $alias => $cols) {
                foreach ($cols as $col) {
                    $parts = preg_split('/\s+as\s+/i', (string) $col, 2);
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
