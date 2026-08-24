<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Support\Documents\ContractDocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Documents\CreatesContractDocumentFixtures;
use Tests\Support\Documents\PdfText;
use Tests\TestCase;

class DocRendererTest extends TestCase
{
    use CreatesContractDocumentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDocumentWorld();
    }

    public function test_text_and_pagecount_goldens(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'locale' => 'es',
            'billing_name' => 'Ada Lovelace',
        ]);
        $contract = $this->createRemoteContract($contact);

        foreach (['es', 'en'] as $locale) {
            $variant = $this->variant($locale);
            $rendered = ContractDocumentRenderer::render($contract, $variant);

            $this->assertStringStartsWith('%PDF', $rendered['pdf_bytes']);
            $this->assertSame(2, PdfText::pageCount($rendered['pdf_bytes']), "locale {$locale} page count");

            $htmlText = PdfText::normalizeHtml($rendered['html']);
            $pdfText = PdfText::extract($rendered['pdf_bytes']);
            $combined = $htmlText."\n".$pdfText;

            PdfText::assertContainsAll($combined, [
                'Acme Storage SL',
                'B12345678',
                'A-101',
                '125.50',
                '200.00',
                '01/08/2026',
                '{{signature}}',
            ]);

            if ($locale === 'es') {
                PdfText::assertContainsAll($combined, [
                    'Contrato de alquiler de trastero',
                    'Obligaciones del inquilino',
                ]);
            } else {
                PdfText::assertContainsAll($combined, [
                    'Self-storage rental agreement',
                    'Tenant obligations',
                ]);
            }

            $goldenPath = base_path("tests/Fixtures/Documents/golden-{$locale}.txt");
            $normalized = PdfText::normalizeHtml($rendered['html']);
            if (! is_file($goldenPath)) {
                file_put_contents($goldenPath, $normalized);
            }
            $this->assertSame(
                file_get_contents($goldenPath),
                $normalized,
                "HTML text golden mismatch for {$locale}",
            );
        }
    }
}
