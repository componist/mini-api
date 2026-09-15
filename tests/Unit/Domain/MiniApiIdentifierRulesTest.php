<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Unit\Domain;

use Componist\MiniApi\Domain\MiniApiIdentifierRules;
use PHPUnit\Framework\TestCase;

class MiniApiIdentifierRulesTest extends TestCase
{
    public function test_valid_identifier_pattern(): void
    {
        $this->assertTrue(MiniApiIdentifierRules::isValidIdentifier('users'));
        $this->assertFalse(MiniApiIdentifierRules::isValidIdentifier('users; drop'));
    }

    public function test_safe_join_column_expression(): void
    {
        $this->assertTrue(MiniApiIdentifierRules::isSafeJoinColumnExpression('users.name as user_name'));
        $this->assertFalse(MiniApiIdentifierRules::isSafeJoinColumnExpression('users; drop'));
    }

    public function test_sanitize_identifier_strips_invalid_chars(): void
    {
        $this->assertSame('usersdrop', MiniApiIdentifierRules::sanitizeIdentifier('users; drop'));
    }
}
