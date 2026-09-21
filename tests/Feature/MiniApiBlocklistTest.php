<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Feature;

use App\Models\User;
use Componist\MiniApi\Http\Controllers\MiniApiController;
use Componist\MiniApi\Tests\Support\CreatesJoinTables;
use Componist\MiniApi\Tests\Support\SessionStub;
use Componist\MiniApi\Tests\Support\UserStub;
use Componist\MiniApi\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MiniApiBlocklistTest extends TestCase
{
    use CreatesJoinTables;

    public function test_join_columns_cannot_bypass_blocked_fields(): void
    {
        $this->createJoinTables();
        $this->configureEndpoint('joined', [
            'table' => self::MAIN_TABLE,
            'columns' => ['id', 'target_id'],
            'relations' => [[
                'table' => self::JOIN_TABLE,
                'foreign_key' => 'target_id',
                'alias' => 'target',
                'columns' => [
                    self::JOIN_TABLE.'.label as label',
                    self::JOIN_TABLE.'.secret_token as leaked',
                    self::JOIN_TABLE.'.password',
                    'users.email as foreign',
                ],
            ]],
        ]);

        $response = $this->requestEndpoint('joined');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['label' => 'Ziel'], $response->getData(true)[0]['target']);
    }

    public function test_model_relations_strip_blocked_fields(): void
    {
        User::factory()->create();
        $this->configureEndpoint('users_model', [
            'model' => UserStub::class,
            'columns' => ['id', 'email'],
            'relations' => ['accounts'],
        ]);

        $row = $this->requestEndpoint('users_model')->getData(true)[0];

        $this->assertArrayHasKey('email', $row['accounts'][0]);
        $this->assertArrayNotHasKey('password', $row['accounts'][0]);
        $this->assertArrayNotHasKey('remember_token', $row['accounts'][0]);
    }

    public function test_relations_to_blocked_tables_and_non_relation_methods_are_dropped(): void
    {
        User::factory()->create();
        $this->configureEndpoint('users_model', [
            'model' => UserStub::class,
            'columns' => ['id', 'email'],
            'relations' => ['loginSessions', 'notARelation', 'delete', 'accounts:id,email'],
        ]);

        $row = $this->requestEndpoint('users_model')->getData(true)[0];

        $this->assertArrayNotHasKey('login_sessions', $row);
        $this->assertArrayHasKey('accounts', $row);
        $this->assertSame(1, User::query()->count());
    }

    public function test_model_on_blocked_table_is_forbidden(): void
    {
        $this->configureEndpoint('sessions_model', ['model' => SessionStub::class, 'columns' => ['id']]);

        $this->assertSame(403, $this->requestEndpoint('sessions_model')->getStatusCode());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function configureEndpoint(string $key, array $config): void
    {
        config(["mini-api.endpoints.{$key}" => $config + ['auth' => ['enabled' => false]]]);
    }

    private function requestEndpoint(string $endpoint): JsonResponse
    {
        return $this->app->make(MiniApiController::class)->show(Request::create('/'), $endpoint);
    }
}
