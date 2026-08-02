<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Jobs\ProcessEsignWebhookEvent;
use App\Support\ESign\EnvelopeSpec;
use App\Support\ESign\ESignEvent;
use App\Support\ESign\ESignProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ESign\FakeSecondProvider;
use Tests\TestCase;

class AdapterSeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_second_provider(): void
    {
        // Core orchestration paths must not name concrete adapters.
        $this->assertStringNotContainsString(
            'Signable',
            (string) file_get_contents(app_path('Jobs/ProcessEsignWebhookEvent.php')),
        );
        $this->assertStringNotContainsString(
            'FakeSecond',
            (string) file_get_contents(app_path('Jobs/ProcessEsignWebhookEvent.php')),
        );
        $this->assertStringNotContainsString(
            'Signable',
            (string) file_get_contents(app_path('Http/Controllers/Webhooks/EsignWebhookController.php')),
        );
        $this->assertStringNotContainsString(
            'FakeSecond',
            (string) file_get_contents(app_path('Http/Controllers/Webhooks/EsignWebhookController.php')),
        );

        $registry = app(ESignProviderRegistry::class);
        $registry->register(FakeSecondProvider::PROVIDER_KEY, FakeSecondProvider::class);

        $this->assertTrue($registry->supports(FakeSecondProvider::PROVIDER_KEY));

        $adapter = $registry->make(FakeSecondProvider::PROVIDER_KEY, [
            'api_key' => 'fs_test_key',
        ]);

        $adapter->verify();
        $this->assertSame(FakeSecondProvider::ANCHOR_TOKEN, $adapter->signatureAnchor());

        $ref = $adapter->createEnvelope(new EnvelopeSpec(
            pdfBytes: '%PDF-1.4 second',
            title: 'Second adapter stub',
            signer: ['name' => 'Grace Hopper', 'email' => 'grace@example.com'],
        ));

        $parsed = $adapter->parseWebhook([
            'event_id' => 'fs_evt_1',
            'type' => 'signed',
            'envelope_ref' => $ref->providerRef,
        ]);

        $this->assertSame(ESignEvent::TYPE_SIGNED, $parsed->type);
        $this->assertSame($ref->providerRef, $parsed->envelopeRef);

        $signed = $adapter->downloadSigned($ref->providerRef);
        $this->assertSame(FakeSecondProvider::STUB_PDF, $signed->pdfBytes);

        $adapter->cancelEnvelope($ref->providerRef);

        // Job class itself has no provider-specific branching (already grepped).
        $this->assertTrue(class_exists(ProcessEsignWebhookEvent::class));
    }
}
