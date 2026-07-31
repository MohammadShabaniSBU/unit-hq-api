<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Fiscal;

use App\Models\Charge;
use App\Models\Contact;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Fiscal\TaxResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class TaxResolverTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
    }

    public function test_jurisdiction_match_wins(): void
    {
        $this->seedVatCatalogue();

        $es = $this->siteIn('ES');
        $fr = $this->siteIn('FR');
        $gb = $this->siteIn('GB');

        $this->assertSame('21.00', (string) TaxResolver::resolve(null, 'vat', $es)->rate);
        $this->assertSame('20.00', (string) TaxResolver::resolve(null, 'vat', $fr)->rate);
        $this->assertSame('20.00', (string) TaxResolver::resolve(null, 'vat', $gb)->rate);
        $this->assertSame('ES', TaxResolver::resolve(null, 'vat', $es)->jurisdiction);
        $this->assertSame('FR', TaxResolver::resolve(null, 'vat', $fr)->jurisdiction);
        $this->assertSame('GB', TaxResolver::resolve(null, 'vat', $gb)->jurisdiction);
    }

    public function test_null_fallback_when_no_match(): void
    {
        TaxRate::query()->create([
            'name' => 'VAT Universal',
            'code' => 'vat',
            'rate' => '19.00',
            'jurisdiction' => null,
            'is_default' => false,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $site = $this->siteIn('DE');

        $resolved = TaxResolver::resolve(null, 'vat', $site);

        $this->assertNotNull($resolved);
        $this->assertNull($resolved->jurisdiction);
        $this->assertSame('19.00', (string) $resolved->rate);
    }

    public function test_unresolvable_fails_loudly(): void
    {
        TaxRate::query()->create([
            'name' => 'VAT (ES)',
            'code' => 'vat',
            'rate' => '21.00',
            'jurisdiction' => 'ES',
            'is_default' => false,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $frSite = $this->siteIn('FR');

        try {
            TaxResolver::resolve(null, 'vat', $frSite);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tax_rate', $e->errors());
            $this->assertStringContainsString('vat', $e->errors()['tax_rate'][0]);
            $this->assertStringContainsString('FR', $e->errors()['tax_rate'][0]);
        }
    }

    public function test_override_bypasses_filter(): void
    {
        Log::spy();

        $this->seedVatCatalogue();

        $frRate = TaxRate::query()->where('jurisdiction', 'FR')->firstOrFail();
        $esSite = $this->siteIn('ES');

        $resolved = TaxResolver::resolve($frRate->id, 'vat', $esSite);

        $this->assertSame($frRate->id, $resolved?->id);
        $this->assertSame('FR', $resolved?->jurisdiction);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'tax.override'
                && ($context['tax_rate_id'] ?? null) === $frRate->id
                && ($context['site_id'] ?? null) === $esSite->id
                && ($context['country_code'] ?? null) === 'ES');
    }

    public function test_all_call_sites_use_resolver(): void
    {
        $appRoot = app_path();

        $billing = File::get($appRoot.'/Support/Billing/ContractBilling.php');
        $this->assertStringNotContainsString(
            'function resolveTaxRate',
            $billing,
            'ContractBilling::resolveTaxRate must be removed',
        );

        $callSiteFiles = [
            $appRoot.'/Http/Controllers/Concerns/GeneratesFirstPeriodCharges.php',
            $appRoot.'/Http/Controllers/ReservationController.php',
            $appRoot.'/Support/Billing/TransferSettlement.php',
        ];

        foreach ($callSiteFiles as $path) {
            $contents = File::get($path);
            $this->assertStringContainsString(
                'TaxResolver',
                $contents,
                basename($path).' must route through TaxResolver',
            );
            $this->assertStringNotContainsString(
                'ContractBilling::resolveTaxRate',
                $contents,
                basename($path).' must not call ContractBilling::resolveTaxRate',
            );
        }

        foreach (File::allFiles($appRoot) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $this->assertStringNotContainsString(
                'ContractBilling::resolveTaxRate',
                $file->getContents(),
                $file->getRelativePathname().' still references ContractBilling::resolveTaxRate',
            );
        }
    }

    public function test_historical_snapshots_untouched(): void
    {
        $esRate = TaxRate::query()->create([
            'name' => 'VAT (ES)',
            'code' => 'vat',
            'rate' => '21.00',
            'jurisdiction' => 'ES',
            'is_default' => true,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $site = $this->siteIn('ES');
        $unitClass = UnitClass::factory()->create(['tax_rate_code' => 'vat']);
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $this->employee->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '100.00',
            ]],
        ]);

        $response->assertCreated();
        $contractId = (int) $response->json('data.id');

        $item = ContractItem::query()->where('contract_id', $contractId)->firstOrFail();
        $charge = Charge::query()
            ->where('contract_id', $contractId)
            ->where('charge_type', 'rent')
            ->firstOrFail();

        $this->assertSame($esRate->id, $item->tax_rate_id);
        $this->assertSame('21.00', (string) $item->tax_rate_snapshot);
        $this->assertSame('21.00', (string) $charge->tax_rate_snapshot);

        // Mutate the catalogue after signing — snapshots must stay frozen.
        $esRate->update([
            'effective_to' => '2026-07-10',
            'is_default' => false,
        ]);
        TaxRate::query()->create([
            'name' => 'VAT (ES) new',
            'code' => 'vat',
            'rate' => '10.00',
            'jurisdiction' => 'ES',
            'is_default' => true,
            'effective_from' => '2026-07-11',
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $item->refresh();
        $charge->refresh();

        $this->assertSame($esRate->id, $item->tax_rate_id);
        $this->assertSame('21.00', (string) $item->tax_rate_snapshot);
        $this->assertSame('21.00', (string) $charge->tax_rate_snapshot);
    }

    public function test_org_default_uses_jurisdiction_preference(): void
    {
        $this->seedVatCatalogue();

        $fr = $this->siteIn('FR');

        // No product code — fall through to org default code `vat`, prefer FR.
        $resolved = TaxResolver::resolve(null, null, $fr);

        $this->assertNotNull($resolved);
        $this->assertSame('FR', $resolved->jurisdiction);
        $this->assertSame('20.00', (string) $resolved->rate);
    }

    public function test_no_code_and_no_default_returns_null(): void
    {
        $site = $this->siteIn('ES');

        $this->assertNull(TaxResolver::resolve(null, null, $site));
    }

    /**
     * @return array{ES: TaxRate, FR: TaxRate, GB: TaxRate}
     */
    private function seedVatCatalogue(): array
    {
        $rates = [];

        foreach (
            [
                'ES' => ['21.00', true],
                'FR' => ['20.00', false],
                'GB' => ['20.00', false],
            ] as $jurisdiction => [$rate, $isDefault]
        ) {
            $rates[$jurisdiction] = TaxRate::query()->create([
                'name' => 'VAT ('.$jurisdiction.')',
                'code' => 'vat',
                'rate' => $rate,
                'jurisdiction' => $jurisdiction,
                'is_default' => $isDefault,
                'effective_from' => '2020-01-01',
                'effective_to' => null,
                'created_by' => $this->employee->id,
            ]);
        }

        return $rates;
    }

    private function siteIn(string $countryCode): Site
    {
        $country = Country::factory()->create([
            'code' => $countryCode,
            'name' => $countryCode,
        ]);

        return Site::factory()->create([
            'country_id' => $country->id,
            'currency' => $countryCode === 'GB' ? 'GBP' : 'EUR',
        ]);
    }
}
