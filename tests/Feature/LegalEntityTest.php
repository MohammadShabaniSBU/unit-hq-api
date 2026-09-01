<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FiscalRegime;
use App\Enums\TaxIdType;
use App\Models\Country;
use App\Models\LegalEntity;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class LegalEntityTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    public function test_archive_refused_with_active_sites(): void
    {
        $entity = LegalEntity::factory()->create();
        Site::factory()->create(['legal_entity_id' => $entity->id]);

        $this->postJson("/api/legal-entities/{$entity->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legal_entity']);
    }

    public function test_fiscal_regime_only_none_this_sprint(): void
    {
        $payload = $this->validEntityPayload();

        $this->postJson('/api/legal-entities', [
            ...$payload,
            'fiscal_regime' => FiscalRegime::None->value,
        ])->assertCreated();

        $rejected = [
            FiscalRegime::Verifactu->value,
            FiscalRegime::NoVerificable->value,
            FiscalRegime::Ticketbai->value,
            FiscalRegime::Sii->value,
        ];

        $messages = [];

        foreach ($rejected as $regime) {
            $response = $this->postJson('/api/legal-entities', [
                ...$payload,
                'tax_id' => 'ID-'.$regime,
                'fiscal_regime' => $regime,
            ]);

            $response->assertStatus(422)->assertJsonValidationErrors(['fiscal_regime']);
            $messages[$regime] = $response->json('errors.fiscal_regime.0');
        }

        $this->assertCount(4, array_unique($messages), 'Each rejected fiscal_regime must produce a distinct message.');
    }

    public function test_tax_id_unique_among_active(): void
    {
        $first = LegalEntity::factory()->create(['tax_id' => 'B12345678']);

        $this->postJson('/api/legal-entities', [
            ...$this->validEntityPayload(),
            'tax_id' => 'B12345678',
        ])->assertStatus(422)->assertJsonValidationErrors(['tax_id']);

        // Archive first (no active sites) so the same tax_id can be reused.
        $this->postJson("/api/legal-entities/{$first->id}/archive")->assertOk();

        $this->postJson('/api/legal-entities', [
            ...$this->validEntityPayload(),
            'tax_id' => 'B12345678',
        ])->assertCreated();
    }

    public function test_sites_form_requires_entity(): void
    {
        $country = Country::factory()->create(['code' => 'ES']);

        $this->postJson('/api/sites', [
            'name' => 'No Entity Storage',
            'timezone' => 'Europe/Madrid',
            'country_id' => $country->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['legal_entity_id']);

        $entity = LegalEntity::factory()->create();

        $this->postJson('/api/sites', [
            'name' => 'With Entity Storage',
            'timezone' => 'Europe/Madrid',
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
        ])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function validEntityPayload(): array
    {
        return [
            'legal_name' => 'Test Storage SL',
            'tax_id' => 'B99887766',
            'tax_id_type' => TaxIdType::Nif->value,
            'country_code' => 'ES',
            'address_line1' => 'Calle Test 1',
            'city' => 'Madrid',
            'postal_code' => '28001',
            'fiscal_regime' => FiscalRegime::None->value,
        ];
    }
}
