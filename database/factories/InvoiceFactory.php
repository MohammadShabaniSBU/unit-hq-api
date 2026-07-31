<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceSeriesKind;
use App\Enums\InvoiceStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
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
        $entity = LegalEntity::factory()->create();
        $series = InvoiceSeries::query()
            ->where('legal_entity_id', $entity->id)
            ->where('kind', InvoiceSeriesKind::Ordinary)
            ->where('is_default', true)
            ->firstOrFail();
        $number = 1;

        return [
            'legal_entity_id' => $entity->id,
            'invoice_series_id' => $series->id,
            'number' => $number,
            'full_number' => sprintf('%s-%06d', $series->code, $number),
            'kind' => InvoiceKind::Ordinary,
            'status' => InvoiceStatus::Issued,
            'issue_date' => now()->toDateString(),
            'contract_id' => Contract::factory(),
            'contact_id' => Contact::factory(),
            'rectifies_invoice_id' => null,
            'rectification_reason' => null,
            'issuer_name' => $entity->legal_name,
            'issuer_tax_id' => $entity->tax_id,
            'issuer_address' => [
                'line1' => $entity->address_line1,
                'line2' => $entity->address_line2,
                'city' => $entity->city,
                'postal' => $entity->postal_code,
                'country' => $entity->country_code,
            ],
            'buyer_name' => fake()->name(),
            'buyer_tax_id' => null,
            'buyer_address' => null,
            'currency' => 'EUR',
            'net_total' => '100.00',
            'tax_total' => '21.00',
            'gross_total' => '121.00',
            'created_by' => null,
        ];
    }
}
