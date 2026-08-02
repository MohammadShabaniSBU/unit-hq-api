<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Enums\ContractDocumentStatus;
use App\Enums\ContractStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\EsignEnvelope;
use App\Models\Interaction;
use App\Models\Invoice;
use App\Models\UnitOccupancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ESign\CreatesEnvelopeFixtures;
use Tests\TestCase;

class EnvelopeFlowTest extends TestCase
{
    use CreatesEnvelopeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvelopeWorld();
    }

    public function test_happy_path_to_completion(): void
    {
        [$contract, $document] = $this->prepareAwaitingWithDocument();

        $sent = $this->sendEnvelope($contract, $document->id);
        $envelope = EsignEnvelope::query()->findOrFail($sent['id']);

        $this->assertSame(EsignEnvelopeStatus::Sent, $envelope->status);
        $this->assertSame(ContractDocumentStatus::Sent, $document->fresh()->status);
        $this->assertSame($envelope->id, $document->fresh()->envelope_id);

        $this->assertTrue(
            Interaction::query()
                ->where('contact_id', $contract->contact_id)
                ->where('channel', 'other')
                ->where('metadata->type', 'esign_envelope')
                ->exists()
        );
        $this->assertNotNull(
            Activity::query()->where('description', 'esign.envelope.sent')->first()
        );

        $this->fireWebhook('viewed', $envelope->provider_envelope_ref);
        $envelope->refresh();
        $this->assertSame(EsignEnvelopeStatus::Viewed, $envelope->status);
        $this->assertNotNull($envelope->viewed_at);
        $this->assertNotNull(
            Activity::query()->where('description', 'esign.envelope.viewed')->first()
        );

        $this->fireWebhook('signed', $envelope->provider_envelope_ref);
        $envelope->refresh();
        $contract->refresh();

        $this->assertSame(EsignEnvelopeStatus::Signed, $envelope->status);
        $this->assertFalse($envelope->completion_pending);
        $this->assertNotNull($envelope->signed_pdf_path);
        $this->assertSame(
            hash('sha256', \App\Support\ESign\FakeESignProvider::STUB_PDF),
            $envelope->signed_pdf_sha256,
        );
        $this->assertTrue(Storage::disk('local')->exists($envelope->signed_pdf_path));
        $this->assertSame(ContractDocumentStatus::Signed, $document->fresh()->status);

        $this->assertContains($contract->status, [
            ContractStatus::Pending,
            ContractStatus::Active,
        ]);
        $this->assertNotNull($contract->signed_at);
        $this->assertGreaterThan(0, Charge::query()->where('contract_id', $contract->id)->count());
        $this->assertGreaterThan(0, Invoice::query()->where('contract_id', $contract->id)->count());
        $this->assertGreaterThan(0, UnitOccupancy::query()->where('contract_id', $contract->id)->count());

        $this->assertNotNull(
            Activity::query()->where('description', 'contract.signed')->first()
        );
        $this->assertNotNull(
            Activity::query()->where('description', 'esign.envelope.signed')->first()
        );
    }

    public function test_missing_email_is_actionable_422(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create([
            'locale' => 'en',
            'billing_name' => 'No Email',
            'email' => null,
        ]);
        $contract = $this->createRemoteContract($contact);

        $this->postJson("/api/contracts/{$contract->id}/documents", ['locale' => 'en'])
            ->assertCreated();

        $this->postJson("/api/contracts/{$contract->id}/envelopes")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact']);
    }
}
