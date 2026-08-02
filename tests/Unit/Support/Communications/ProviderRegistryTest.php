<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderRegistry;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_options_for_email_includes_credential_fields_and_capability_flags(): void
    {
        $options = app(ProviderRegistry::class)->optionsFor(Channel::Email);

        $this->assertNotEmpty($options);

        $brevo = collect($options)->firstWhere('provider', 'brevo');
        $this->assertNotNull($brevo);
        $this->assertArrayHasKey('api_key', $brevo['credential_fields']);
        $this->assertTrue($brevo['sends_email']);
        $this->assertFalse($brevo['sends_sms']);
        $this->assertTrue($brevo['auto_registers_webhooks']);
        $this->assertTrue($brevo['reports_delivery_events']);

        $postmark = collect($options)->firstWhere('provider', 'postmark');
        $this->assertNotNull($postmark);
        $this->assertFalse($postmark['auto_registers_webhooks']);
    }

    public function test_supports_registered_pairs_only(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertTrue($registry->supports(Channel::Email, Provider::Brevo));
        $this->assertTrue($registry->supports(Channel::Sms, Provider::Twilio));
        $this->assertTrue($registry->supports(Channel::Sms, Provider::Sinch));
        $this->assertTrue($registry->supports(Channel::Whatsapp, Provider::Sinch));
        $this->assertTrue($registry->supports(Channel::Call, Provider::Aircall));
        $this->assertFalse($registry->supports(Channel::Sms, Provider::Brevo));
    }
}
