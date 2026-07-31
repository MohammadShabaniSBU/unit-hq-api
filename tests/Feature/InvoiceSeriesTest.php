<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InvoiceSeriesKind;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Support\Fiscal\InvoiceNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_number_create_only(): void
    {
        $entity = LegalEntity::factory()->create();

        $created = $this->postJson("/api/legal-entities/{$entity->id}/invoice-series", [
            'code' => 'F2026X',
            'kind' => InvoiceSeriesKind::Ordinary->value,
            'starting_number' => 42,
            'is_default' => false,
        ])->assertCreated()
            ->json('data');

        $this->assertSame(42, $created['next_number']);

        $this->patchJson("/api/invoice-series/{$created['id']}", [
            'starting_number' => 99,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['next_number']);

        $this->patchJson("/api/invoice-series/{$created['id']}", [
            'next_number' => 99,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['next_number']);

        $this->assertSame(42, InvoiceSeries::query()->findOrFail($created['id'])->next_number);
    }

    public function test_single_default_per_kind(): void
    {
        $entity = LegalEntity::factory()->create();

        $first = $this->postJson("/api/legal-entities/{$entity->id}/invoice-series", [
            'code' => 'F-A',
            'kind' => InvoiceSeriesKind::Ordinary->value,
            'is_default' => true,
        ])->assertCreated()->json('data');

        $second = $this->postJson("/api/legal-entities/{$entity->id}/invoice-series", [
            'code' => 'F-B',
            'kind' => InvoiceSeriesKind::Ordinary->value,
            'is_default' => true,
        ])->assertCreated()->json('data');

        $this->assertFalse(InvoiceSeries::query()->findOrFail($first['id'])->is_default);
        $this->assertTrue(InvoiceSeries::query()->findOrFail($second['id'])->is_default);

        $this->patchJson("/api/invoice-series/{$first['id']}", [
            'is_default' => true,
        ])->assertOk();

        $this->assertTrue(InvoiceSeries::query()->findOrFail($first['id'])->fresh()->is_default);
        $this->assertFalse(InvoiceSeries::query()->findOrFail($second['id'])->fresh()->is_default);
    }

    public function test_cannot_archive_sole_default(): void
    {
        $entity = LegalEntity::factory()->create();
        InvoiceSeries::ensureDefaultsFor($entity);

        $ordinary = InvoiceSeries::query()
            ->where('legal_entity_id', $entity->id)
            ->where('kind', InvoiceSeriesKind::Ordinary)
            ->where('is_default', true)
            ->firstOrFail();

        $this->postJson("/api/invoice-series/{$ordinary->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_series']);

        $this->postJson("/api/legal-entities/{$entity->id}/invoice-series", [
            'code' => 'F-ALT',
            'kind' => InvoiceSeriesKind::Ordinary->value,
            'is_default' => false,
        ])->assertCreated();

        $this->postJson("/api/invoice-series/{$ordinary->id}/archive")
            ->assertOk();
    }

    public function test_ensure_defaults_creates_three_per_entity(): void
    {
        $entity = LegalEntity::factory()->create();
        InvoiceSeries::ensureDefaultsFor($entity);
        InvoiceSeries::ensureDefaultsFor($entity); // idempotent

        $series = InvoiceSeries::query()
            ->where('legal_entity_id', $entity->id)
            ->whereNull('archived_at')
            ->get();

        $this->assertCount(3, $series);
        $this->assertTrue($series->every(fn (InvoiceSeries $s) => $s->is_default));
        $this->assertEqualsCanonicalizing(
            [
                InvoiceSeriesKind::Ordinary->value,
                InvoiceSeriesKind::Simplified->value,
                InvoiceSeriesKind::Rectificative->value,
            ],
            $series->pluck('kind')->map(fn ($k) => $k->value)->all()
        );
    }

    public function test_legal_entity_create_seeds_defaults(): void
    {
        $response = $this->postJson('/api/legal-entities', [
            'legal_name' => 'Series Seed SL',
            'tax_id' => 'B11223344',
            'tax_id_type' => 'nif',
            'country_code' => 'ES',
            'address_line1' => 'Calle Test 1',
            'city' => 'Madrid',
            'postal_code' => '28001',
            'fiscal_regime' => 'none',
        ])->assertCreated();

        $entityId = $response->json('data.id');

        $this->assertSame(
            3,
            InvoiceSeries::query()->where('legal_entity_id', $entityId)->count()
        );
    }

    public function test_code_frozen_helper_ready_for_invoices(): void
    {
        $entity = LegalEntity::factory()->create();
        $series = InvoiceSeries::factory()->create([
            'legal_entity_id' => $entity->id,
            'is_default' => false,
        ]);

        $this->assertFalse(Schema::hasTable('invoices'));
        $this->assertFalse($series->hasIssuedInvoices());

        $this->patchJson("/api/invoice-series/{$series->id}", [
            'code' => 'CHANGED',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_assert_kind_rejects_mismatch(): void
    {
        $series = InvoiceSeries::factory()->create([
            'kind' => InvoiceSeriesKind::Ordinary,
            'is_default' => false,
        ]);

        $this->expectException(ValidationException::class);
        InvoiceNumbering::assertKind($series, InvoiceSeriesKind::Rectificative->value);
    }

    public function test_index_lists_series_for_entity(): void
    {
        $entity = LegalEntity::factory()->create();
        InvoiceSeries::ensureDefaultsFor($entity);

        $other = LegalEntity::factory()->create();
        InvoiceSeries::ensureDefaultsFor($other);

        $data = $this->getJson("/api/legal-entities/{$entity->id}/invoice-series")
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $data);
        $this->assertTrue(collect($data)->every(
            fn (array $row) => $row['legal_entity_id'] === $entity->id
        ));
    }
}
