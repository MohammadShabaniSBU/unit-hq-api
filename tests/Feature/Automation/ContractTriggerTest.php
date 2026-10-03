<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationNodeType;
use App\Enums\ContractStatus;
use App\Events\ModelCreated;
use App\Events\ModelUpdated;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Contracts\ContractSigning;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ContractTriggerTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use AutomationGraph;
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = $this->authenticateAsEmployee();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 10:00:00', 'Europe/Madrid'));

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $this->contact = Contact::factory()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_signed_at_update_dispatches_model_updated(): void
    {
        Event::fake([ModelCreated::class, ModelUpdated::class]);

        $contract = Contract::factory()->create(['signed_at' => null]);

        Event::fake([ModelUpdated::class]);

        $contract->forceFill(['signed_at' => now()])->save();

        Event::assertDispatched(ModelUpdated::class, function (ModelUpdated $event) use ($contract): bool {
            return $event->subjectType === 'contract'
                && (int) $event->subjectId === (int) $contract->id
                && array_key_exists('signed_at', $event->dirty);
        });
    }

    public function test_signing_enrols_one_run_and_later_saves_do_not(): void
    {
        $this->buildGraph(
            [
                [
                    'key' => 't',
                    'type' => AutomationNodeType::ObjectUpdated,
                    'config' => [
                        'objectType' => 'contract',
                        'property' => 'signed_at',
                        'conditions' => [
                            ['operator' => 'is_not_empty'],
                        ],
                    ],
                ],
            ],
            [],
        );

        $create = $this->postJson('/api/contracts', [
            'contact_id' => $this->contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'signature_mode' => 'remote',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '100.00',
            ]],
        ])->assertCreated();

        $contract = Contract::query()->findOrFail($create->json('data.id'));
        $this->assertNull($contract->signed_at);
        $this->assertSame(0, AutomationRun::query()->where('subject_type', 'contract')->count());

        DB::transaction(function () use ($contract): void {
            ContractSigning::complete($contract, null, $this->employee->id);
        });

        $runs = AutomationRun::query()
            ->where('subject_type', 'contract')
            ->where('subject_id', $contract->id)
            ->get();
        $this->assertCount(1, $runs);
        $this->assertSame('updated', $runs->first()->trigger_payload['lifecycle'] ?? null);
        $this->assertArrayHasKey('signed_at', $runs->first()->trigger_payload['dirty'] ?? []);

        $contract->refresh();
        $this->assertNotNull($contract->signed_at);
        $this->assertContains($contract->status, [ContractStatus::Pending, ContractStatus::Active]);

        $contract->forceFill(['notice_period_days' => 30])->save();

        $this->assertSame(
            1,
            AutomationRun::query()->where('subject_type', 'contract')->where('subject_id', $contract->id)->count(),
        );
    }
}
