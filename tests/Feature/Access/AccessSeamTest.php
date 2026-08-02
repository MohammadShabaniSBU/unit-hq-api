<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessCredentialMode;
use App\Jobs\ProcessAccessWebhookEvent;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\AccessWebhookPayload;
use App\Support\Access\GrantSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Access\FakeSecondProvider;
use Tests\TestCase;

class AccessSeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_second_provider(): void
    {
        $this->assertStringNotContainsString(
            'Sensorberg',
            (string) file_get_contents(app_path('Jobs/ProcessAccessWebhookEvent.php')),
        );
        $this->assertStringNotContainsString(
            'FakeSecond',
            (string) file_get_contents(app_path('Jobs/ProcessAccessWebhookEvent.php')),
        );
        $this->assertStringNotContainsString(
            'Sensorberg',
            (string) file_get_contents(app_path('Http/Controllers/Webhooks/AccessWebhookController.php')),
        );
        $this->assertStringNotContainsString(
            'FakeSecond',
            (string) file_get_contents(app_path('Http/Controllers/Webhooks/AccessWebhookController.php')),
        );

        $registry = app(AccessProviderRegistry::class);
        $registry->register(FakeSecondProvider::PROVIDER_KEY, FakeSecondProvider::class);

        $this->assertTrue($registry->supports(FakeSecondProvider::PROVIDER_KEY));

        $adapter = $registry->make(FakeSecondProvider::PROVIDER_KEY, [
            'api_key' => 'fs_test_key',
        ]);

        $adapter->verify();
        $this->assertSame([AccessCredentialMode::Pin->value], $adapter->credentialModes());

        $points = $adapter->listPoints();
        $this->assertCount(1, $points);

        $ref = $adapter->grant(new GrantSpec(
            providerPointId: 'fs-point-1',
            person: ['name' => 'Grace Hopper', 'email' => 'grace@example.com'],
            mode: AccessCredentialMode::Pin->value,
        ));

        $this->assertSame('654321', $ref->pin);
        $this->assertCount(1, $adapter->listGrants());

        $parsed = $adapter->parseWebhook([
            'event_id' => 'fs_evt_1',
            'type' => 'granted',
            'provider_point_id' => 'fs-point-1',
            'grant_ref' => $ref->providerGrantId,
        ]);

        $this->assertTrue($parsed->isKnown());
        $this->assertSame(AccessWebhookPayload::class, $parsed::class);

        $adapter->revoke($ref->providerGrantId);
        $this->assertCount(0, $adapter->listGrants());

        $this->assertTrue(class_exists(ProcessAccessWebhookEvent::class));
    }
}
