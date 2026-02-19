<?php

namespace Componist\MiniApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;

class MiniApiBuilderController extends Controller
{
    /**
     * Builder-UI anzeigen.
     */
    public function index()
    {
        return view('mini-api::builder');
    }

    /**
     * GET /mini-api-builder/api/tables – Liste aller Tabellen (nur echte Tabellen, keine Views).
     */
    public function tables(): JsonResponse
    {
        $tables = $this->getDatabaseTableNamesOnly();
        return response()->json(['tables' => $tables]);
    }

    /**
     * GET /mini-api-builder/api/tables/{table}/columns – Spalten einer Tabelle.
     */
    public function columns(string $table): JsonResponse
    {
        $table = $this->sanitizeTableName($table);
        $columns = Schema::getColumnListing($table);

        return response()->json(['columns' => array_values(array_unique((array) $columns))]);
    }

    /**
     * GET /mini-api-builder/api/models – Liste der App-Models (App\Models\*).
     */
    public function models(): JsonResponse
    {
        $modelsPath = app_path('Models');
        if (! File::isDirectory($modelsPath)) {
            return response()->json(['models' => []]);
        }

        $models = [];
        foreach (File::files($modelsPath) as $file) {
            $className = 'App\\Models\\' . $file->getFilenameWithoutExtension();
            if (class_exists($className) && is_subclass_of($className, \Illuminate\Database\Eloquent\Model::class)) {
                $instance = new $className;
                $table = $instance->getTable();
                $models[] = [
                    'class' => $className,
                    'table' => $table,
                    'name' => class_basename($className),
                ];
            }
        }

        return response()->json(['models' => $models]);
    }

    /**
     * GET /mini-api-builder/api/models/{model}/relations – Relationen eines Models (inkl. Baum).
     */
    public function relations(string $model): JsonResponse
    {
        $model = str_replace('.', '\\', $model);
        if (! class_exists($model) || ! is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class)) {
            return response()->json(['relations' => [], 'nested' => []], 404);
        }

        $flat = $this->getModelRelations($model);
        $nested = $this->getNestedRelationsTree($model, '');

