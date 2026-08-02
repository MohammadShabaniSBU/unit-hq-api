<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Unit;
use App\Support\Documents\ContractDocumentRenderer;
use App\Support\ESign\FakeESignProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Documents\CreatesContractDocumentFixtures;
use Tests\Support\Documents\PdfText;
use Tests\TestCase;

class SmartBlockTest extends TestCase
{
    use CreatesContractDocumentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDocumentWorld();
    }

    public function test_parties_terms_anchor(): void
    {
        $complete = Contact::factory()->fiscalComplete()->create([
            'first_name' => 'Jane',
            'last_name' => 'Tenant',
            'billing_name' => 'Jane Tenant SL',
            'locale' => 'en',
        ]);
        $incomplete = Contact::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'locale' => 'en',
            'tax_id' => null,
        ]);

        $contractComplete = $this->createRemoteContract($complete, '125.50');
        $secondUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
            'unit_number' => 'B-202',
        ]);
        $contractIncomplete = $this->createRemoteContractOnUnit($incomplete, $secondUnit, '99.00');

        $variant = $this->variant('en');

        $payloadComplete = ContractDocumentRenderer::payload($contractComplete, $variant);
        $parties = $this->firstBlock($payloadComplete, 'parties');
        $this->assertFalse($parties['tenant']['incomplete']);
        $this->assertSame('Jane Tenant SL', $parties['tenant']['name']);
        $this->assertSame('12345678Z', $parties['tenant']['tax_id']);
        $this->assertSame('Acme Storage SL', $parties['issuer']['name']);
        $this->assertSame('B12345678', $parties['issuer']['tax_id']);

        $terms = $this->firstBlock($payloadComplete, 'terms_table')['terms'];
        $this->assertSame('125.50', $terms['items'][0]['amount']);
        $this->assertSame('A-101', $terms['items'][0]['label']);
        $this->assertSame('200.00', $terms['deposit']);
        $this->assertSame('2026-08-01', $terms['move_in']);

        $itemsOn = $contractComplete->itemsOn($contractComplete->move_in_date);
        $expectedAmount = $itemsOn->first()?->base_rate !== null
            ? (string) $itemsOn->first()->base_rate
            : (string) $itemsOn->first()?->price?->amount;
        $this->assertSame($expectedAmount, $terms['items'][0]['amount']);

        $anchor = $this->firstBlock($payloadComplete, 'signature_anchor');
        $this->assertSame(FakeESignProvider::ANCHOR_TOKEN, $anchor['token']);

        $payloadIncomplete = ContractDocumentRenderer::payload($contractIncomplete, $variant);
        $tenant = $this->firstBlock($payloadIncomplete, 'parties')['tenant'];
        $this->assertTrue($tenant['incomplete']);
        $this->assertSame('John Doe', $tenant['name']);

        $html = ContractDocumentRenderer::html($payloadComplete);
        PdfText::assertContainsAll(PdfText::normalizeHtml($html), [
            FakeESignProvider::ANCHOR_TOKEN,
            '125.50',
            'Jane Tenant SL',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function firstBlock(array $payload, string $type): array
    {
        foreach ($payload['blocks'] as $block) {
            if (($block['type'] ?? null) === $type) {
                return $block;
            }
        }

        $this->fail("Missing block type {$type}");
    }

    private function createRemoteContractOnUnit(Contact $contact, Unit $unit, string $amount): Contract
    {
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-08-01',
            'move_in_date' => '2026-08-01',
            'deposit_amount' => '50.00',
            'signature_mode' => 'remote',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => $amount,
            ]],
        ]);
        $response->assertCreated();

        return Contract::query()->findOrFail((int) $response->json('data.id'));
    }
}
