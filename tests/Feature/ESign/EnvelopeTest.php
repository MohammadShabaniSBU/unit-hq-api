<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Enums\ContractDocumentStatus;
use App\Enums\ContractStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Models\EsignEnvelope;
use App\Support\ESign\FakeESignProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ESign\CreatesEnvelopeFixtures;
use Tests\TestCase;

class EnvelopeTest extends TestCase
{
    use CreatesEnvelopeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvelopeWorld();
    }

    public function test_download_failure_pending_sweep(): void
    {
        [$contract, $document] = $this->prepareAwaitingWithDocument();
        $sent = $this->sendEnvelope($contract, $document->id);
        $envelope = EsignEnvelope::query()->findOrFail($sent['id']);

        FakeESignProvider::failNextDownload();
        $this->fireWebhook('signed', $envelope->provider_envelope_ref);

        $envelope->refresh();
        $contract->refresh();

        $this->assertSame(EsignEnvelopeStatus::Signed, $envelope->status);
        $this->assertTrue($envelope->completion_pending);
        $this->assertNull($envelope->signed_pdf_path);
        $this->assertSame(ContractStatus::AwaitingSignature, $contract->status);

        Artisan::call('esign:sweep-completion-pending');

        $envelope->refresh();
        $contract->refresh();

        $this->assertFalse($envelope->completion_pending);
        $this->assertNotNull($envelope->signed_pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($envelope->signed_pdf_path));
        $this->assertContains($contract->status, [
            ContractStatus::Pending,
            ContractStatus::Active,
        ]);
    }

    public function test_resend_supersede_document_choice(): void
    {
        [$contract, $document] = $this->prepareAwaitingWithDocument();
        $sent = $this->sendEnvelope($contract, $document->id);
        $first = EsignEnvelope::query()->findOrFail($sent['id']);
        $prefix = substr((string) $document->sha256, 0, 8);

        $this->assertSame($prefix, $sent['document_sha256_prefix']);

        // Resend against the same frozen document.
        $resendSame = $this->postJson(
            "/api/contracts/{$contract->id}/envelopes/{$first->id}/resend"
        );
        $resendSame->assertCreated();
        $this->assertSame($prefix, $resendSame->json('data.document_sha256_prefix'));
        $this->assertSame($document->id, $resendSame->json('data.contract_document_id'));

        $first->refresh();
        $this->assertSame(EsignEnvelopeStatus::Cancelled, $first->status);

        $secondId = (int) $resendSame->json('data.id');
        $this->assertNotSame($first->id, $secondId);

        // Only one live envelope.
        $liveCount = EsignEnvelope::query()
            ->where('contract_id', $contract->id)
            ->whereIn('status', ['sent', 'viewed'])
            ->count();
        $this->assertSame(1, $liveCount);

        // Regenerate a new draft and resend with document choice.
        $second = EsignEnvelope::query()->findOrFail($secondId);
        $this->postJson("/api/contracts/{$contract->id}/envelopes/{$second->id}/cancel")
            ->assertOk();

        // Document is still sent/frozen — generate a new draft via store.
        $newDoc = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $newDoc->assertCreated();
        $newDocId = (int) $newDoc->json('data.id');
        $newPrefix = (string) $newDoc->json('data.sha256_prefix');
        $this->assertSame(ContractDocumentStatus::Draft, ContractDocumentStatus::from(
            (string) $newDoc->json('data.status')
        ));

        $sentNew = $this->sendEnvelope($contract, $newDocId);
        $this->assertSame($newDocId, $sentNew['contract_document_id']);
        $this->assertSame($newPrefix, $sentNew['document_sha256_prefix']);
        $this->assertNotSame($prefix, $newPrefix);
    }
}
