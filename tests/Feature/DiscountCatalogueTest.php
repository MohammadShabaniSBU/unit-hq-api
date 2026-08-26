<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DiscountKind;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Setting;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscountCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
    }

    public function test_validation_matrix(): void
    {
        $this->postJson('/api/discounts', [
            'name' => '20% off',
            'kind' => DiscountKind::Percent->value,
            'params' => ['percent' => '20.00'],
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'percent')
            ->assertJsonPath('data.params.percent', '20.00')
            ->assertJsonPath('data.tracks_rate_changes', true);

        $this->postJson('/api/discounts', [
            'name' => 'Long-stay promo',
            'kind' => DiscountKind::FreeTime->value,
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 4, 'free_weeks' => 2],
                    ['min_commitment_weeks' => 8, 'free_weeks' => 4],
                    ['min_commitment_weeks' => 12, 'free_weeks' => 6],
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'free_time')
            ->assertJsonPath('data.tracks_rate_changes', false)
            ->assertJsonPath('data.params.tiers.0.free_weeks', 2);

        // percent edges
        $this->postJson('/api/discounts', [
            'name' => 'Zero',
            'kind' => 'percent',
            'params' => ['percent' => '0.00'],
        ])->assertStatus(422)->assertJsonValidationErrors(['params.percent']);

        $this->postJson('/api/discounts', [
            'name' => 'Hundred',
            'kind' => 'percent',
            'params' => ['percent' => '100.00'],
        ])->assertStatus(422)->assertJsonValidationErrors(['params.percent']);

        $this->postJson('/api/discounts', [
            'name' => 'Missing percent',
            'kind' => 'percent',
            'params' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['params.percent']);

        // free_time: free >= commitment
        $this->postJson('/api/discounts', [
            'name' => 'Free everything',
            'kind' => 'free_time',
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 4, 'free_weeks' => 4],
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['params.tiers.0.free_weeks']);

        // free_time: non-monotonic commitment
        $this->postJson('/api/discounts', [
            'name' => 'Bad commitment order',
            'kind' => 'free_time',
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 8, 'free_weeks' => 2],
                    ['min_commitment_weeks' => 4, 'free_weeks' => 3],
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['params.tiers.1.min_commitment_weeks']);

        // free_time: non-monotonic free weeks
        $this->postJson('/api/discounts', [
            'name' => 'Bad free order',
            'kind' => 'free_time',
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 4, 'free_weeks' => 3],
                    ['min_commitment_weeks' => 8, 'free_weeks' => 2],
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['params.tiers.1.free_weeks']);

        // free_time: empty tiers
        $this->postJson('/api/discounts', [
            'name' => 'Empty tiers',
            'kind' => 'free_time',
            'params' => ['tiers' => []],
        ])->assertStatus(422)->assertJsonValidationErrors(['params.tiers']);
    }

    public function test_alignment_warning(): void
    {
        // Default org cadence is monthly (30-day nominal) — week free time misaligns.
        Setting::setBilling(Setting::billing()->with(
            defaultBillingInterval: 'month',
            defaultBillingIntervalCount: 1,
        ));

        $response = $this->postJson('/api/discounts', [
            'name' => 'Misaligned promo',
            'kind' => DiscountKind::FreeTime->value,
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 4, 'free_weeks' => 2],
                ],
            ],
        ])->assertCreated();

        $warnings = $response->json('data.alignment_warnings');
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('46.6% period', $warnings[0]);
        $this->assertStringContainsString('monthly', $warnings[0]);

        // Aligned on 4-week cadence: 2 free weeks = exactly half a period — wait,
        // 14 % 28 === 14, still misaligned. Use 4 free weeks on week×4.
        Setting::setBilling(Setting::billing()->with(
            defaultBillingInterval: 'week',
            defaultBillingIntervalCount: 4,
        ));

        $aligned = $this->postJson('/api/discounts', [
            'name' => 'Aligned promo',
            'kind' => DiscountKind::FreeTime->value,
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 8, 'free_weeks' => 4],
                ],
            ],
        ])->assertCreated();

        $this->assertSame([], $aligned->json('data.alignment_warnings'));
    }

    public function test_archive_semantics(): void
    {
        $active = Discount::factory()->percent('10.00')->create([
            'created_by' => $this->employee->id,
        ]);
        $archived = Discount::factory()->percent('20.00')->archived()->create([
            'created_by' => $this->employee->id,
        ]);

        $this->getJson('/api/discounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson('/api/discounts?status=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archived->id);

        $this->getJson('/api/discounts/options')
            ->assertOk()
            ->assertJsonFragment(['value' => $active->id, 'label' => $active->name])
            ->assertJsonMissing(['value' => $archived->id]);

        $this->postJson("/api/discounts/{$active->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);

        $this->getJson('/api/discounts/options')
            ->assertOk()
            ->assertJsonMissing(['value' => $active->id]);

        // Provenance resolvability — show still works when archived.
        $this->getJson("/api/discounts/{$active->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $active->id)
            ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);

        $this->postJson("/api/discounts/{$active->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);

        // In-use guard: referenced by an offer option.
        $inUse = Discount::factory()->percent('15.00')->create();
        $rate = UnitClassRate::query()->create([
            'unit_class_id' => UnitClass::factory()->create()->id,
            'site_id' => Site::factory()->create()->id,
        ]);
        OfferOption::query()->create([
            'offer_id' => Offer::factory()->create()->id,
            'unit_class_rate_id' => $rate->id,
            'unit_id' => null,
            'discount_id' => $inUse->id,
            'label' => 'In-use option',
            'description' => null,
            'display_order' => 0,
            'selected_at' => null,
        ]);

        $this->postJson("/api/discounts/{$inUse->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount']);

        // No hard delete route.
        $this->deleteJson("/api/discounts/{$active->id}")
            ->assertStatus(405);
    }

    public function test_agent_offerable_requires_english_terms(): void
    {
        $this->postJson('/api/discounts', [
            'name' => 'Walk-in 10',
            'kind' => DiscountKind::Percent->value,
            'params' => ['percent' => '10.00'],
            'agent_offerable' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['customer_terms.en']);

        $created = $this->postJson('/api/discounts', [
            'name' => 'Long-stay agent promo',
            'kind' => DiscountKind::FreeTime->value,
            'params' => [
                'tiers' => [
                    ['min_commitment_weeks' => 4, 'free_weeks' => 2],
                ],
            ],
            'agent_offerable' => true,
            'customer_terms' => [
                'en' => 'Commit to 4 weeks or more and your first 2 weeks are free.',
                'es' => 'Comprométete a 4 semanas o más y las 2 primeras semanas son gratis.',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.agent_offerable', true)
            ->assertJsonPath('data.customer_terms.en', 'Commit to 4 weeks or more and your first 2 weeks are free.')
            ->assertJsonPath('data.customer_terms.es', 'Comprométete a 4 semanas o más y las 2 primeras semanas son gratis.');

        $id = $created->json('data.id');

        $this->patchJson("/api/discounts/{$id}", [
            'agent_offerable' => false,
            'customer_terms' => null,
        ])->assertOk()
            ->assertJsonPath('data.agent_offerable', false)
            ->assertJsonPath('data.customer_terms', null);
    }
}
