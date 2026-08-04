<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LogChannel;
use App\Enums\TaxIdType;
use App\Models\Activity;
use App\Models\Contact;
use App\Support\RecordsActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

/**
 * Contact fiscal identity (S03-01).
 *
 * Snapshot immutability — editing a contact must never mutate issued invoice
 * buyer snapshots — is asserted with task 03 once invoices exist.
 */
class ContactFiscalTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    public function test_fiscal_complete_logic(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create();
        $this->assertTrue($contact->fiscalComplete());

        $missingTaxId = Contact::factory()->fiscalComplete()->create(['tax_id' => null]);
        $this->assertFalse($missingTaxId->fiscalComplete());

        $missingType = Contact::factory()->fiscalComplete()->create(['tax_id_type' => null]);
        $this->assertFalse($missingType->fiscalComplete());

        $missingLine1 = Contact::factory()->fiscalComplete()->create(['billing_address_line1' => null]);
        $this->assertFalse($missingLine1->fiscalComplete());

        $missingCity = Contact::factory()->fiscalComplete()->create(['billing_city' => null]);
        $this->assertFalse($missingCity->fiscalComplete());

        $missingPostal = Contact::factory()->fiscalComplete()->create(['billing_postal_code' => null]);
        $this->assertFalse($missingPostal->fiscalComplete());

        $missingCountry = Contact::factory()->fiscalComplete()->create(['billing_country_code' => null]);
        $this->assertFalse($missingCountry->fiscalComplete());

        $noNames = Contact::factory()->fiscalComplete()->create([
            'first_name' => '',
            'last_name' => '',
            'billing_name' => null,
        ]);
        $this->assertFalse($noNames->fiscalComplete());

        $billingNameOnly = Contact::factory()->fiscalComplete()->create([
            'first_name' => '',
            'last_name' => '',
            'billing_name' => 'ACME SL',
        ]);
        $this->assertTrue($billingNameOnly->fiscalComplete());
    }

    public function test_updates_log_crm_activity(): void
    {
        $contact = Contact::factory()->create();

        $this->patchJson("/api/contacts/{$contact->id}", [
            'tax_id' => '12345678Z',
            'tax_id_type' => TaxIdType::Nif->value,
            'billing_address_line1' => 'Calle Mayor 1',
            'billing_city' => 'Madrid',
            'billing_postal_code' => '28013',
            'billing_country_code' => 'es',
        ])->assertOk()
            ->assertJsonPath('data.tax_id', '12345678Z')
            ->assertJsonPath('data.billing_country_code', 'ES')
            ->assertJsonPath('data.fiscal_complete', true);

        $activity = Activity::query()
            ->where('subject_id', $contact->id)
            ->where('subject_type', 'contact')
            ->where('log_name', LogChannel::Crm->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
    }

    public function test_redaction_clears_fiscal_fields(): void
    {
        $contact = Contact::factory()->create();

        RecordsActivity::core('updated', $contact, [
            'tax_id' => '12345678Z',
            'billing_name' => 'Secret Buyer',
            'billing_city' => 'Madrid',
            'safe' => 'keep-me',
        ]);

        $this->artisan('contacts:redact', ['contact' => $contact->id])->assertSuccessful();

        $activity = Activity::query()
            ->where('description', 'updated')
            ->where('subject_id', $contact->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->properties->get('tax_id'));
        $this->assertNull($activity->properties->get('billing_name'));
        $this->assertNull($activity->properties->get('billing_city'));
        $this->assertSame('keep-me', $activity->properties->get('safe'));
    }

    public function test_rejects_invalid_tax_id(): void
    {
        $contact = Contact::factory()->create();

        $this->patchJson("/api/contacts/{$contact->id}", [
            'tax_id' => '12345678A',
            'tax_id_type' => TaxIdType::Nif->value,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['tax_id']);
    }

    public function test_persists_fiscal_fields(): void
    {
        $contact = Contact::factory()->create();

        $this->patchJson("/api/contacts/{$contact->id}", [
            'billing_name' => 'ACME SL',
            'tax_id' => 'es-b12345674',
            'tax_id_type' => TaxIdType::Nif->value,
            'billing_address_line1' => 'Calle Mayor 1',
            'billing_address_line2' => 'Piso 2',
            'billing_city' => 'Madrid',
            'billing_postal_code' => '28013',
            'billing_country_code' => 'ES',
        ])->assertOk()
            ->assertJsonPath('data.billing_name', 'ACME SL')
            ->assertJsonPath('data.tax_id', 'ESB12345674')
            ->assertJsonPath('data.tax_id_type', 'nif')
            ->assertJsonPath('data.billing_address_line1', 'Calle Mayor 1')
            ->assertJsonPath('data.billing_address_line2', 'Piso 2')
            ->assertJsonPath('data.billing_city', 'Madrid')
            ->assertJsonPath('data.billing_postal_code', '28013')
            ->assertJsonPath('data.billing_country_code', 'ES')
            ->assertJsonPath('data.fiscal_complete', true);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.tax_id', 'ESB12345674')
            ->assertJsonPath('data.fiscal_complete', true);
    }
}
