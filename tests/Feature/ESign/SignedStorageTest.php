<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\EsignEnvelope;
use App\Support\ESign\FakeESignProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\ESign\CreatesEnvelopeFixtures;
use Tests\TestCase;

class SignedStorageTest extends TestCase
{
    use CreatesEnvelopeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvelopeWorld();
    }

    public function test_immutable_streamed_retained(): void
    {
        [$contract, $document] = $this->prepareAwaitingWithDocument();
        $sent = $this->sendEnvelope($contract, $document->id);
        $envelope = EsignEnvelope::query()->findOrFail($sent['id']);

        $this->fireWebhook('signed', $envelope->provider_envelope_ref);
        $envelope->refresh();

        $this->assertNotNull($envelope->signed_pdf_path);
        $this->assertNotNull($envelope->signed_pdf_sha256);
        $this->assertNotNull($envelope->certificate_path);
        $this->assertSame(
            FakeESignProvider::STUB_PDF,
            Storage::disk('local')->get($envelope->signed_pdf_path),
        );

        try {
            $envelope->update(['signed_pdf_sha256' => str_repeat('0', 64)]);
            $this->fail('Expected RuntimeException for immutable signed_pdf_sha256');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $envelope->refresh();
        $this->assertSame(
            hash('sha256', FakeESignProvider::STUB_PDF),
            $envelope->signed_pdf_sha256,
        );

        $this->get("/api/contracts/{$contract->id}/envelopes/{$envelope->id}/signed-pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get("/api/contracts/{$contract->id}/envelopes/{$envelope->id}/certificate")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $pdfPath = $envelope->signed_pdf_path;
        $certPath = $envelope->certificate_path;

        Artisan::call('contacts:redact', ['contact' => $contract->contact_id]);

        $this->assertTrue(Storage::disk('local')->exists($pdfPath));
        $this->assertTrue(Storage::disk('local')->exists($certPath));
        $envelope->refresh();
        $this->assertSame($pdfPath, $envelope->signed_pdf_path);
        $this->assertSame($certPath, $envelope->certificate_path);

        $config = (string) file_get_contents(config_path('redaction.php'));
        $this->assertStringContainsString('E-sign signed PDFs', $config);
        $this->assertStringContainsString('Legal-retention', $config);
        $this->assertStringContainsString('GDPR export', $config);
    }
}
