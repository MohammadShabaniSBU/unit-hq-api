<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\Unit;
use App\Models\UnitClass;
use Database\Seeders\ContractDocumentTemplateSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;

trait CreatesContractDocumentFixtures
{
    use CreatesCataloguePrices;

    protected Employee $employee;

    protected Site $site;

    protected Unit $unit;

    protected LegalEntity $legalEntity;

    protected TemplateFamily $documentFamily;

    protected function seedDocumentWorld(): void
    {
        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $this->legalEntity = LegalEntity::factory()->create([
            'legal_name' => 'Acme Storage SL',
            'tax_id' => 'B12345678',
            'country_code' => 'ES',
            'address_line1' => 'Calle Emisor 1',
            'city' => 'Madrid',
            'postal_code' => '28001',
        ]);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $this->legalEntity->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);

        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '125.50', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'A-101',
        ]);

        $this->seed(ContractDocumentTemplateSeeder::class);
        $this->documentFamily = TemplateFamily::query()
            ->where('channel', TemplateChannel::Document)
            ->where('purpose', TemplatePurpose::Contract)
            ->firstOrFail();
    }

    protected function createRemoteContract(?Contact $contact = null, string $amount = '125.50'): Contract
    {
        $contact ??= Contact::factory()->create(['locale' => 'es']);

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-08-01',
            'move_in_date' => '2026-08-01',
            'deposit_amount' => '200.00',
            'signature_mode' => 'remote',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => $amount,
            ]],
        ]);
        $response->assertCreated();

        return Contract::query()->findOrFail((int) $response->json('data.id'));
    }

    /**
     * @return array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}
     */
    protected function minimalContractBlocks(): array
    {
        return [
            'version' => 1,
            'blocks' => [
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'parties',
                    'params' => [],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'terms_table',
                    'params' => [],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'signature_anchor',
                    'params' => [],
                ],
            ],
        ];
    }

    protected function variant(string $locale): TemplateVariant
    {
        return $this->documentFamily->variants()->where('locale', $locale)->firstOrFail();
    }
}
