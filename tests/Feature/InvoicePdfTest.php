<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Fiscal\InvoiceRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    public function test_renders_from_snapshot_arrays_only(): void
    {
        $invoice = Invoice::factory()->create([
            'kind' => InvoiceKind::Ordinary,
            'status' => InvoiceStatus::Issued,
            'full_number' => 'F2026-000001',
            'issue_date' => '2026-07-15',
            'issuer_name' => 'Acme Storage SL',
            'issuer_tax_id' => 'B12345678',
            'issuer_address' => [
                'line1' => 'Calle Emisor 1',
                'line2' => null,
                'city' => 'Madrid',
                'postal' => '28001',
                'country' => 'ES',
            ],
            'buyer_name' => 'Jane Tenant',
            'buyer_tax_id' => '12345678Z',
            'buyer_address' => [
                'line1' => 'Calle Buyer 2',
                'line2' => null,
                'city' => 'Madrid',
                'postal' => '28002',
                'country' => 'ES',
            ],
            'currency' => 'EUR',
            'net_total' => '100.00',
            'tax_total' => '21.00',
            'gross_total' => '121.00',
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Rent AL6-06 01–31 Jul',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'net_amount' => '100.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '21.00',
            'gross_amount' => '121.00',
        ]);

        $payload = InvoiceRenderer::payloadFromInvoice($invoice->fresh('lines'));

        $this->assertIsArray($payload);
        $this->assertIsArray($payload['issuer']);
        $this->assertIsArray($payload['lines']);
        $this->assertTrue($payload['qr_placeholder']);

        $html = InvoiceRenderer::html($payload);

        $this->assertStringContainsString('F2026-000001', $html);
        $this->assertStringContainsString('Acme Storage SL', $html);
        $this->assertStringContainsString('Jane Tenant', $html);
        $this->assertStringContainsString('Rent AL6-06', $html);
        $this->assertStringContainsString('Verifactu', $html);
        $this->assertStringNotContainsString('App\\Models\\', $html);

        $pdf = InvoiceRenderer::pdf($payload);
        $this->assertNotSame('', $pdf);
        $this->assertStringStartsWith('%PDF', $pdf);

        $this->get("/api/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_simplified_template_renders(): void
    {
        $invoice = Invoice::factory()->create([
            'kind' => InvoiceKind::Simplified,
            'buyer_name' => 'Walk-in',
            'buyer_tax_id' => null,
            'buyer_address' => null,
            'issuer_address' => [
                'line1' => 'Calle Emisor 1',
                'city' => 'Madrid',
                'postal' => '28001',
                'country' => 'ES',
            ],
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Rent',
            'net_amount' => '50.00',
            'tax_rate_snapshot' => '0.00',
            'tax_amount' => '0.00',
            'gross_amount' => '50.00',
        ]);

        $html = InvoiceRenderer::html(InvoiceRenderer::payloadFromInvoice($invoice->fresh('lines')));
        $this->assertStringContainsString('simplificada', strtolower($html));
        $this->assertStringContainsString('Walk-in', $html);
    }
}
