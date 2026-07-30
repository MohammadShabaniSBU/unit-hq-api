<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    protected $model = Charge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 50, 300);

        return [
            'contract_id'           => Contract::factory(),
            'invoice_id'            => Invoice::factory(),
            'charge_type'           => ChargeType::Rent,
            'net_amount'            => $amount,
            'amount'                => $amount,
            'tax_amount'            => 0,
            'due_date'              => fake()->dateTimeBetween('-6 months', '+1 month')->format('Y-m-d'),
            'description'           => null,
            'reversal_of_charge_id' => null,
        ];
    }
}
