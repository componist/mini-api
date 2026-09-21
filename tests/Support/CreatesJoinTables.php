<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesJoinTables
{
    private const MAIN_TABLE = 'mini_api_main';

    private const JOIN_TABLE = 'mini_api_targets';

    /**
     * Zeile 1 verweist auf ein Ziel, Zeile 2 nicht (für left_join-Fälle).
     */
    private function createJoinTables(): void
    {
        Schema::create(self::JOIN_TABLE, function ($table): void {
            $table->id();
            $table->string('label');
            $table->string('secret_token')->nullable();
            $table->string('password')->nullable();
        });
        Schema::create(self::MAIN_TABLE, function ($table): void {
            $table->id();
            $table->unsignedBigInteger('target_id')->nullable();
        });

        DB::table(self::JOIN_TABLE)->insert(['id' => 1, 'label' => 'Ziel', 'secret_token' => 'geheim', 'password' => 'pw']);
        DB::table(self::MAIN_TABLE)->insert([['id' => 1, 'target_id' => 1], ['id' => 2, 'target_id' => null]]);
    }
}
