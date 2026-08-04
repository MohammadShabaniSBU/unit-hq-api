<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\BillingRun;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Models\User;
use App\Support\Auth\SubjectSite;
use App\Support\Auth\UnresolvableSubjectSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectSiteTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private Unit $unitA;

    private Unit $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::factory()->create(['code' => 'ES']);
        $this->siteA = Site::factory()->create(['country_id' => $country->id]);
        $this->siteB = Site::factory()->create(['country_id' => $country->id]);
        $unitClass = UnitClass::factory()->create();
        $this->unitA = Unit::factory()->create([
            'site_id' => $this->siteA->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $this->unitB = Unit::factory()->create([
            'site_id' => $this->siteB->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    #[Test]
    public function resolves_contract_via_open_occupancy(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unitA->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        $site = SubjectSite::for($contract->fresh());

        $this->assertNotNull($site);
        $this->assertSame($this->siteA->id, $site->id);
    }

    #[Test]
    public function resolves_transferred_contract_to_open_occupancy(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unitA->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => '2026-03-01',
            'ended_reason' => 'transferred',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $this->unitB->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-03-01',
            'ended_on' => null,
        ]);

        $site = SubjectSite::for($contract->fresh());

        $this->assertNotNull($site);
        $this->assertSame($this->siteB->id, $site->id);
    }

    #[Test]
    public function resolves_ended_contract_to_latest_occupancy(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unitA->id,
            'contract_id' => $contract->id,
            'started_on' => '2025-01-01',
            'ended_on' => '2025-06-01',
            'ended_reason' => 'vacated',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $this->unitB->id,
            'contract_id' => $contract->id,
            'started_on' => '2025-06-01',
            'ended_on' => '2026-01-01',
            'ended_reason' => 'vacated',
        ]);

        $site = SubjectSite::for($contract->fresh());

        $this->assertNotNull($site);
        $this->assertSame($this->siteB->id, $site->id);
    }

    #[Test]
    public function company_level_subjects_resolve_null(): void
    {
        $this->assertNull(SubjectSite::for(new TaxRate));
        $this->assertNull(SubjectSite::for(new BillingRun));
    }

    #[Test]
    public function unmapped_subject_throws(): void
    {
        $this->expectException(UnresolvableSubjectSite::class);

        SubjectSite::for(new User);
    }
}
