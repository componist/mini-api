<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Unit\Domain;

use Componist\MiniApi\Domain\MiniApiIdentifierRules;
use PHPUnit\Framework\TestCase;

class MiniApiJoinColumnParsingTest extends TestCase
{
    public function test_parses_qualified_column_with_alias(): void
    {
        $this->assertSame(
            ['table' => 'users', 'column' => 'email', 'alias' => 'mail'],
            MiniApiIdentifierRules::parseJoinColumn('users.email AS mail'),
        );
    }

    public function test_parses_plain_column_without_table(): void
    {
        $this->assertSame(['table' => null, 'column' => 'name', 'alias' => null], MiniApiIdentifierRules::parseJoinColumn('name'));
    }

    public function test_rejects_unsafe_expressions(): void
    {
        $this->assertNull(MiniApiIdentifierRules::parseJoinColumn('name; DROP TABLE users'));
        $this->assertNull(MiniApiIdentifierRules::parseJoinColumn('users.*'));
    }
}
