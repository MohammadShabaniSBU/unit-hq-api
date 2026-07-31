<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\LegalEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_id_required_on_create(): void
    {
        $response = $this->postJson('/api/sites', [
            'name' => 'No Country Storage',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => LegalEntity::factory()->create()->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['country_id']);
    }

    public function test_country_id_accepted_on_create(): void
    {
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();

        $response = $this->postJson('/api/sites', [
            'name' => 'Madrid Storage',
            'timezone' => 'Europe/Madrid',
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sites', [
            'name' => 'Madrid Storage',
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
        ]);
    }
}
