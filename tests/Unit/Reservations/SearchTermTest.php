<?php

namespace Tests\Unit\Reservations;

use App\Domain\Reservations\Queries\SearchTerm;
use PHPUnit\Framework\TestCase;

class SearchTermTest extends TestCase
{
    public function test_classifies_the_input(): void
    {
        $this->assertSame(['ref', 'R-2026001'], [SearchTerm::parse('r2026001')->kind, SearchTerm::parse('r2026001')->value]);
        $this->assertSame('ref', SearchTerm::parse('R-2026')->kind);
        $this->assertSame(['email', 'john@x.com'], [SearchTerm::parse('John@X.com')->kind, SearchTerm::parse('John@X.com')->value]);
        $this->assertSame(['phone', '7700900123'], [SearchTerm::parse('7700 900 123')->kind, SearchTerm::parse('7700 900 123')->value]);
        $this->assertSame('101', SearchTerm::parse('101')->room, 'short numbers are also room numbers');
        $this->assertSame('phone', SearchTerm::parse('1012')->kind);
        $this->assertSame(['name', 'A101'], [SearchTerm::parse('A101')->kind, SearchTerm::parse('A101')->room]);
        $this->assertSame('+John +Smith', SearchTerm::parse('John Smith')->fulltext());
        $this->assertSame('+Smith', SearchTerm::parse('Smith* -x')->fulltext());
        $this->assertNull(SearchTerm::parse('a'));
        $this->assertSame('50\%\_%', SearchTerm::prefix('50%_'));
    }
}
