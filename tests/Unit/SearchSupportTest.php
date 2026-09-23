<?php

namespace Tests\Unit;

use App\Support\Phone;
use App\Support\Search;
use PHPUnit\Framework\TestCase;

class SearchSupportTest extends TestCase
{
    public function test_terms_are_normalised(): void
    {
        $this->assertSame(['helene', 'dupont'], Search::terms('  Hélène   DUPONT '));
        $this->assertSame(['0612345678'], Search::terms('+33 6 12 34 56 78'));
        $this->assertSame([], Search::terms('   '));
        $this->assertSame([], Search::terms('% _'));
    }

    public function test_phone_formatting(): void
    {
        $this->assertSame('06 12 34 56 78', Phone::format('0612345678'));
        $this->assertSame('06 12 34 56 78', Phone::format('+33 6 12 34 56 78'));
        $this->assertSame('+32 470 12 34 56', Phone::format('+32 470 12 34 56'), 'Numéro étranger laissé tel quel.');
        $this->assertNull(Phone::format(''));
        $this->assertSame('tel:+33612345678', Phone::href('06 12 34 56 78'));
    }
}
