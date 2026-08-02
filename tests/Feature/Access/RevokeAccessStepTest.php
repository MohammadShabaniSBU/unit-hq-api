<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessSuspensionReason;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Models\AccessSuspension;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Access\DesiredAccess;
use App\Support\Delinquency\DelinquencyEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class RevokeAccessStepTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_fact_only_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create([
            'auto_release_overlock' => true,
            'auto_restore_access' => true,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::RevokeAccess,
            'params' => [],
            'sort' => 1,
        ]);

        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $policy->id,
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);
        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'started_on' => '2026-06-01',
            'ended_on' => null,
            'created_by' => $employee->id,
        ]);
        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);

        (new DelinquencyEngine)->run((int) $contract->id);

        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $step = DelinquencyStep::query()
            ->where('delinquency_id', $case->id)
            ->where('action', DelinquencyStepAction::RevokeAccess)
            ->firstOrFail();

        $this->assertNotNull($step->access_suspension_id);
        $this->assertFalse((bool) ($step->detail['already_suspended'] ?? true));

        $suspension = AccessSuspension::query()->findOrFail($step->access_suspension_id);
        $this->assertTrue($suspension->isActive());
        $this->assertSame(AccessSuspensionReason::Delinquency, $suspension->reason);
        $this->assertSame($case->id, $suspension->delinquency_id);
        $this->assertTrue(DesiredAccess::forContract($contract->fresh())->isEmpty());

        // Pre-suspend then re-execute via a second policy step — already_suspended.
        $second = DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 2,
            'action' => DelinquencyPolicyAction::RevokeAccess,
            'params' => [],
            'sort' => 2,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->run((int) $contract->id);

        $secondStep = DelinquencyStep::query()
            ->where('delinquency_id', $case->id)
            ->where('policy_step_id', $second->id)
            ->firstOrFail();

        $this->assertTrue((bool) ($secondStep->detail['already_suspended'] ?? false));
        $this->assertSame($suspension->id, $secondStep->access_suspension_id);
        $this->assertSame(1, AccessSuspension::query()->active()->where('contract_id', $contract->id)->count());

        $this->assertDelinquencyHasNoAdapterReferences();

        Carbon::setTestNow();
    }

    private function assertDelinquencyHasNoAdapterReferences(): void
    {
        $roots = [
            app_path('Support/Delinquency'),
            app_path('Http/Controllers/DelinquencyController.php'),
            app_path('Http/Controllers/DelinquencyPolicyController.php'),
        ];
        $forbidden = [
            'AccessProvider',
            'SensorbergAccessProvider',
            'FakeAccessProvider',
            'AccessReconciler',
            'AccessProviderRegistry',
        ];

        foreach ($roots as $root) {
            $files = is_dir($root)
                ? new RegexIterator(
                    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
                    '/\.php$/',
                )
                : [new \SplFileInfo($root)];

            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($forbidden as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $contents,
                        "Delinquency code must not reference {$needle}: {$file->getPathname()}",
                    );
                }
            }
        }
    }
}
