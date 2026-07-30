<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Support\Billing\JurisdictionCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Employee::factory()->manager()->create();
    }

    public function test_jurisdiction_accepts_null_country_and_subdivision(): void
    {
        foreach ([null, 'ES', 'ES-CN'] as $jurisdiction) {
            $payload = [
                'name' => 'VAT '.($jurisdiction ?? 'any'),
                'code' => 'vat_'.($jurisdiction ?? 'null'),
                'rate' => 21,
            ];

            if ($jurisdiction !== null) {
                $payload['jurisdiction'] = $jurisdiction;
            }

            $this->postJson('/api/tax-rates', $payload)->assertCreated();
        }

        $this->assertTrue(JurisdictionCode::isValid(null));
        $this->assertTrue(JurisdictionCode::isValid('ES'));
        $this->assertTrue(JurisdictionCode::isValid('ES-CN'));
    }

    public function test_jurisdiction_rejects_malformed_codes(): void
    {
        foreach (['esp', 'ES_CN', 'Spain', 'es-cn'] as $jurisdiction) {
            $this->postJson('/api/tax-rates', [
                'name' => 'Bad',
                'code' => 'bad_'.md5($jurisdiction),
                'rate' => 21,
                'jurisdiction' => $jurisdiction,
            ])->assertStatus(422)->assertJsonValidationErrors(['jurisdiction']);
        }
    }
}
