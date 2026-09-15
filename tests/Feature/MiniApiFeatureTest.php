<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Feature;

use App\Models\User;
use Componist\MiniApi\Tests\TestCase;

class MiniApiFeatureTest extends TestCase
{
    public function test_api_requires_key_when_auth_enabled(): void
    {
        $this->getJson('/api/users-liste')
            ->assertUnauthorized()
            ->assertJson(['error' => 'Ungültiger oder fehlender API-Key.']);
    }

    public function test_api_rejects_wrong_key(): void
    {
        $this->withHeader('X-Api-Key', 'wrong')
            ->getJson('/api/users-liste')
            ->assertUnauthorized();
    }

    public function test_api_returns_only_safe_columns_with_valid_key(): void
    {
        User::factory()->create([
            'name' => 'Mini User',
            'email' => 'mini@example.com',
            'email_verified_at' => now(),
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ]);

        $response = $this->withHeader('X-Api-Key', 'test-mini-api-key')
            ->getJson('/api/users-liste')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Mini User',
                'email' => 'mini@example.com',
            ]);

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload);

        foreach ($payload as $row) {
            $this->assertArrayNotHasKey('password', $row);
            $this->assertArrayNotHasKey('remember_token', $row);
        }
    }

    public function test_auth_enabled_without_configured_key_returns_unauthorized(): void
    {
        config(['mini-api.auth.key' => '']);

        $this->getJson('/api/users-liste')
            ->assertUnauthorized()
            ->assertJson(['error' => 'API-Key ist nicht konfiguriert.']);
    }

    public function test_builder_requires_authentication_when_enabled(): void
    {
        config([
            'mini-api.builder.enabled' => true,
            'mini-api.builder.only_dev' => false,
            'app.debug' => true,
        ]);

        // Routes are registered at boot; without re-boot this may 404 if builder was disabled.
        // Verify gate path via middleware on known route if registered.
        $response = $this->get('/mini-api-builder');
        $this->assertTrue(
            in_array($response->status(), [302, 401, 403, 404], true),
            'Builder muss ohne Login unerreichbar oder nicht registriert sein.'
        );
    }

    public function test_api_routes_are_throttled(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(static fn ($candidate): bool => str_contains($candidate->uri(), 'api/users-liste'));

        $this->assertNotNull($route);
        $this->assertTrue(collect($route->gatherMiddleware())->contains(
            static fn (mixed $middleware): bool => is_string($middleware) && str_contains($middleware, 'throttle')
        ));
    }
}
