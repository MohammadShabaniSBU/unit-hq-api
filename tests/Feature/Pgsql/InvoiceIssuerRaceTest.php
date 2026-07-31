<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * Postgres-only: two parallel full issuer transactions get consecutive numbers.
 */
class InvoiceIssuerRaceTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    /** @var list<string|null> */
    protected $connectionsToTransact = [];

    public function test_concurrent_issuer_allocations_distinct_consecutive(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Issuer race test is Postgres-only.');
        }

        $employee = Employee::factory()->manager()->create();
        // Unique code — this test disables per-test transactions, so a fixed
        // 'ES' would collide with later suites that also create ES.
        $country = Country::factory()->create(['code' => 'ZZ']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '50.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);

        $series = InvoiceSeries::query()
            ->where('legal_entity_id', $entity->id)
            ->where('kind', 'simplified')
            ->where('is_default', true)
            ->firstOrFail();
        $series->update(['next_number' => 1]);

        $contractIds = [];
        $contactIds = [];
        $unitIds = [];
        for ($i = 0; $i < 2; $i++) {
            $unit = Unit::factory()->create([
                'site_id' => $site->id,
                'unit_class_id' => $unitClass->id,
            ]);
            $unitIds[] = $unit->id;
            $contact = Contact::factory()->create();
            $contactIds[] = $contact->id;
            $contract = Contract::factory()->create([
                'contact_id' => $contact->id,
                'currency' => 'EUR',
                'deposit_amount' => '0.00',
            ]);
            $contract->items()->create([
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'price_id' => $price->id,
                'effective_from' => '2026-07-01',
                'effective_to' => null,
            ]);
            Charge::query()->create([
                'contract_id' => $contract->id,
                'charge_type' => ChargeType::Rent,
                'net_amount' => '50.00',
                'tax_amount' => '0.00',
                'amount' => '50.00',
                'currency' => 'EUR',
                'tax_rate_snapshot' => '0.00',
                'due_date' => '2026-07-01',
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'description' => 'Rent',
            ]);
            $contractIds[] = $contract->id;
        }

        $basePath = base_path();
        $scriptPath = sys_get_temp_dir().'/invoice_issuer_race_'.uniqid('', true).'.php';
        $idsExport = var_export($contractIds, true);

        file_put_contents($scriptPath, <<<PHP
<?php

declare(strict_types=1);

require '{$basePath}/vendor/autoload.php';

\$app = require '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$contractIds = {$idsExport};
\$contractId = \$contractIds[(int) \$argv[1]];

usleep(random_int(1000, 50000));

\$fullNumber = Illuminate\Support\Facades\DB::transaction(function () use (\$contractId) {
    \$contract = App\Models\Contract::query()->with(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity'])->findOrFail(\$contractId);
    \$charges = App\Models\Charge::query()->where('contract_id', \$contractId)->get();
    \$invoice = App\Support\Fiscal\InvoiceIssuer::issue(\$contract, \$charges);

    return \$invoice?->full_number ?? '';
});

echo \$fullNumber;
PHP);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        ];

        try {
            $results = Process::concurrently(function (Pool $pool) use ($scriptPath, $env, $basePath) {
                $pool->as('a')->path($basePath)->env($env)->command(['php', $scriptPath, '0']);
                $pool->as('b')->path($basePath)->env($env)->command(['php', $scriptPath, '1']);
            });

            $numbers = [];
            foreach (['a', 'b'] as $key) {
                $result = $results[$key];
                $this->assertTrue(
                    $result->successful(),
                    "Worker {$key} failed: {$result->errorOutput()} {$result->output()}"
                );
                $numbers[] = trim($result->output());
            }

            sort($numbers);
            $this->assertCount(2, array_unique($numbers));
            $this->assertSame(3, $series->fresh()->next_number);
            $this->assertSame(2, Invoice::query()->where('invoice_series_id', $series->id)->count());
        } finally {
            @unlink($scriptPath);

            // This test disables per-test transactions — scrub committed rows so
            // later suites (e.g. SeederIntegrity) see a clean database.
            $invoiceIds = Invoice::query()->whereIn('contract_id', $contractIds)->pluck('id');
            DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('charges')->whereIn('contract_id', $contractIds)->update(['invoice_id' => null]);
            Invoice::query()->whereIn('id', $invoiceIds)->delete();
            DB::table('charges')->whereIn('contract_id', $contractIds)->delete();
            DB::table('contract_items')->whereIn('contract_id', $contractIds)->delete();
            Contract::query()->whereIn('id', $contractIds)->delete();
            Contact::query()->whereIn('id', $contactIds)->delete();
            Unit::query()->whereIn('id', $unitIds)->delete();
            $unitClass->forceFill(['current_price_id' => null])->save();
            UnitClassRate::query()->where('site_id', $site->id)->where('unit_class_id', $unitClass->id)->delete();
            Price::query()->whereKey($price->id)->delete();
            $unitClass->delete();
            $site->delete();
            InvoiceSeries::query()->where('legal_entity_id', $entity->id)->delete();
            $entity->delete();
            $country->delete();
            $employee->delete();
        }
    }
}
