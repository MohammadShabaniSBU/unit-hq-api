<?php

declare(strict_types=1);

namespace Tests\Support\ESign;

use App\Enums\CredentialStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\EsignProviderAccount;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\ESign\FakeESignProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Documents\CreatesContractDocumentFixtures;

trait CreatesEnvelopeFixtures
{
    use CreatesContractDocumentFixtures;

    protected EsignProviderAccount $esignAccount;

    protected string $webhookToken;

    protected function seedEnvelopeWorld(): void
    {
        Storage::fake('local');
        FakeESignProvider::reset();

        $registry = app(ESignProviderRegistry::class);
        $registry->register('signable', FakeESignProvider::class);

        $this->seedDocumentWorld();

        $this->webhookToken = Str::random(40);
        $this->esignAccount = EsignProviderAccount::query()->create([
            'provider' => EsignProvider::Signable,
            'display_name' => 'Signable Fake',
            'credentials' => ['api_key' => 'fake_key_test'],
            'webhook_token' => $this->webhookToken,
            'webhook_state' => EsignWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);
    }

    protected function contactWithEmail(?string $email = 'signer@example.com'): Contact
    {
        $contact = Contact::factory()->fiscalComplete()->create([
            'locale' => 'en',
            'billing_name' => 'Ada Lovelace',
            'email' => $email,
        ]);

        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Email,
            'value' => $email,
            'is_primary' => true,
        ]);

        return $contact;
    }

    protected function prepareAwaitingWithDocument(?Contact $contact = null): array
    {
        $contact ??= $this->contactWithEmail();
        $contract = $this->createRemoteContract($contact);

        $create = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $create->assertCreated();

        $document = ContractDocument::query()->findOrFail((int) $create->json('data.id'));

        return [$contract, $document, $contact];
    }

    protected function sendEnvelope(Contract $contract, ?int $documentId = null): array
    {
        $payload = [];
        if ($documentId !== null) {
            $payload['contract_document_id'] = $documentId;
        }

        $response = $this->postJson("/api/contracts/{$contract->id}/envelopes", $payload);
        $response->assertCreated();

        return $response->json('data');
    }

    protected function fireWebhook(string $type, string $envelopeRef, array $extra = []): void
    {
        $payload = array_merge([
            'event_id' => 'evt_'.Str::random(12),
            'type' => $type,
            'envelope_ref' => $envelopeRef,
        ], $extra);

        // QUEUE_CONNECTION=sync in phpunit — job runs inline.
        $this->postJson('/api/webhooks/esign/'.$this->webhookToken, $payload)
            ->assertOk();
    }
}
