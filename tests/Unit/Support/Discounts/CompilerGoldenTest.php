<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Discounts;

use App\Enums\DiscountKind;
use App\Models\Discount;
use App\Support\Discounts\CompileContext;
use App\Support\Discounts\DiscountCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompilerGoldenTest extends TestCase
{
    public function test_both_kinds_all_tiers_remainder(): void
    {
        $list = '184.90';
        $anchor = '2026-08-03';

        // Percent 20% → forever at 80%.
        $percent = $this->discount(DiscountKind::Percent, ['percent' => '20.00']);
        $pctPlan = DiscountCompiler::compile($percent, $this->ctx($list, 'week', 4, $anchor, 8));
        $this->assertFalse($pctPlan->noop);
        $this->assertSame([
            ['from' => $anchor, 'to' => null, 'amount' => '147.92'],
        ], $pctPlan->segments);

        $freeTime = $this->discount(DiscountKind::FreeTime, [
            'tiers' => [
                ['min_commitment_weeks' => 4, 'free_weeks' => 2],
                ['min_commitment_weeks' => 8, 'free_weeks' => 4],
                ['min_commitment_weeks' => 12, 'free_weeks' => 6],
            ],
        ]);

        // 4w → 2 free on 4-week cadence: half-rate period, then list.
        $t4 = DiscountCompiler::compile($freeTime, $this->ctx($list, 'week', 4, $anchor, 4));
        $this->assertFalse($t4->noop);
        $this->assertSame([
            ['from' => '2026-08-03', 'to' => '2026-08-31', 'amount' => '92.45'],
            ['from' => '2026-08-31', 'to' => null, 'amount' => '184.90'],
        ], $t4->segments);

        // 8w → 4 free: one €0 period, then list.
        $t8 = DiscountCompiler::compile($freeTime, $this->ctx($list, 'week', 4, $anchor, 8));
        $this->assertFalse($t8->noop);
        $this->assertSame([
            ['from' => '2026-08-03', 'to' => '2026-08-31', 'amount' => '0.00'],
            ['from' => '2026-08-31', 'to' => null, 'amount' => '184.90'],
        ], $t8->segments);

        // 12w → 6 free: €0, half-rate, then list.
        $t12 = DiscountCompiler::compile($freeTime, $this->ctx($list, 'week', 4, $anchor, 12));
        $this->assertFalse($t12->noop);
        $this->assertSame([
            ['from' => '2026-08-03', 'to' => '2026-08-31', 'amount' => '0.00'],
            ['from' => '2026-08-31', 'to' => '2026-09-28', 'amount' => '92.45'],
            ['from' => '2026-09-28', 'to' => null, 'amount' => '184.90'],
        ], $t12->segments);

        // No commitment → noop at list.
        $noop = DiscountCompiler::compile($freeTime, $this->ctx($list, 'week', 4, $anchor, null));
        $this->assertTrue($noop->noop);
        $this->assertSame([
            ['from' => $anchor, 'to' => null, 'amount' => '184.90'],
        ], $noop->segments);

        // Misaligned: 3 free weeks on monthly (30d) → partial at list × 9/30.
        $misaligned = $this->discount(DiscountKind::FreeTime, [
            'tiers' => [
                ['min_commitment_weeks' => 4, 'free_weeks' => 3],
            ],
        ]);
        $odd = DiscountCompiler::compile($misaligned, $this->ctx($list, 'month', 1, $anchor, 4));
        $this->assertFalse($odd->noop);
        $this->assertSame('55.47', $odd->segments[0]['amount']); // round2(184.90 * 9 / 30)
        $this->assertSame('184.90', $odd->segments[1]['amount']);
        $this->assertNull($odd->segments[1]['to']);
    }

    #[DataProvider('commitmentProvider')]
    public function test_commitment_weeks_from_length(int $length, string $period, int $expected): void
    {
        $this->assertSame(
            $expected,
            \App\Support\Discounts\CommitmentWeeks::fromLengthAndPeriod($length, $period)
        );
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function commitmentProvider(): array
    {
        return [
            '2 months → 8 weeks' => [2, 'month', 8],
            '12 weeks' => [12, 'week', 12],
            '14 days → 2 weeks' => [14, 'day', 2],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function discount(DiscountKind $kind, array $params): Discount
    {
        $d = new Discount;
        $d->forceFill([
            'name' => 'test',
            'kind' => $kind,
            'params' => $params,
            'applies_to' => 'unit',
            'tracks_rate_changes' => $kind === DiscountKind::Percent,
        ]);

        return $d;
    }

    private function ctx(
        string $list,
        string $interval,
        int $count,
        string $anchor,
        ?int $commitment,
    ): CompileContext {
        return new CompileContext(
            listAmount: $list,
            currency: 'EUR',
            interval: $interval,
            intervalCount: $count,
            anchorDate: $anchor,
            commitmentWeeks: $commitment,
        );
    }
}
