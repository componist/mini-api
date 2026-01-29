<?php

namespace Componist\MiniApi\Http\Controllers;

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

        // Auth: API-Key prüfen (global oder pro Endpoint)
        $auth = array_merge(config('mini-api.auth', []), $config['auth'] ?? []);
        if (! empty($auth['enabled']) && ! empty($auth['key'])) {
            $provided = $request->header($auth['header'] ?? 'X-Api-Key')
                ?? $request->query($auth['query'] ?? 'api_key');
            if ($provided !== $auth['key']) {
                return response()->json(['error' => 'Invalid or missing API key'], 401);
            }
        }

        $columns = $config['columns'] ?? ['*'];

        if (isset($config['model'])) {
            $query = $config['model']::query()
                ->select($columns);
            $relations = $this->normalizeRelations($config['relations'] ?? []);
            if (! empty($relations)) {
                $query->with($relations);
            }
            $data = $query->get();
        } else {
            $table = $config['table'];
            $query = DB::table($table)->select($columns);
            $relations = $config['relations'] ?? [];
            foreach ($relations as $rel) {
                if (is_array($rel)) {
                    $mainTable = $table;
                    $type = $rel['type'] ?? 'join';
                    $joinTable = $rel['table'];
                    $foreignKey = $rel['foreign_key'] ?? null;
                    $relColumns = $rel['columns'] ?? [];
                    if (! $foreignKey) {
                        continue;
                    }
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
                    foreach ($relColumns as $col) {
                        $query->addSelect(DB::raw($col));
                    }
                }
            }
            $data = $query->get();
            // Optional: bei alias Unterobjekte bilden
            $data = $this->groupJoinAliases($data, $relations);
        }

        return response()->json($data);
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
