<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Support\Communications\Contracts\AutoRegistersWebhooks;
use App\Support\Communications\Contracts\ProviderAccount;
use App\Support\Communications\Contracts\ReportsDeliveryEvents;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Contracts\SendsSms;
use App\Support\Communications\Contracts\SendsWhatsApp;
use App\Support\Communications\Providers\AircallAdapter;
use App\Support\Communications\Providers\BrevoAdapter;
use App\Support\Communications\Providers\PostmarkAdapter;
use App\Support\Communications\Providers\SinchAdapter;
use App\Support\Communications\Providers\SinchWhatsAppAdapter;
use App\Support\Communications\Providers\TwilioSmsAdapter;
use InvalidArgumentException;

/**
 * Single source of truth mapping (channel, provider) → adapter class.
 * Adding a provider = one registry entry + one adapter.
 */
final class ProviderRegistry
{
    /**
     * @var array<string, class-string<ProviderAccount>>
     */
    private array $map;

    public function __construct()
    {
        $this->map = [
            $this->key(Channel::Email, Provider::Brevo) => BrevoAdapter::class,
            $this->key(Channel::Email, Provider::Postmark) => PostmarkAdapter::class,
            $this->key(Channel::Sms, Provider::Twilio) => TwilioSmsAdapter::class,
            $this->key(Channel::Sms, Provider::Sinch) => SinchAdapter::class,
            $this->key(Channel::Whatsapp, Provider::Sinch) => SinchWhatsAppAdapter::class,
            $this->key(Channel::Call, Provider::Aircall) => AircallAdapter::class,
            // Mandrill adapter lands when needed:
            // $this->key(Channel::Email, Provider::Mandrill) => MandrillAdapter::class,
        ];
    }

    /**
     * @return class-string<ProviderAccount>
     */
    public function adapterClass(Channel $channel, Provider $provider): string
    {
        $key = $this->key($channel, $provider);

        if (! isset($this->map[$key])) {
            throw new InvalidArgumentException(
                "No adapter registered for {$channel->value}/{$provider->value}."
            );
        }

        return $this->map[$key];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function make(Channel $channel, Provider $provider, array $credentials): ProviderAccount
    {
        $class = $this->adapterClass($channel, $provider);

        return $class::make($credentials);
    }

    public function supports(Channel $channel, Provider $provider): bool
    {
        return isset($this->map[$this->key($channel, $provider)]);
    }

    /** @return list<Provider> */
    public function providersFor(Channel $channel): array
    {
        $providers = [];

        foreach ($this->map as $key => $class) {
            [$channelValue] = explode(':', $key, 2);

            if ($channelValue === $channel->value) {
                $providers[] = Provider::from(explode(':', $key, 2)[1]);
            }
        }

        return $providers;
    }

    /**
     * Shape consumed by the settings form: provider options + credentialFields
     * + interface-derived capability flags (no capabilities() boolean map).
     *
     * @return list<array{
     *     provider: string,
     *     label: string,
     *     credential_fields: array<string, array{label: string, secret: bool}>,
     *     sends_email: bool,
     *     sends_sms: bool,
     *     sends_whatsapp: bool,
     *     auto_registers_webhooks: bool,
     *     reports_delivery_events: bool
     * }>
     */
    public function optionsFor(Channel $channel): array
    {
        $options = [];

        foreach ($this->providersFor($channel) as $provider) {
            $class = $this->adapterClass($channel, $provider);
            $adapter = $class::make([]);

            $options[] = [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'credential_fields' => $adapter->credentialFields(),
                'sends_email' => is_subclass_of($class, SendsEmail::class),
                'sends_sms' => is_subclass_of($class, SendsSms::class),
                'sends_whatsapp' => is_subclass_of($class, SendsWhatsApp::class),
                'auto_registers_webhooks' => is_subclass_of($class, AutoRegistersWebhooks::class),
                'reports_delivery_events' => is_subclass_of($class, ReportsDeliveryEvents::class),
            ];
        }

        return $options;
    }

    private function key(Channel $channel, Provider $provider): string
    {
        return $channel->value.':'.$provider->value;
    }
}
