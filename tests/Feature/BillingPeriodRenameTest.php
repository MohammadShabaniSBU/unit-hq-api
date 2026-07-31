<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingPeriod;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingPeriodRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_grouping_is_billing_periods_alongside_fiscal_invoices(): void
    {
        Employee::factory()->manager()->create();

        // S01 renamed display grouping; S03 reclaimed `invoices` for fiscal documents.
        $this->assertTrue(Schema::hasTable('billing_periods'));
        $this->assertTrue(Schema::hasTable('invoices'));
        $this->assertTrue(Schema::hasColumn('charges', 'billing_period_id'));
        $this->assertTrue(Schema::hasColumn('charges', 'invoice_id'));

        $this->getJson('/api/invoices')->assertOk();

        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id, 'currency' => 'EUR']);

        BillingPeriod::query()->create([
            'contract_id' => $contract->id,
            'billing_period_start' => now()->startOfMonth()->toDateString(),
            'billing_period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $response = $this->getJson("/api/contacts/{$contact->id}/transactions");

        $response->assertOk();
        $this->assertArrayHasKey('billing_periods', $response->json('data'));
        $this->assertArrayNotHasKey('invoices', $response->json('data'));
        $this->assertSame('EUR', $response->json('data.billing_periods.0.currency'));
    }
}
