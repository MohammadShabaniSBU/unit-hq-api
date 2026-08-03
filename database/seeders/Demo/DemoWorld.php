<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\AccessProviderAccount;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\EsignProviderAccount;
use App\Models\PaymentProviderAccount;
use App\Models\Site;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\FakeAccessProvider;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\ESign\FakeESignProvider;
use Database\Seeders\Demo\Injectors\AccessInjector;
use Database\Seeders\Demo\Injectors\AircallInjector;
use Database\Seeders\Demo\Injectors\DeliveryInjector;
use Database\Seeders\Demo\Injectors\ESignInjector;
use Database\Seeders\Demo\Injectors\InboundInjector;
use Database\Seeders\Demo\Injectors\StripeInjector;
use InvalidArgumentException;
use RuntimeException;

/**
 * Handle registry for demo scripts — journeys address actors by string handle,
 * not raw IDs (`$world->contact('marcus')`).
 */
final class DemoWorld
{
    private static ?self $current = null;

    /** @var array<string, mixed> */
    private array $registry = [];

    private StripeInjector $stripe;

    private DeliveryInjector $delivery;

    private InboundInjector $inbound;

    private ESignInjector $esign;

    private AccessInjector $access;

    private AircallInjector $aircall;

    public function __construct()
    {
        $this->bootFakeProviders();
        $this->stripe = new StripeInjector($this);
        $this->delivery = new DeliveryInjector($this);
        $this->inbound = new InboundInjector($this);
        $this->esign = new ESignInjector($this);
        $this->access = new AccessInjector($this);
        $this->aircall = new AircallInjector($this);
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(?self $world): void
    {
        self::$current = $world;
    }

    public function remember(string $handle, mixed $value): mixed
    {
        $this->registry[$handle] = $value;

        return $value;
    }

    public function get(string $handle): mixed
    {
        if (! array_key_exists($handle, $this->registry)) {
            throw new InvalidArgumentException("Unknown demo handle: {$handle}");
        }

        return $this->registry[$handle];
    }

    public function has(string $handle): bool
    {
        return array_key_exists($handle, $this->registry);
    }

    public function contact(string $handle): Contact
    {
        $value = $this->get($handle);

        if (! $value instanceof Contact) {
            throw new RuntimeException("Handle '{$handle}' is not a Contact.");
        }

        return $value;
    }

    public function site(string $handle): Site
    {
        $key = str_starts_with($handle, 'site.') ? $handle : 'site.'.$handle;
        $value = $this->get($key);

        if (! $value instanceof Site) {
            throw new RuntimeException("Handle '{$key}' is not a Site.");
        }

        return $value;
    }

    public function stripe(): StripeInjector
    {
        return $this->stripe;
    }

    public function delivery(): DeliveryInjector
    {
        return $this->delivery;
    }

    public function inbound(): InboundInjector
    {
        return $this->inbound;
    }

    public function esign(): ESignInjector
    {
        return $this->esign;
    }

    public function access(): AccessInjector
    {
        return $this->access;
    }

    public function aircall(): AircallInjector
    {
        return $this->aircall;
    }

    public function stripeAccount(): PaymentProviderAccount
    {
        if ($this->has('account.stripe')) {
            $account = $this->get('account.stripe');
            if ($account instanceof PaymentProviderAccount) {
                return $account;
            }
        }

        $account = PaymentProviderAccount::query()
            ->where('provider', 'stripe')
            ->where('is_active', true)
            ->firstOrFail();

        return $this->remember('account.stripe', $account);
    }

    public function emailAccount(): CommunicationAccount
    {
        return $this->resolveCommunicationAccount(
            'account.email',
            Provider::Brevo,
            Channel::Email,
        );
    }

    public function postmarkAccount(): CommunicationAccount
    {
        return $this->resolveCommunicationAccount(
            'account.postmark',
            Provider::Postmark,
            Channel::Email,
        );
    }

    public function smsAccount(): CommunicationAccount
    {
        return $this->resolveCommunicationAccount(
            'account.sms',
            Provider::Twilio,
            Channel::Sms,
        );
    }

    public function whatsappAccount(): CommunicationAccount
    {
        return $this->resolveCommunicationAccount(
            'account.whatsapp',
            Provider::Sinch,
            Channel::Whatsapp,
        );
    }

    public function aircallAccount(): CommunicationAccount
    {
        return $this->resolveCommunicationAccount(
            'account.aircall',
            Provider::Aircall,
            Channel::Call,
        );
    }

    public function esignAccount(): EsignProviderAccount
    {
        if ($this->has('account.esign')) {
            $account = $this->get('account.esign');
            if ($account instanceof EsignProviderAccount) {
                return $account;
            }
        }

        $account = EsignProviderAccount::query()
            ->where('is_active', true)
            ->firstOrFail();

        return $this->remember('account.esign', $account);
    }

    public function accessAccount(): AccessProviderAccount
    {
        if ($this->has('account.access')) {
            $account = $this->get('account.access');
            if ($account instanceof AccessProviderAccount) {
                return $account;
            }
        }

        $account = AccessProviderAccount::query()
            ->where('is_active', true)
            ->firstOrFail();

        return $this->remember('account.access', $account);
    }

    /**
     * Hydrate site/provider handles from an already-seeded stage DB.
     */
    public function hydrateFromDatabase(): void
    {
        $handles = [
            'MAD-01' => 'madrid',
            'BCN-01' => 'barcelona',
            'VLC-01' => 'valencia',
            'LON-01' => 'london',
            'PAR-01' => 'paris',
        ];

        foreach ($handles as $code => $handle) {
            $site = Site::query()->where('code', $code)->first();
            if ($site !== null) {
                $this->remember('site.'.$handle, $site);
                $this->remember('site.'.strtolower($code), $site);
            }
        }

        $this->stripeAccount();
        $this->emailAccount();
        $this->postmarkAccount();
        $this->smsAccount();
        $this->whatsappAccount();
        $this->aircallAccount();
        $this->esignAccount();
        $this->accessAccount();
    }

    /**
     * Handles marked steady via JourneySupport::markSteadyPayer.
     *
     * @return list<string>
     */
    public function steadyPayerHandles(): array
    {
        $handles = [];
        foreach ($this->payerEntries() as $entry) {
            if ($entry['mode'] === 'steady') {
                $handles[] = $entry['handle'];
            }
        }

        return $handles;
    }

    /**
     * Payer standing-order registry: steady | missing | late:{n}.
     *
     * @return list<array{handle: string, mode: string}>
     */
    public function payerEntries(): array
    {
        $entries = [];
        foreach ($this->registry as $key => $value) {
            if (! str_ends_with($key, '.payer') || ! is_string($value)) {
                continue;
            }
            $entries[] = [
                'handle' => substr($key, 0, -strlen('.payer')),
                'mode' => $value,
            ];
        }

        return $entries;
    }

    public function hasMissingPayers(): bool
    {
        foreach ($this->payerEntries() as $entry) {
            if ($entry['mode'] === 'missing' && $this->has($entry['handle'].'.contract')) {
                return true;
            }
        }

        return false;
    }

    private function bootFakeProviders(): void
    {
        FakeESignProvider::reset();
        FakeAccessProvider::reset();

        app(ESignProviderRegistry::class)->register('signable', FakeESignProvider::class);
        app(AccessProviderRegistry::class)->register('sensorberg', FakeAccessProvider::class);
        app(AccessProviderRegistry::class)->set(FakeAccessProvider::make(['api_key' => 'fake_key_demo']));
    }

    private function resolveCommunicationAccount(
        string $handle,
        Provider $provider,
        Channel $channel,
    ): CommunicationAccount {
        if ($this->has($handle)) {
            $account = $this->get($handle);
            if ($account instanceof CommunicationAccount) {
                return $account;
            }
        }

        $account = CommunicationAccount::query()
            ->where('provider', $provider)
            ->where('channel', $channel)
            ->orderByDesc('is_active')
            ->firstOrFail();

        return $this->remember($handle, $account);
    }
}
