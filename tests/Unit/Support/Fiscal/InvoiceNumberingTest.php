<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Fiscal;

use App\Enums\InvoiceSeriesKind;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Support\Fiscal\InvoiceNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_allocation(): void
    {
        $series = $this->makeSeries();

        $numbers = DB::transaction(function () use ($series) {
            return [
                InvoiceNumbering::allocate($series),
                InvoiceNumbering::allocate($series),
                InvoiceNumbering::allocate($series),
            ];
        });

        $this->assertSame([1, 2, 3], $numbers);
        $this->assertSame(4, $series->fresh()->next_number);
    }

    public function test_rollback_leaves_no_gap(): void
    {
        $series = $this->makeSeries();

        $first = DB::transaction(fn () => InvoiceNumbering::allocate($series));
        $this->assertSame(1, $first);
        $this->assertSame(2, $series->fresh()->next_number);

        try {
            DB::transaction(function () use ($series) {
                InvoiceNumbering::allocate($series);
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(2, $series->fresh()->next_number);

        $third = DB::transaction(fn () => InvoiceNumbering::allocate($series));
        $this->assertSame(2, $third);
        $this->assertSame(3, $series->fresh()->next_number);
    }

    public function test_assert_kind_rejects_mismatch(): void
    {
        $series = $this->makeSeries(InvoiceSeriesKind::Ordinary);

        $this->expectException(ValidationException::class);

        InvoiceNumbering::assertKind($series, InvoiceSeriesKind::Rectificative->value);
    }

    public function test_assert_kind_accepts_match(): void
    {
        $series = $this->makeSeries(InvoiceSeriesKind::Simplified);

        InvoiceNumbering::assertKind($series, InvoiceSeriesKind::Simplified->value);

        $this->assertTrue(true);
    }

    private function makeSeries(InvoiceSeriesKind $kind = InvoiceSeriesKind::Ordinary): InvoiceSeries
    {
        $entity = LegalEntity::factory()->create();

        return InvoiceSeries::factory()->create([
            'legal_entity_id' => $entity->id,
            'kind' => $kind,
            'code' => 'T'.fake()->unique()->numerify('####'),
            'next_number' => 1,
            'is_default' => false,
        ]);
    }
}
