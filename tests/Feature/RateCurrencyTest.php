<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class RateCurrencyTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    public function test_site_currency_mismatch_on_rate_junction(): void
    {
        Employee::factory()->manager()->create();

        $site = Site::factory()->create([
            'country_id' => Country::factory()->create(['code' => 'GB'])->id,
            'currency' => 'GBP',
        ]);
        $unitClass = UnitClass::factory()->create();

        $rejected = $this->postJson("/api/unit-classes/{$unitClass->id}/prices", [
            'site_id' => $site->id,
            'amount' => '100.00',
            'currency' => 'EUR',
        ]);

        $rejected->assertStatus(422)->assertJsonValidationErrors(['currency']);

        $allowed = $this->postJson("/api/unit-classes/{$unitClass->id}/prices", [
            'site_id' => $site->id,
            'amount' => '100.00',
            'currency' => 'EUR',
            'allow_currency_mismatch' => true,
        ]);

        $allowed->assertCreated();
        $this->assertSame('EUR', $allowed->json('data.currency'));
    }

    public function test_price_currency_prefills_from_site_when_omitted(): void
    {
        Employee::factory()->manager()->create();

        $site = Site::factory()->create([
            'country_id' => Country::factory()->create(['code' => 'GB'])->id,
            'currency' => 'GBP',
        ]);
        $unitClass = UnitClass::factory()->create();

        $response = $this->postJson("/api/unit-classes/{$unitClass->id}/prices", [
            'site_id' => $site->id,
            'amount' => '88.00',
        ]);

        $response->assertCreated();
        $this->assertSame('GBP', $response->json('data.currency'));
    }
}
