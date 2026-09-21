<?php

declare(strict_types=1);

namespace Componist\MiniApi\Tests\Unit\Domain;

use Componist\MiniApi\Domain\MiniApiPageRequest;
use PHPUnit\Framework\TestCase;

class MiniApiPageRequestTest extends TestCase
{
    public function test_without_page_no_pagination_is_requested(): void
    {
        $this->assertNull(MiniApiPageRequest::fromQuery(null, '10', 500));
    }

    public function test_page_is_at_least_one_and_per_page_is_capped(): void
    {
        $request = MiniApiPageRequest::fromQuery('-3', '9999', 500);

        $this->assertSame(1, $request->page);
        $this->assertSame(500, $request->perPage);
    }

    public function test_per_page_defaults_to_maximum(): void
    {
        $this->assertSame(200, MiniApiPageRequest::fromQuery('2', null, 200)->perPage);
    }
}
