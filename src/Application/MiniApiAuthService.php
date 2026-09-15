<?php

declare(strict_types=1);

namespace Componist\MiniApi\Application;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MiniApiAuthService
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function authorizeRequest(Request $request, array $config): ?JsonResponse
    {
        $auth = array_merge(config('mini-api.auth', []), $config['auth'] ?? []);

        if (empty($auth['enabled'])) {
            return null;
        }

        $expected = (string) ($auth['key'] ?? '');
        if ($expected === '') {
            return response()->json(['error' => 'API-Key ist nicht konfiguriert.'], 401);
        }

        $provided = (string) ($request->header($auth['header'] ?? 'X-Api-Key')
            ?? $request->query($auth['query'] ?? 'api_key')
            ?? '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Ungültiger oder fehlender API-Key.'], 401);
        }

        return null;
    }
}
