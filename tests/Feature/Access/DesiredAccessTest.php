<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessSuspensionReason;
use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\AccessSuspension;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Access\DesiredAccess;
use App\Support\Access\DesiredGrant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesiredAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private UnitClass $unitClass;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00', 'Europe/Madrid'));

        $this->accountId = AccessProviderAccount::factory()->create()->id;

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->unitClass = UnitClass::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: bool}>
     */
    public static function fiveFactorProvider(): array
    {
        // [setup flags, expectDoor, expectGate]
        return [
            'active_occupancy_clear' => [[
                'status' => ContractStatus::Active,
                'occupancy' => true,
                'overlock' => false,
                'suspension' => false,
            ], true, true],
            'notice_given_occupancy_clear' => [[
                'status' => ContractStatus::NoticeGiven,
                'occupancy' => true,
                'overlock' => false,
                'suspension' => false,
            ], true, true],
            'pending_no_access' => [[
                'status' => ContractStatus::Pending,
                'occupancy' => true,
                'overlock' => false,
                'suspension' => false,
            ], false, false],
            'awaiting_signature_no_access' => [[
                'status' => ContractStatus::AwaitingSignature,
                'occupancy' => true,
                'overlock' => false,
                'suspension' => false,
            ], false, false],
            'ended_no_access' => [[
                'status' => ContractStatus::Ended,
                'occupancy' => true,
                'overlock' => false,
                'suspension' => false,
            ], false, false],
            'cancelled_no_access' => [[
                'status' => ContractStatus::Cancelled,
                'occupancy' => true,
                'overlock' => false,
                'suspension' => false,
            ], false, false],
            'no_occupancy_covering_today' => [[
                'status' => ContractStatus::Active,
                'occupancy' => false,
                'overlock' => false,
                'suspension' => false,
            ], false, false],
            'overlocked_gate_yes_door_no' => [[
                'status' => ContractStatus::Active,
                'occupancy' => true,
                'overlock' => true,
                'suspension' => false,
            ], false, true],
            'suspension_total_deny_door' => [[
                'status' => ContractStatus::Active,
                'occupancy' => true,
                'overlock' => false,
                'suspension' => true,
            ], false, false],
            'suspension_total_deny_with_overlock' => [[
                'status' => ContractStatus::Active,
                'occupancy' => true,
                'overlock' => true,
                'suspension' => true,
            ], false, false],
            'occupancy_ended_before_today' => [[
                'status' => ContractStatus::Active,
                'occupancy' => 'ended',
                'overlock' => false,
                'suspension' => false,
            ], false, false],
            'occupancy_starts_tomorrow' => [[
                'status' => ContractStatus::Active,
                'occupancy' => 'future',
                'overlock' => false,
                'suspension' => false,
            ], false, false],
        ];
    }

    /**
     * @param  array<string, mixed>  $flags
     */
    #[DataProvider('fiveFactorProvider')]
    public function test_five_factor_truth_table(array $flags, bool $expectDoor, bool $expectGate): void
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => $flags['status'],
            'start_date' => '2026-07-01',
            'move_in_date' => '2026-07-01',
            'billing_anchor_date' => '2026-07-01',
            'billed_through' => '2026-07-01',
        ]);

        $this->placeOccupancy($unit, $contract, $flags['occupancy']);

        if ($flags['overlock']) {
            UnitHold::factory()->overlock()->create([
                'unit_id' => $unit->id,
                'starts_on' => '2026-08-01',
                'ends_on' => null,
                'released_at' => null,
            ]);
        }

        if ($flags['suspension']) {
            AccessSuspension::query()->create([
                'contract_id' => $contract->id,
                'reason' => AccessSuspensionReason::Manual,
                'created_at' => now(),
            ]);
        }

        $door = AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-1',
        ]);
        $gate = AccessPoint::factory()->gate()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'gate-1',
        ]);

        $desired = DesiredAccess::forSite($this->site);
        $doorIds = $desired->filter(fn (DesiredGrant $g): bool => $g->accessPointId === $door->id);
        $gateIds = $desired->filter(fn (DesiredGrant $g): bool => $g->accessPointId === $gate->id);

        $this->assertSame($expectDoor, $doorIds->isNotEmpty(), 'door grant mismatch');
        $this->assertSame($expectGate, $gateIds->isNotEmpty(), 'gate grant mismatch');

        if ($expectDoor || $expectGate) {
            $sample = $expectDoor ? $doorIds->first() : $gateIds->first();
            $this->assertNotNull($sample);
            $this->assertSame((int) $contact->id, $sample->contactId);
            $this->assertSame((int) $contract->id, $sample->contractId);
        }
    }

    public function test_gate_via_other_unit_occupancy_zone_same(): void
    {
        $unitA = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $unitB = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => ContractStatus::Active,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $unitA->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-07-01',
            'ended_on' => null,
        ]);

        $doorB = AccessPoint::factory()->unitDoor($unitB->id)->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-b',
        ]);
        $gate = AccessPoint::factory()->gate()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'gate-1',
        ]);
        $zone = AccessPoint::factory()->zone()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'zone-1',
        ]);

        $desired = DesiredAccess::forSite($this->site);
        $pointIds = $desired->map(fn (DesiredGrant $g): int => $g->accessPointId)->all();

        $this->assertContains($gate->id, $pointIds);
        $this->assertContains($zone->id, $pointIds);
        $this->assertNotContains($doorB->id, $pointIds);
    }

    public function test_archived_points_excluded(): void
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $contract = Contract::factory()->create(['status' => ContractStatus::Active]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-07-01',
            'ended_on' => null,
        ]);

        AccessPoint::factory()->unitDoor($unit->id)->archived()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-arch',
        ]);
        AccessPoint::factory()->gate()->archived()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'gate-arch',
        ]);

        $this->assertCount(0, DesiredAccess::forSite($this->site));
    }

    public function test_released_overlock_does_not_deny_door(): void
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $contract = Contract::factory()->create(['status' => ContractStatus::Active]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-07-01',
            'ended_on' => null,
        ]);
        UnitHold::factory()->overlock()->released()->create([
            'unit_id' => $unit->id,
            'starts_on' => '2026-08-01',
            'hold_type' => HoldType::Overlock,
        ]);
        $door = AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-1',
        ]);

        $desired = DesiredAccess::forSite($this->site);
        $this->assertTrue($desired->contains(
            fn (DesiredGrant $g): bool => $g->accessPointId === $door->id,
        ));
    }

    public function test_purity_grep(): void
    {
        $dir = app_path('Support/Access');
        $files = [
            $dir.'/DesiredAccess.php',
            $dir.'/DesiredGrant.php',
        ];

        $forbidden = [
            'AccessGrant',
            'access_grants',
            'Sensorberg',
            'Http\\',
            'Illuminate\\Support\\Facades\\Http',
            'AccessProvider',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            $source = file_get_contents($path);
            $this->assertNotFalse($source);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    "DesiredAccess purity violated in {$path}: contains {$needle}",
                );
            }
        }

        // AccessSync is the nudge seam — may exist alongside but DesiredAccess must not use it.
        $desired = file_get_contents($dir.'/DesiredAccess.php');
        $this->assertNotFalse($desired);
        $this->assertStringNotContainsString('AccessSync', $desired);
    }

    private function placeOccupancy(Unit $unit, Contract $contract, mixed $occupancy): void
    {
        if ($occupancy === false) {
            return;
        }

        if ($occupancy === 'ended') {
            UnitOccupancy::query()->create([
                'unit_id' => $unit->id,
                'contract_id' => $contract->id,
                'started_on' => '2026-07-01',
                'ended_on' => '2026-08-10',
            ]);

            return;
        }

        if ($occupancy === 'future') {
            UnitOccupancy::query()->create([
                'unit_id' => $unit->id,
                'contract_id' => $contract->id,
                'started_on' => '2026-08-20',
                'ended_on' => null,
            ]);

            return;
        }

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-07-01',
            'ended_on' => null,
        ]);
    }
}
