<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-12 months', '-1 month');

        return [
            'contract_id'          => Contract::factory(),
            'billing_period_start' => $periodStart->format('Y-m-d'),
            'billing_period_end'   => (clone $periodStart)->modify('+1 month -1 day')->format('Y-m-d'),
            'status'               => 'issued',
            'issued_at'            => $periodStart,
        ];
    }
}
