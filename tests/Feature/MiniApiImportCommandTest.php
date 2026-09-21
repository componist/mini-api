<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Feature;

use App\Models\User;
use Componist\MiniApi\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;

class MiniApiImportCommandTest extends TestCase
{
    private const TABLE = 'mini_api_import_items';

    private const LIVE_URL = 'https://live.test';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(self::TABLE, function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('note')->nullable();
        });

        config([
            'mini-api.endpoints.items' => [
                'route' => 'items-liste',
                'table' => self::TABLE,
                'columns' => ['id', 'name', 'note'],
            ],
        ]);
    }

    public function test_api_paginates_with_stable_order_and_headers(): void
    {
        $ids = User::factory()->count(5)->create()->pluck('id')->sort()->values()->all();

        $response = $this->withHeader('X-Api-Key', 'test-mini-api-key')
            ->getJson('/api/users-liste?page=2&per_page=2')
            ->assertOk()
            ->assertHeader('X-Total-Count', '5')
            ->assertHeader('X-Last-Page', '3');

        $this->assertSame(array_slice($ids, 2, 2), array_column($response->json(), 'id'));
    }

    public function test_api_without_page_parameter_stays_unpaginated(): void
    {
        User::factory()->count(3)->create();

        $this->withHeader('X-Api-Key', 'test-mini-api-key')
            ->getJson('/api/users-liste')
            ->assertOk()
            ->assertHeaderMissing('X-Total-Count')
            ->assertJsonCount(3);
    }

    public function test_per_page_is_capped_by_endpoint_limit(): void
    {
        config(['mini-api.endpoints.users.limit' => 2]);
        User::factory()->count(3)->create();

        $this->withHeader('X-Api-Key', 'test-mini-api-key')
            ->getJson('/api/users-liste?page=1&per_page=999')
            ->assertOk()
            ->assertHeader('X-Last-Page', '2')
            ->assertJsonCount(2);
    }

    public function test_update_mode_upserts_across_pages_without_deleting(): void
    {
        DB::table(self::TABLE)->insert([
            ['id' => 1, 'name' => 'alt', 'note' => null],
            ['id' => 99, 'name' => 'lokal', 'note' => null],
        ]);
        $this->fakeLivePages([
            [['id' => 1, 'name' => 'neu', 'note' => 'x'], ['id' => 2, 'name' => 'zwei', 'note' => null]],
            [['id' => 3, 'name' => 'drei', 'note' => null]],
        ]);

        $this->runImport('update')->expectsOutputToContain('3 Zeilen verarbeitet')->assertSuccessful();

        $this->assertSame(4, DB::table(self::TABLE)->count());
        $this->assertSame('neu', DB::table(self::TABLE)->where('id', 1)->value('name'));
        $this->assertSame('lokal', DB::table(self::TABLE)->where('id', 99)->value('name'));
    }

    public function test_fresh_mode_replaces_table_content(): void
    {
        DB::table(self::TABLE)->insert(['id' => 99, 'name' => 'lokal']);
        $this->fakeLivePages([[['id' => 1, 'name' => 'live', 'note' => null]]]);

        $this->runImport('fresh')->assertSuccessful();

        $this->assertSame([1], DB::table(self::TABLE)->pluck('id')->all());
    }

    public function test_fresh_mode_asks_for_confirmation_and_aborts_on_no(): void
    {
        DB::table(self::TABLE)->insert(['id' => 99, 'name' => 'lokal']);
        Http::fake();

        $this->artisan('mini-api:import', $this->importArguments('fresh', force: false))
            ->expectsConfirmation('Fresh löscht den kompletten Inhalt von: '.self::TABLE.'. Fortfahren?', 'no')
            ->assertFailed();

        $this->assertSame(1, DB::table(self::TABLE)->count());
        Http::assertNothingSent();
    }

    public function test_failed_page_rolls_back_fresh_import(): void
    {
        DB::table(self::TABLE)->insert(['id' => 99, 'name' => 'lokal']);
        Http::fake([
            self::LIVE_URL.'/api/items-liste*' => Http::sequence()
                ->push([['id' => 1, 'name' => 'live']], 200, ['X-Last-Page' => '2'])
                ->push('Fehler', 500),
        ]);

        $this->runImport('fresh')->expectsOutputToContain('HTTP 500')->assertFailed();

        $this->assertSame([99], DB::table(self::TABLE)->pluck('id')->all());
    }

    public function test_locally_missing_columns_are_skipped_and_reported(): void
    {
        config(['mini-api.endpoints.items.columns' => ['id', 'name', 'ghost']]);
        $this->fakeLivePages([[['id' => 1, 'name' => 'live', 'ghost' => 'boo']]]);

        $this->runImport('update')->expectsOutputToContain('ghost')->assertSuccessful();

        $this->assertSame('live', DB::table(self::TABLE)->where('id', 1)->value('name'));
    }

    public function test_update_mode_requires_primary_key_in_columns(): void
    {
        config(['mini-api.endpoints.items.columns' => ['name']]);
        Http::fake();

        $this->runImport('update')->expectsOutputToContain('Primärschlüssel')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_blocked_tables_cannot_be_imported(): void
    {
        config(['mini-api.endpoints.items.table' => 'sessions']);
        Http::fake();

        $this->runImport('fresh')->expectsOutputToContain('nicht erlaubt')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_import_is_blocked_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Http::fake();

        $this->runImport('update')->expectsOutputToContain('gesperrt')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_missing_url_or_key_fails(): void
    {
        $this->artisan('mini-api:import', ['endpoint' => ['items'], '--mode' => 'update'])
            ->expectsOutputToContain('fehlen')
            ->assertFailed();
    }

    public function test_server_without_pagination_header_is_read_once(): void
    {
        Http::fake([self::LIVE_URL.'/api/items-liste*' => Http::response([['id' => 1, 'name' => 'live']])]);

        $this->runImport('update')->assertSuccessful();

        Http::assertSentCount(1);
    }

    /**
     * @param  list<list<array<string, mixed>>>  $pages
     */
    private function fakeLivePages(array $pages): void
    {
        $sequence = Http::sequence();
        foreach ($pages as $rows) {
            $sequence->push($rows, 200, ['X-Last-Page' => (string) count($pages)]);
        }

        Http::fake([self::LIVE_URL.'/api/items-liste*' => $sequence]);
    }

    private function runImport(string $mode): PendingCommand
    {
        return $this->artisan('mini-api:import', $this->importArguments($mode));
    }

    /**
     * @return array<string, mixed>
     */
    private function importArguments(string $mode, bool $force = true): array
    {
        return array_filter([
            'endpoint' => ['items'],
            '--url' => self::LIVE_URL,
            '--key' => 'live-key',
            '--mode' => $mode,
            '--force' => $force ?: null,
        ]);
    }
}
