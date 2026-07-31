<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FiscalRegime;
use App\Enums\TaxIdType;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalEntity>
 */
class LegalEntityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => fake()->company(),
            'trading_name' => null,
            'tax_id' => fake()->unique()->bothify('B########'),
            'tax_id_type' => TaxIdType::Nif,
            'vat_number' => null,
            'country_code' => 'ES',
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'fiscal_regime' => FiscalRegime::None,
            'sepa_creditor_id' => null,
            'archived_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (LegalEntity $entity): void {
            InvoiceSeries::ensureDefaultsFor($entity);
        });
    }
}