        return response()->json(['relations' => $flat, 'nested' => $nested]);
    }

    /**
     * POST /mini-api-builder/api/config – Endpoint-Config in config/mini-api.php anhängen (optional).
     */
    public function storeConfig(Request $request): JsonResponse
    {
        $configPath = config_path('mini-api.php');
        if (! file_exists($configPath)) {
            return response()->json(['error' => 'config/mini-api.php nicht gefunden. Bitte zuerst publishen.'], 422);
        }

        $endpointsToWrite = [];

        if ($request->has('endpoints') && is_array($request->input('endpoints'))) {
            $request->validate([
                'endpoints' => 'required|array',
                'endpoints.*.key' => 'required|string|max:100',
                'endpoints.*.route' => 'required|string|max:100',
                'endpoints.*.table' => 'nullable|string|max:100',
                'endpoints.*.model' => 'nullable|string|max:255',
                'endpoints.*.columns' => 'required|array',
                'endpoints.*.columns.*' => 'string|max:100',
                'endpoints.*.relations' => 'nullable|array',
                'endpoints.*.relations.*' => 'string|max:255',
            ]);
            foreach ($request->input('endpoints') as $ep) {
                $endpointsToWrite[] = [
                    'key' => Str::slug($ep['key'] ?? '', '_'),
                    'route' => Str::slug($ep['route'] ?? '', '-'),
                    'table' => $ep['table'] ?? null,
                    'model' => $ep['model'] ?? null,
                    'columns' => $ep['columns'] ?? [],
                    'relations' => $ep['relations'] ?? [],
                ];
            }
        } else {
            $request->validate([
                'key' => 'required|string|max:100',
                'route' => 'required|string|max:100',
                'table' => 'nullable|string|max:100',
                'model' => 'nullable|string|max:255',
                'columns' => 'required|array',
                'columns.*' => 'string|max:100',
                'relations' => 'nullable|array',
                'relations.*' => 'string|max:255',
            ]);
            $endpointsToWrite[] = [
                'key' => Str::slug($request->input('key'), '_'),
                'route' => Str::slug($request->input('route'), '-'),
                'table' => $request->input('table'),
                'model' => $request->input('model'),
                'columns' => $request->input('columns', []),
                'relations' => $request->input('relations', []),
            ];
        }

        if (empty($endpointsToWrite)) {
            return response()->json(['error' => 'Keine Endpoints zum Schreiben.'], 422);
        }

        $content = file_get_contents($configPath);
        $blocks = [];
        foreach ($endpointsToWrite as $ep) {
            $key = $ep['key'];
            $endpoint = [
                'route' => $ep['route'],
                'columns' => $ep['columns'],
            ];
            if (! empty($ep['model'])) {
                $endpoint['model'] = $ep['model'];
            } else {
                $endpoint['table'] = $ep['table'] ?: $key;
            }
            if (! empty($ep['relations'])) {
                $endpoint['relations'] = $ep['relations'];
            }
            $blocks[] = "        '" . $this->escapePhpSingleQuotedString($key) . "' => " . $this->exportArray($endpoint);
        }
        $newBlock = "\n        " . implode(",\n        ", $blocks) . ",\n    ],";

        $posEndpoints = strpos($content, "'endpoints' => [");
        if ($posEndpoints === false) {
            return response()->json(['error' => 'Config konnte nicht eingefügt werden (endpoints-Block nicht gefunden).'], 422);
        }
        $posAfterEndpoints = $posEndpoints + strlen("'endpoints' => [");
        $posBuilder = strpos($content, "'builder' =>", $posAfterEndpoints);
        $sectionEnd = $posBuilder !== false ? $posBuilder : strlen($content);
        $section = substr($content, $posEndpoints, $sectionEnd - $posEndpoints);
        $lastClosing = strrpos($section, "    ],");
        if ($lastClosing === false) {
            return response()->json(['error' => 'Config konnte nicht eingefügt werden (endpoints-Block nicht gefunden).'], 422);
        }
        $replaceAt = $posEndpoints + $lastClosing;
        $content = substr_replace($content, $newBlock, $replaceAt, 6);

        if (! file_put_contents($configPath, $content)) {
            return response()->json(['error' => 'Config-Datei konnte nicht geschrieben werden.'], 500);
        }

        $msg = count($endpointsToWrite) > 1
            ? count($endpointsToWrite) . ' Endpoints in config/mini-api.php eingetragen.'
            : 'Endpoint in config/mini-api.php eingetragen.';
        return response()->json(['success' => true, 'message' => $msg]);
    }

    /**
     * Relationen eines Models ermitteln (öffentliche Methoden, die eine Relation zurückgeben).
     *
     * @return list<string>
     */
    protected function getModelRelations(string $modelClass): array
    {
        $relations = [];
        $model = new $modelClass;

        foreach (get_class_methods($model) as $method) {
            if (Str::startsWith($method, '__')) {
                continue;
            }
            try {
                $ref = new ReflectionMethod($modelClass, $method);
                if ($ref->getNumberOfRequiredParameters() > 0) {
                    continue;
                }
                $result = $ref->invoke($model);
                if ($result instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    $relations[] = $method;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $relations;
    }

    /**
     * Verschachtelte Relationen als Baum (für Checkboxen z. B. company.country).
     *
     * @return array<string, mixed>
     */
    protected function getNestedRelationsTree(string $modelClass, string $prefix, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }
        $tree = [];
        $flat = $this->getModelRelations($modelClass);
        $model = new $modelClass;

        foreach ($flat as $relName) {
            $key = $prefix ? $prefix . '.' . $relName : $relName;
            $tree[$key] = $key;
            try {
                $relation = $model->{$relName}();
                $related = get_class($relation->getRelated());
                $children = $this->getNestedRelationsTree($related, $key, $depth + 1);
                foreach ($children as $childKey => $childVal) {
                    $tree[$childKey] = $childVal;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $tree;
    }

    /**
     * Liefert nur echte Datenbank-Tabellen (keine Views, keine System-Tabellen).
     */
    protected function getDatabaseTableNamesOnly(): array
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $result = DB::select(
                "SELECT table_name AS name FROM information_schema.tables " .
                "WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
                [$database]
            );
            return array_values(array_unique(array_map(fn ($r) => $r->name, $result)));
        }

        if ($driver === 'sqlite') {
            $result = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );
            return array_values(array_unique(array_map(fn ($r) => $r->name, $result)));
        }

        if ($driver === 'pgsql') {
            $result = DB::select(
                "SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
            );
            return array_values(array_unique(array_map(fn ($r) => $r->name, $result)));
        }

        // Fallback: Laravel Schema (liefert je nach Driver nur Tabellen)
        $schema = Schema::getConnection()->getSchemaBuilder();
        if (method_exists($schema, 'getTableListing')) {
            $names = $schema->getTableListing(null, false);
            return array_values(array_unique((array) $names));
        }

        return [];
    }

    protected function sanitizeTableName(string $table): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: 'users';
    }

    /**
     * String für PHP single-quoted Literal escapen (nur \ und ').
     */
    protected function escapePhpSingleQuotedString(string $s): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
    }

    protected function exportArray(array $arr): string
    {
        $parts = [];
        foreach ($arr as $k => $v) {
            $key = is_string($k) ? "'" . $this->escapePhpSingleQuotedString($k) . "'" : $k;
            if (is_array($v)) {
                $val = $this->exportArray($v);
                $parts[] = $key . ' => ' . $val;
            } elseif ($v === null) {
                $parts[] = $key . ' => null';
            } elseif (is_bool($v)) {
                $parts[] = $key . ' => ' . ($v ? 'true' : 'false');
            } elseif (is_string($v)) {
                $parts[] = $key . " => '" . $this->escapePhpSingleQuotedString($v) . "'";
            } else {
                $parts[] = $key . ' => ' . $v;
            }
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
