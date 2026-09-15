<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mini-api.auth.enabled' => true,
            'mini-api.auth.key' => 'test-mini-api-key',
            'mini-api.max_rows' => 500,
            'mini-api.endpoints.users' => [
                'route' => 'users-liste',
                'table' => 'users',
                'columns' => ['id', 'name', 'email', 'created_at', 'password'],
                'limit' => 100,
            ],
        ]);
    }
}
