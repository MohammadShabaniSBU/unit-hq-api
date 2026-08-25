<?php

declare(strict_types=1);

namespace Tests\Feature\Facility;

use App\Enums\SiteServiceAreaKind;
use App\Models\Site;
use App\Models\SiteServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\TestCase;

class SiteServiceAreaApiTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    #[Test]
    public function store_lists_and_archives_a_service_area(): void
    {
        $site = Site::factory()->create();

        $created = $this->postJson("/api/sites/{$site->id}/service-areas", [
            'kind' => SiteServiceAreaKind::PostcodePrefix->value,
            'value' => '280',
        ])->assertCreated()->json('data');

        $this->assertSame('280', $created['value']);
        $this->assertSame(SiteServiceAreaKind::PostcodePrefix->value, $created['kind']);
        $this->assertNull($created['archived_at']);

        $this->getJson("/api/sites/{$site->id}/service-areas")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson("/api/site-service-areas/{$created['id']}/archive")
            ->assertOk();

        $this->assertNotNull(SiteServiceArea::query()->findOrFail($created['id'])->archived_at);

        $this->getJson("/api/sites/{$site->id}/service-areas")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/sites/{$site->id}/service-areas?status=archived")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson("/api/site-service-areas/{$created['id']}/unarchive")
            ->assertOk();

        $this->assertNull(SiteServiceArea::query()->findOrFail($created['id'])->fresh()->archived_at);
    }

    #[Test]
    public function duplicate_live_row_is_rejected(): void
    {
        $site = Site::factory()->create();
        SiteServiceArea::factory()->create([
            'site_id' => $site->id,
            'kind' => SiteServiceAreaKind::Postcode,
            'value' => '28001',
        ]);

        $this->postJson("/api/sites/{$site->id}/service-areas", [
            'kind' => SiteServiceAreaKind::Postcode->value,
            'value' => '28001',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }
}
