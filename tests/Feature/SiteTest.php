<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\LegalEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class SiteTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    public function test_country_id_defaults_to_deployment_country(): void
    {
        $country = Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        $entity = LegalEntity::factory()->create();

        $response = $this->postJson('/api/sites', [
            'name' => 'No Country Storage',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => $entity->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sites', [
            'name' => 'No Country Storage',
            'country_id' => $country->id,
        ]);
    }

    public function test_foreign_country_rejected(): void
    {
        Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        $gb = Country::factory()->create(['code' => 'GB', 'name' => 'United Kingdom']);
        $entity = LegalEntity::factory()->create();

        $this->postJson('/api/sites', [
            'name' => 'London Storage',
            'timezone' => 'Europe/Madrid',
            'country_id' => $gb->id,
            'legal_entity_id' => $entity->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['country_id']);
    }

    public function test_timezone_must_be_allowed(): void
    {
        Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        $entity = LegalEntity::factory()->create();

        $this->postJson('/api/sites', [
            'name' => 'Canary Storage',
            'timezone' => 'Atlantic/Canary',
            'legal_entity_id' => $entity->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['timezone']);
    }
}
