<?php

namespace Tests\Feature;

use App\Services\NumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_follow_each_other_without_gaps(): void
    {
        Carbon::setTestNow('2026-09-23');
        $generator = app(NumberGenerator::class);

        $this->assertSame('DEV-2026-0001', $generator->preview('quote'));
        $this->assertSame('DEV-2026-0001', $generator->next('quote'));
        $this->assertSame('DEV-2026-0002', $generator->next('quote'));
        $this->assertSame('FAC-2026-0001', $generator->next('invoice'), 'Chaque type a son propre compteur.');
        $this->assertSame('AV-2026-0001', $generator->next('credit_note'));
    }

    public function test_counter_continues_across_years(): void
    {
        $generator = app(NumberGenerator::class);

        Carbon::setTestNow('2026-12-31 18:00');
        $this->assertSame('FAC-2026-0001', $generator->next('invoice'));

        Carbon::setTestNow('2027-01-02 08:00');
        $this->assertSame('FAC-2027-0002', $generator->next('invoice'));
    }

    public function test_preview_does_not_consume_a_number(): void
    {
        $generator = app(NumberGenerator::class);

        $generator->preview('quote');
        $generator->preview('quote');

        $this->assertStringEndsWith('-0001', $generator->next('quote'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
