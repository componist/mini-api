<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Feature;

use Componist\MiniApi\Http\Controllers\MiniApiController;
use Componist\MiniApi\Tests\Support\CreatesJoinTables;
use Componist\MiniApi\Tests\TestCase;
use Illuminate\Http\Request;

class MiniApiJoinQueryBuilderTest extends TestCase
{
    use CreatesJoinTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createJoinTables();
    }

    public function test_inner_join_groups_columns_under_alias_and_drops_unmatched_rows(): void
    {
        $rows = $this->fetchRows(['alias' => 'target', 'columns' => [self::JOIN_TABLE.'.label']]);

        $this->assertSame([['target' => ['label' => 'Ziel'], 'id' => 1, 'target_id' => 1]], $rows);
    }

    public function test_left_join_keeps_unmatched_rows_with_null_values(): void
    {
        $rows = $this->fetchRows(['type' => 'left_join', 'alias' => 'target', 'columns' => [self::JOIN_TABLE.'.label as name']]);

        $this->assertCount(2, $rows);
        $this->assertSame(['name' => 'Ziel'], $rows[0]['target']);
        $this->assertSame(['name' => null], $rows[1]['target']);
    }

    public function test_join_columns_without_alias_are_not_exposed(): void
    {
        $rows = $this->fetchRows(['columns' => [self::JOIN_TABLE.'.label as label']]);

        $this->assertSame([['id' => 1, 'target_id' => 1]], $rows);
    }

    public function test_join_on_blocked_table_is_ignored(): void
    {
        $rows = $this->fetchRows(['table' => 'sessions', 'type' => 'left_join', 'columns' => ['sessions.id']]);

        $this->assertCount(2, $rows);
        $this->assertSame(['id' => 1, 'target_id' => 1], $rows[0]);
    }

    /**
     * @param  array<string, mixed>  $relation
     * @return list<array<string, mixed>>
     */
    private function fetchRows(array $relation): array
    {
        config(['mini-api.endpoints.joined' => [
            'table' => self::MAIN_TABLE,
            'columns' => ['id', 'target_id'],
            'auth' => ['enabled' => false],
            'relations' => [$relation + ['table' => self::JOIN_TABLE, 'foreign_key' => 'target_id']],
        ]]);

        $response = $this->app->make(MiniApiController::class)->show(Request::create('/'), 'joined');
        $this->assertSame(200, $response->getStatusCode());

        return $response->getData(true);
    }
}
