<?php

declare(strict_types=1);

namespace Componist\MiniApi\Http\Controllers;

use Componist\MiniApi\Application\MiniApiAuthService;
use Componist\MiniApi\Domain\MiniApiIdentifierRules;
use Componist\MiniApi\Domain\MiniApiPageRequest;
use Componist\MiniApi\Infrastructure\MiniApiJoinQueryBuilder;
use Componist\MiniApi\Infrastructure\MiniApiQueryPager;
use Componist\MiniApi\Infrastructure\MiniApiSchemaGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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

            $page = MiniApiPageRequest::fromQuery(
                $request->query('page'),
                $request->query('per_page'),
                $this->resolveLimit($config),
            );

            if (isset($config['model'])) {
                return $this->respondWithModel($config, $columns, $page);
            }

            return $this->respondWithTable($config, $columns, $page);
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
    protected function respondWithModel(array $config, array $columns, ?MiniApiPageRequest $page): JsonResponse
    {
        $modelClass = $config['model'];
        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return response()->json(['error' => 'Ungültige Model-Konfiguration.'], 500);
        }

        $columns = MiniApiSchemaGuard::filterModelColumns($columns);
        if ($columns === []) {
            return response()->json(['error' => 'Keine erlaubten Spalten konfiguriert.'], 500);
        }

        $query = $modelClass::query()->select($columns);
        if (MiniApiSchemaGuard::isBlockedTable($query->getModel()->getTable())) {
            return response()->json(['error' => 'Tabelle ist nicht erlaubt.'], 403);
        }

        $limit = $this->resolveLimit($config);
        $relations = $this->normalizeRelations($query->getModel(), $config['relations'] ?? []);
        if ($relations !== []) {
            $query->with($relations);
        }
        $orderColumn = MiniApiSchemaGuard::resolveModelOrderColumn($query->getModel(), $config['order_by'] ?? null);
        [$data, $headers] = MiniApiQueryPager::fetch($query, $limit, $page, $orderColumn);

        $allowedKeys = array_flip($columns);
        $data = $data->map(function (Model $model) use ($allowedKeys) {
            $arr = $model->toArray();
            $main = array_intersect_key($arr, $allowedKeys);
            $relations = MiniApiSchemaGuard::stripBlockedFields(array_diff_key($arr, $allowedKeys));

            return array_merge($main, $relations);
        });

        return response()->json($data, 200, $headers);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $columns
     */
    protected function respondWithTable(array $config, array $columns, ?MiniApiPageRequest $page): JsonResponse
    {
        $table = MiniApiIdentifierRules::sanitizeIdentifier((string) ($config['table'] ?? ''));
        if ($table === '' || ! MiniApiSchemaGuard::isAllowedTable($table)) {
            return response()->json(['error' => 'Tabelle ist nicht erlaubt.'], 403);
        }

        $columns = MiniApiSchemaGuard::filterColumns($table, $columns);
        if ($columns === []) {
            return response()->json(['error' => 'Keine erlaubten Spalten konfiguriert.'], 500);
        }

        $orderColumn = MiniApiSchemaGuard::resolveOrderColumn($table, $config['order_by'] ?? null);
        if ($page !== null && $orderColumn === null) {
            return response()->json(['error' => 'Pagination benötigt einen Primärschlüssel oder `order_by`.'], 500);
        }

        $relations = (array) ($config['relations'] ?? []);
        $query = MiniApiJoinQueryBuilder::build($table, $columns, $relations);
        [$data, $headers] = MiniApiQueryPager::fetch($query, $this->resolveLimit($config), $page, $orderColumn);

        return response()->json(MiniApiJoinQueryBuilder::reshape($data, $columns, $relations), 200, $headers);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveLimit(array $config): int
    {
        $configured = (int) ($config['limit'] ?? config('mini-api.max_rows', 500));

        return max(1, min($configured, (int) config('mini-api.max_rows', 500)));
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return array<int|string, mixed>
     */
    protected function normalizeRelations(Model $model, array $relations): array
    {
        $normalized = [];
        foreach ($relations as $key => $value) {
            $name = is_int($key) ? $value : $key;
            // `relation:col1,col2` ist Eloquent-Syntax für eine Spaltenauswahl; geprüft wird nur der Relationsname.
            if (! is_string($name) || ! MiniApiSchemaGuard::isAllowedRelationPath($model, explode(':', $name)[0])) {
                continue;
            }

            if (is_string($key) && is_array($value)) {
                $normalized[$key] = fn ($q) => $q->select(MiniApiSchemaGuard::filterModelColumns($value));
            } else {
                $normalized[] = $name;
            }
        }

        return $normalized;
    }
}
