<?php

namespace App\Tests\Unit;

use App\Ai\Pricing\CostCalculator;
use App\Ai\Pricing\Money;
use App\Ai\Pricing\PriceEntry;
use App\Ai\Pricing\PricingCatalog;
use App\Ai\Provider\TokenUsage;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    private function price(): PriceEntry
    {
        return new PriceEntry('claude-sonnet-5-5', new \DateTimeImmutable('2026-10-06'), 'test',
            PriceEntry::parseRate('2.00'), PriceEntry::parseRate('10.00'), PriceEntry::parseRate('2.50'), PriceEntry::parseRate('4.00'), PriceEntry::parseRate('0.20'));
    }

    public function testParseRateIsExactWithoutFloats(): void
    {
        self::assertSame(2_000_000, PriceEntry::parseRate('2'));
        self::assertSame(2_500_000, PriceEntry::parseRate('2.50'));
        self::assertSame(200_000, PriceEntry::parseRate('0.20'));
        self::assertSame(300_001, PriceEntry::parseRate('0.300001'));
    }

    public function testParseRateRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PriceEntry::parseRate('2,5$');
    }

    public function testActualCostWithAllCategories(): void
    {
        // 1 000 entrée × 2 $/M + 500 sortie × 10 $/M + 2 000 lus × 0,20 $/M + 300 écrits 5 min × 2,50 $/M + 100 écrits 1 h × 4 $/M
        $usage = new TokenUsage(1000, 500, 400, 300, 100, 2000);
        $expected = 1000 * 2_000_000 + 500 * 10_000_000 + 2000 * 200_000 + 300 * 2_500_000 + 100 * 4_000_000;
        self::assertSame($expected, (new CostCalculator())->actualCost($usage, $this->price()));
        self::assertSame('0,0086 $', Money::format($expected));
    }

    public function testCacheWriteWithoutBreakdownUsesFiveMinuteRate(): void
    {
        $usage = new TokenUsage(0, 0, 1000, null, null, 0);
        self::assertSame(1000 * 2_500_000, (new CostCalculator())->actualCost($usage, $this->price()));
    }

    public function testIncompleteUsageGivesUnknownCostInsteadOfGuess(): void
    {
        self::assertNull((new CostCalculator())->actualCost(new TokenUsage(null, 120), $this->price()));
        self::assertNull((new CostCalculator())->actualCost(new TokenUsage(120, null), $this->price()));
    }

    public function testCeilingCountsWholeOutputLimitAndWorstInputRate(): void
    {
        $ceiling = (new CostCalculator())->ceiling(1000, 8000, $this->price());
        self::assertSame(1000 * 2_500_000 + 8000 * 10_000_000, $ceiling);
        self::assertSame('0,0825 $', Money::format($ceiling));
    }

    public function testMoneyFromDollars(): void
    {
        self::assertSame(1_500_000_000_000, Money::fromDollars('1.50'));
        self::assertSame(1_500_000_000_000, Money::fromDollars('1,5'));
        self::assertSame('inconnu', Money::format(null));
    }

    public function testCatalogPicksLatestRateEffectiveAtDate(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pricing');
        file_put_contents($file, <<<YAML
prices:
  - {model: m, effective_from: '2026-01-01', input: '1.00', output: '5.00', cache_write_5m: '1.25', cache_write_1h: '2.00', cache_read: '0.10'}
  - {model: m, effective_from: '2026-06-01', input: '2.00', output: '10.00', cache_write_5m: '2.50', cache_write_1h: '4.00', cache_read: '0.20'}
YAML);
        $catalog = new PricingCatalog($file);
        self::assertSame('m@2026-01-01', $catalog->find('m', new \DateTimeImmutable('2026-03-01'))?->reference());
        self::assertSame('m@2026-06-01', $catalog->find('m', new \DateTimeImmutable('2026-07-01'))?->reference());
        self::assertNull($catalog->find('m', new \DateTimeImmutable('2025-12-31')));
        self::assertNull($catalog->find('autre'));
        unlink($file);
    }

    public function testProjectPricingFileIsValidAndCoversDefaultModel(): void
    {
        $catalog = new PricingCatalog(__DIR__.'/../../config/ai/pricing.yaml');
        self::assertNotNull($catalog->find('claude-sonnet-5-5'));
    }
}
