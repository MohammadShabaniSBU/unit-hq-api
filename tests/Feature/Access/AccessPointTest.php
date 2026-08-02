<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Enums\AccessPointType;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessPointTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private UnitClass $unitClass;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountId = AccessProviderAccount::factory()->create()->id;

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->unitClass = UnitClass::factory()->create();
    }

    public function test_uniqueness_constraints_provider_point_id(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is Postgres-only.');
        }

        AccessPoint::factory()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'unit_id' => null,
            'point_type' => AccessPointType::Gate,
            'provider_point_id' => 'gate-1',
            'label' => 'Gate',
        ]);

        // Archived may reuse the same provider id.
        AccessPoint::factory()->gate()->archived()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'gate-1',
            'label' => 'Old gate',
        ]);

        $this->expectException(QueryException::class);
        AccessPoint::factory()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'unit_id' => null,
            'point_type' => AccessPointType::Gate,
            'provider_point_id' => 'gate-1',
            'label' => 'Gate duplicate',
        ]);
    }

    public function test_uniqueness_constraints_one_live_door_per_unit(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is Postgres-only.');
        }

        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-1',
        ]);

        $this->expectException(QueryException::class);
        AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-2',
        ]);
    }

    public function test_archived_door_frees_unit_slot(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is Postgres-only.');
        }

        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);

        $archivedDoor = AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-b1',
        ]);
        $archivedDoor->archive();

        $replacement = AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'door-b2',
        ]);

        $this->assertNull($replacement->archived_at);
    }

    public function test_uniqueness_constraints_live_grant(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is Postgres-only.');
        }

        $point = AccessPoint::factory()->gate()->create([
            'access_provider_account_id' => $this->accountId,
            'site_id' => $this->site->id,
            'provider_point_id' => 'gate-1',
        ]);
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);

        AccessGrant::factory()->create([
            'access_point_id' => $point->id,
            'contact_id' => $contact->id,
            'contract_id' => $contract->id,
            'state' => AccessGrantState::Applied,
        ]);

        $this->expectException(QueryException::class);
        AccessGrant::factory()->applying()->create([
            'access_point_id' => $point->id,
            'contact_id' => $contact->id,
            'contract_id' => $contract->id,
        ]);
    }
}
