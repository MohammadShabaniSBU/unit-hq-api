<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FiscalRegime;
use App\Enums\TaxIdType;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Support\Country\CountryProfiles;
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
            'tax_id' => $this->validCif(),
            'tax_id_type' => TaxIdType::Nif,
            'vat_number' => null,
            'country_code' => CountryProfiles::current()->code(),
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

    /** Spanish CIF (org letter B + 7 digits + digit control). */
    private function validCif(): string
    {
        $digits = str_pad((string) fake()->unique()->numberBetween(0, 9_999_999), 7, '0', STR_PAD_LEFT);
        $evenSum = 0;
        $oddSum = 0;

        for ($i = 0; $i < 7; $i++) {
            $digit = (int) $digits[$i];
            if (($i % 2) === 0) {
                $doubled = $digit * 2;
                $oddSum += intdiv($doubled, 10) + ($doubled % 10);
            } else {
                $evenSum += $digit;
            }
        }

        $controlDigit = (10 - (($evenSum + $oddSum) % 10)) % 10;

        return 'B'.$digits.$controlDigit;
    }
}
