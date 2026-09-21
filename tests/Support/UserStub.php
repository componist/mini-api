<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bewusst ohne $hidden, damit nur die Mini-API-Blocklisten Felder wie `password` filtern können.
 */
class UserStub extends Model
{
    protected $table = 'users';

    public function accounts(): HasMany
    {
        return $this->hasMany(self::class, 'id', 'id');
    }

    public function loginSessions(): HasMany
    {
        return $this->hasMany(SessionStub::class, 'user_id');
    }

    public function notARelation(): string
    {
        return 'nope';
    }
}
