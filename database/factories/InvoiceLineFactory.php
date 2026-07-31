<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'charge_id' => Charge::factory(),
            'description' => 'Rent',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'net_amount' => '100.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '21.00',
            'gross_amount' => '121.00',
        ];
    }
}
