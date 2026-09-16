<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DeploymentIdentity;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Support\Country\CountryProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\TestCase;

class DeploymentCountryTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        CountryProfiles::syncIdentity();
        $this->authenticateAsEmployee();
    }

    public function test_deployment_endpoint_returns_profile(): void
    {
        $response = $this->getJson('/api/deployment');

        $response->assertOk()
            ->assertJsonPath('data.country', 'ES')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.default_locale', 'es')
            ->assertJsonPath('data.fiscal_regime', 'es_verifactu');
        $this->assertSame(['Europe/Madrid'], $response->json('data.allowed_timezones'));
    }

    public function test_country_command_prints_profile(): void
    {
        $this->artisan('deployment:country')
            ->assertSuccessful()
            ->expectsOutputToContain('EUR');
    }

    public function test_first_site_locks_country(): void
    {
        $this->assertFalse(CountryProfiles::isLocked());

        Site::factory()->create([
            'country_id' => CountryProfiles::countryId(),
            'timezone' => 'Europe/Madrid',
        ]);

        $this->assertTrue(CountryProfiles::isLocked());
        $this->assertDatabaseHas('activity_log', ['description' => 'deployment.country_locked']);
    }

    public function test_env_change_after_lock_fails_health_check(): void
    {
        LegalEntity::factory()->create(['country_code' => 'ES']);
        $this->assertTrue(CountryProfiles::isLocked());

        config(['deployment.country' => 'FR']);
        CountryProfiles::resetResolved();

        $this->assertTrue(CountryProfiles::isLockedMismatch());
        $this->get('/up')->assertServerError();

        $this->artisan('deployment:audit-country')
            ->assertFailed()
            ->expectsOutputToContain('locked');
    }

    public function test_env_change_before_lock_updates_identity(): void
    {
        $identity = DeploymentIdentity::instance();
        $this->assertNotNull($identity);
        $this->assertFalse($identity->isLocked());

        config(['deployment.country' => 'FR']);
        CountryProfiles::resetResolved();
        CountryProfiles::syncIdentity();

        $this->assertSame('FR', DeploymentIdentity::instance()?->country_code);
        $this->assertDatabaseHas('activity_log', ['description' => 'deployment.country_changed']);
    }

    public function test_mismatch_blocks_api(): void
    {
        Site::factory()->create([
            'country_id' => CountryProfiles::countryId(),
            'timezone' => 'Europe/Madrid',
        ]);

        config(['deployment.country' => 'GB']);
        CountryProfiles::resetResolved();

        $this->getJson('/api/deployment')
            ->assertStatus(503)
            ->assertJsonPath('message', 'deployment_country_mismatch');
    }
}
