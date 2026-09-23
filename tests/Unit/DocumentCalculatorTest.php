<?php

namespace Tests\Unit;

use App\Services\DocumentCalculator;
use PHPUnit\Framework\TestCase;

class DocumentCalculatorTest extends TestCase
{
    private function line(int $qtyMilli, int $priceCents, int $vat = 1000, array $extra = []): array
    {
        return ['type' => 'item', 'quantity' => $qtyMilli, 'unit_price' => $priceCents, 'vat_rate' => $vat] + $extra;
    }

    public function test_real_quote_2307_in_franchise(): void
    {
        // Devis Wix n° 0002307 : 1 × 584 € + 177 m² × 8 € = 2 000 €, sans TVA.
        $result = (new DocumentCalculator)->calculate([
            $this->line(1000, 58400),
            $this->line(177000, 800),
        ], franchise: true);

        $this->assertSame([58400, 141600], $result['lines']);
        $this->assertSame(200000, $result['total_ht']);
        $this->assertSame(0, $result['total_vat']);
        $this->assertSame(200000, $result['total_ttc']);
        $this->assertSame([], $result['vat']);
    }

    public function test_real_quote_2288_with_decimal_price(): void
    {
        // 295 m² × 9,90 € = 2 920,50 € ; ligne « OFFERT » à 0.
        $result = (new DocumentCalculator)->calculate([
            $this->line(295000, 990),
            $this->line(1000, 0, 1000, ['is_offered' => true]),
        ], franchise: true);

        $this->assertSame(292050, $result['total_ttc']);
    }

    public function test_vat_is_computed_per_rate(): void
    {
        $result = (new DocumentCalculator)->calculate([
            $this->line(1000, 1890000, 1000),   // 18 900 € à 10 %
            $this->line(2000, 5000, 2000),      // 100 € à 20 %
            $this->line(1000, 10000, 550),      // 100 € à 5,5 %
        ]);

        $this->assertSame(1910000, $result['total_ht']);
        $this->assertSame(['base' => 1890000, 'amount' => 189000], $result['vat'][1000]);
        $this->assertSame(['base' => 10000, 'amount' => 2000], $result['vat'][2000]);
        $this->assertSame(['base' => 10000, 'amount' => 550], $result['vat'][550]);
        $this->assertSame(191550, $result['total_vat']);
        $this->assertSame(2101550, $result['total_ttc']);
        $this->assertSame([2000, 1000, 550], array_keys($result['vat']), 'Taux affichés du plus élevé au plus bas.');
    }

    public function test_real_quote_2294_amount_discount(): void
    {
        // 3 950 € − 100 € de réduction = 3 850 €.
        $result = (new DocumentCalculator)->calculate([
            $this->line(1000, 15000), $this->line(1000, 95000), $this->line(1000, 50000),
            $this->line(1000, 165000), $this->line(1000, 45000), $this->line(1000, 25000),
        ], 'amount', 10000, true);

        $this->assertSame(395000, $result['subtotal']);
        $this->assertSame(10000, $result['discount']);
        $this->assertSame(385000, $result['total_ttc']);
    }

    public function test_percent_discount_is_spread_over_vat_rates(): void
    {
        $result = (new DocumentCalculator)->calculate([
            $this->line(1000, 100000, 1000),
            $this->line(1000, 50000, 2000),
        ], 'percent', 1000); // 10 %

        $this->assertSame(15000, $result['discount']);
        $this->assertSame(90000, $result['vat'][1000]['base']);
        $this->assertSame(45000, $result['vat'][2000]['base']);
        $this->assertSame(135000, $result['total_ht']);
        $this->assertSame(9000 + 9000, $result['total_vat']);
    }

    public function test_discount_rounding_never_loses_a_cent(): void
    {
        $result = (new DocumentCalculator)->calculate([
            $this->line(1000, 3333, 1000),
            $this->line(1000, 3333, 2000),
            $this->line(1000, 3334, 550),
        ], 'amount', 1000);

        $bases = array_sum(array_column($result['vat'], 'base'));
        $this->assertSame($result['total_ht'], $bases);
        $this->assertSame(9000, $result['total_ht']);
    }

    public function test_line_discount_quantity_decimals_and_rounding(): void
    {
        $calculator = new DocumentCalculator;

        $this->assertSame(1002, $calculator->lineAmount(['quantity' => 1333, 'unit_price' => 752]), '1,333 × 7,52 € = 10,02416 € → 10,02 €');
        $this->assertSame(1003, $calculator->lineAmount(['quantity' => 1335, 'unit_price' => 751]), '1,335 × 7,51 € = 10,02585 € → 10,03 €');
        $this->assertSame(90000, $calculator->lineAmount(['quantity' => 1000, 'unit_price' => 100000, 'discount_percent' => 1000]), 'Remise de ligne 10 %');
        $this->assertSame(85500, $calculator->lineAmount(['quantity' => 85500, 'unit_price' => 1000]), '85,5 m² × 10 €');
    }

    public function test_optional_lines_are_excluded_from_total(): void
    {
        $result = (new DocumentCalculator)->calculate([
            $this->line(1000, 157000),
            $this->line(1000, 500000, 1000, ['is_optional' => true]),
        ], franchise: true);

        $this->assertSame(157000, $result['total_ttc']);
        $this->assertSame(500000, $result['optional_total']);
    }

    public function test_sections_get_subtotals(): void
    {
        $result = (new DocumentCalculator)->calculate([
            ['type' => 'section'],
            $this->line(1000, 10000),
            $this->line(2000, 5000),
            ['type' => 'text'],
            ['type' => 'section'],
            $this->line(1000, 30000),
        ], franchise: true);

        $this->assertSame([0 => 20000, 4 => 30000], $result['sections']);
        $this->assertSame(50000, $result['total_ttc']);
    }

    public function test_discount_cannot_exceed_subtotal(): void
    {
        $result = (new DocumentCalculator)->calculate([$this->line(1000, 5000)], 'amount', 999999, true);

        $this->assertSame(0, $result['total_ttc']);
    }
}
