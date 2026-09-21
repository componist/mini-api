<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class SessionStub extends Model
{
    protected $table = 'sessions';
}
