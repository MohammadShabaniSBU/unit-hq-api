<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DelinquencyPolicy;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\UnitClass;
use App\Support\Country\CountryProfiles;
use App\Support\Country\GbProfile;
use Database\Seeders\CountryTaxSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\TestCase;

class CountryEnforcementTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use RefreshDatabase;

    private Country $spain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spain = Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        Country::factory()->create(['code' => 'GB', 'name' => 'United Kingdom']);
        CountryProfiles::syncIdentity();
        $this->authenticateAsEmployee();
    }

    public function test_foreign_tax_jurisdiction_rejected_null_accepted(): void
    {
        $this->postJson('/api/tax-rates', [
            'name' => 'TVA',
            'code' => 'vat',
            'rate' => 20,
            'jurisdiction' => GbProfile::CODE,
        ])->assertStatus(422)->assertJsonValidationErrors(['jurisdiction']);

        $this->postJson('/api/tax-rates', [
            'name' => 'Universal VAT',
            'code' => 'vat-any',
            'rate' => 21,
            'jurisdiction' => null,
        ])->assertCreated();
    }

    public function test_country_tax_seeder_is_idempotent(): void
    {
        $seeder = new CountryTaxSeeder;
        $seeder->run();
        $seeder->run();

        $this->assertSame(1, TaxRate::query()->where('code', 'vat')->where('jurisdiction', 'ES')->count());
    }

    public function test_price_currency_outside_country_requires_override(): void
    {
        $site = Site::factory()->create([
            'country_id' => $this->spain->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();

        $this->postJson("/api/unit-classes/{$unitClass->id}/prices", [
            'site_id' => $site->id,
            'amount' => '100.00',
            'currency' => 'GBP',
        ])->assertStatus(422)->assertJsonValidationErrors(['currency']);

        $this->postJson("/api/unit-classes/{$unitClass->id}/prices", [
            'site_id' => $site->id,
            'amount' => '100.00',
            'currency' => 'GBP',
            'allow_currency_mismatch' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('activity_log', ['description' => 'price.currency_mismatch_allowed']);
    }

    public function test_default_currency_outside_country_rejected(): void
    {
        $this->patchJson('/api/settings/billing', [
            'default_currency' => 'GBP',
        ])->assertStatus(422)->assertJsonValidationErrors(['default_currency']);
    }

    public function test_legal_entity_country_and_tax_id(): void
    {
        $this->postJson('/api/legal-entities', [
            'legal_name' => 'Foreign Ltd',
            'tax_id' => '00000000T',
            'tax_id_type' => 'nif',
            'country_code' => GbProfile::CODE,
            'address_line1' => '1 Street',
            'city' => 'London',
            'postal_code' => 'E1 1AA',
        ])->assertStatus(422)->assertJsonValidationErrors(['country_code']);

        $this->postJson('/api/legal-entities', [
            'legal_name' => 'Bad Tax SL',
            'tax_id' => 'NOT-A-NIF',
            'tax_id_type' => 'nif',
            'address_line1' => '1 Street',
            'city' => 'Madrid',
            'postal_code' => '28001',
        ])->assertStatus(422)->assertJsonValidationErrors(['tax_id']);
    }

    public function test_sepa_creditor_rejected_when_rail_unavailable(): void
    {
        config(['deployment.country' => GbProfile::CODE]);
        CountryProfiles::resetResolved();
        CountryProfiles::syncIdentity();

        $this->postJson('/api/legal-entities', [
            'legal_name' => 'GB Ltd',
            'tax_id' => 'GB123456789',
            'tax_id_type' => 'vat',
            'sepa_creditor_id' => 'GB12ZZZ123456',
            'address_line1' => '1 Street',
            'city' => 'London',
            'postal_code' => 'E1 1AA',
        ])->assertStatus(422);
    }

    public function test_foreign_delinquency_policy_assignment_rejected(): void
    {
        $gbPolicy = DelinquencyPolicy::query()->create([
            'name' => 'GB standard',
            'jurisdiction' => GbProfile::CODE,
            'auto_release_overlock' => true,
            'auto_restore_access' => true,
        ]);

        $this->postJson('/api/sites', [
            'name' => 'Madrid',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => LegalEntity::factory()->create()->id,
            'delinquency_policy_id' => $gbPolicy->id,
        ])->assertStatus(422);
    }

    public function test_audit_reports_seeded_foreign_site(): void
    {
        $gb = Country::query()->where('code', GbProfile::CODE)->firstOrFail();
        Site::factory()->create([
            'country_id' => $gb->id,
            'timezone' => 'Europe/London',
        ]);

        $this->artisan('deployment:audit-country')
            ->assertFailed()
            ->expectsOutputToContain('site');
    }
}
