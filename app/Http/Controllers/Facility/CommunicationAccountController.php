<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\CredentialStatus;
use App\Http\Controllers\Controller;
use App\Models\CommunicationAccount;
use App\Models\SiteSenderIdentity;
use App\Models\WhatsappTemplate;
use App\Support\Communications\AccountScope;
use App\Support\Communications\AircallUserDirectory;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\AutoRegistersWebhooks;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderRegistry;
use App\Support\Communications\SenderIdentitySync;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialMasker;
use App\Support\Http\PublicUrlGuard;
use App\Support\Http\PublicUrlUnreachableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Company-level communication credentials, keyed by channel.
 * Site-scoped accounts are modeled but ship no UI yet.
 */
class CommunicationAccountController extends Controller
{
    private const ENTITY = 'communication_account';

    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $accounts = CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->get()
            ->groupBy(fn (CommunicationAccount $account) => $account->channel->value);

        $channels = [];

        foreach (Channel::implemented() as $channel) {
            $channelAccounts = $accounts->get($channel->value, collect());
            $options = $this->registry->optionsFor($channel);
            $active = $channelAccounts->first(fn (CommunicationAccount $a) => $a->is_active);

            $channels[] = [
                'channel' => $channel->value,
                'label' => $channel->label(),
                'active_provider' => $active?->provider->value,
                'accounts' => $channelAccounts->map(
                    fn (CommunicationAccount $account) => $this->serializeAccount($account, $options)
                )->values()->all(),
                'provider_options' => $options,
            ];
        }

        return $this->success($channels, 'Communication settings retrieved successfully.');
    }

    public function update(Request $request, Channel $channel): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if (! $channel->isImplemented()) {
            throw ValidationException::withMessages([
                'channel' => ['This channel is not available yet.'],
            ]);
        }

        $providerValues = array_map(
            static fn (Provider $p) => $p->value,
            $this->registry->providersFor($channel)
        );

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($providerValues)],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        $provider = Provider::from($validated['provider']);
        $adapter = $this->registry->make($channel, $provider, []);
        $fields = $adapter->credentialFields();

        $account = CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', $channel)
            ->where('provider', $provider)
            ->first();

        /** @var array<string, mixed> $existing */
        $existing = $account !== null
            ? (CredentialMasker::readSafely($account, 'credentials') ?? [])
            : [];

        if (! is_array($existing)) {
            $existing = [];
        }

        $submitted = $validated['credentials'] ?? [];
        $merged = CredentialMasker::mergeFields($existing, $submitted, $fields);

        $hasAnyCredential = CredentialMasker::primarySecret($merged, $fields) !== null
            || collect($fields)->contains(fn (array $meta, string $key) => ! empty($merged[$key]));

        if (! $hasAnyCredential) {
            throw ValidationException::withMessages([
                'credentials' => ['Credentials are required to connect this provider.'],
            ]);
        }

        $isRotate = $account !== null && CredentialMasker::primarySecret($existing, $fields) !== null;
        $previousActiveProvider = CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', $channel)
            ->where('is_active', true)
            ->value('provider');

        $verifyAdapter = $this->registry->make($channel, $provider, $merged);
        $verification = $verifyAdapter->verify();

        if ($verification->ok) {
            $status = CredentialStatus::Connected;
            $lastError = null;
        } else {
            $status = CredentialStatus::Error;
            $lastError = $verification->error;
        }

        $activate = (bool) ($validated['activate'] ?? true);

        $account ??= new CommunicationAccount([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => $channel,
            'provider' => $provider,
        ]);

        if ($activate) {
            CommunicationAccount::query()
                ->where('scope', AccountScope::Company)
                ->whereNull('site_id')
                ->where('channel', $channel)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $account->fill([
            'credentials' => $merged,
            'is_active' => $activate,
            'status' => $status,
            'verified_at' => $status === CredentialStatus::Connected ? now() : null,
            'last_error' => $lastError,
        ]);
        $account->save();

        if ($activate && $previousActiveProvider !== null && $previousActiveProvider !== $provider->value) {
            SenderIdentitySync::clearAllSitesForChannel($channel);
        }

        $secret = CredentialMasker::primarySecret($merged, $fields);

        if ($isRotate) {
            CredentialAudit::rotated(self::ENTITY, $account, null, $provider->value, $secret, $status->value, $channel->value);
        } else {
            CredentialAudit::created(self::ENTITY, $account, null, $provider->value, $secret, $status->value, $channel->value);
        }

        $this->syncSenderIdentityAccounts($channel, $account);

        return $this->success(
            $this->channelPayload($channel),
            'Communication account saved successfully.'
        );
    }

    public function createWebhook(Channel $channel): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $account = $this->activeCompanyAccount($channel);

        if ($account === null) {
            throw ValidationException::withMessages([
                'webhook' => ['Connect and activate a provider before creating a webhook.'],
            ]);
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];

        if (! is_array($credentials) || $credentials === []) {
            throw ValidationException::withMessages([
                'webhook' => ['Connect credentials before creating a webhook.'],
            ]);
        }

        $adapter = $this->registry->make($channel, $account->provider, $credentials);

        if (! $adapter instanceof AutoRegistersWebhooks) {
            throw ValidationException::withMessages([
                'webhook' => ['This provider does not support automatic webhook registration. Paste the URL into the vendor dashboard instead.'],
            ]);
        }

        try {
            PublicUrlGuard::assertPublic(Config::get('communications.public_base_url'));
        } catch (PublicUrlUnreachableException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $account->webhook_url_token ??= Str::random(40);
        $url = PublicUrlGuard::webhookUrl(
            "api/webhooks/{$account->provider->value}/{$account->webhook_url_token}"
        );

        try {
            $registration = $adapter->createWebhook($url, $adapter->defaultWebhookEvents());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $account->webhook_endpoint_id = $registration->endpointId;
        $account->webhook_configured_at = now();
        $account->save();

        return $this->success(
            $this->channelPayload($channel),
            'Webhook created successfully.'
        );
    }

    public function deleteWebhook(Channel $channel): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $account = $this->activeCompanyAccount($channel);

        if ($account === null || $account->webhook_endpoint_id === null) {
            return $this->success(
                $this->channelPayload($channel),
                'Webhook already removed.'
            );
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $adapter = $this->registry->make($channel, $account->provider, is_array($credentials) ? $credentials : []);

        if ($adapter instanceof AutoRegistersWebhooks) {
            try {
                $adapter->deleteWebhook($account->webhook_endpoint_id);
            } catch (\Throwable) {
                // Best-effort: still clear local state.
            }
        }

        $account->webhook_endpoint_id = null;
        $account->webhook_configured_at = null;
        $account->save();

        return $this->success(
            $this->channelPayload($channel),
            'Webhook removed successfully.'
        );
    }

    public function destroy(Channel $channel, Provider $provider): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if (! $this->registry->supports($channel, $provider)) {
            return $this->notFound('Provider not supported for this channel.');
        }

        $account = CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', $channel)
            ->where('provider', $provider)
            ->first();

        if ($account === null) {
            return $this->noContent('Communication account already removed.');
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];
        $fields = $this->registry->make($channel, $provider, [])->credentialFields();
        $secret = CredentialMasker::primarySecret($credentials, $fields);

        if ($account->webhook_endpoint_id !== null) {
            $adapter = $this->registry->make($channel, $provider, $credentials);

            if ($adapter instanceof AutoRegistersWebhooks) {
                try {
                    $adapter->deleteWebhook($account->webhook_endpoint_id);
                } catch (\Throwable) {
                    // Best-effort.
                }
            }
        }

        $wasActive = $account->is_active;

        WhatsappTemplate::query()
            ->where('communication_account_id', $account->id)
            ->delete();

        $account->delete();

        if ($wasActive) {
            SenderIdentitySync::clearAllSitesForChannel($channel);
        }

        CredentialAudit::removed(self::ENTITY, null, null, $provider->value, $secret, $channel->value);

        return $this->noContent('Communication account removed successfully.');
    }

    private function activeCompanyAccount(Channel $channel): ?CommunicationAccount
    {
        return CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', $channel)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    private function serializeAccount(CommunicationAccount $account, array $options): array
    {
        $option = collect($options)->firstWhere('provider', $account->provider->value);
        /** @var array<string, array{label: string, secret: bool}> $fields */
        $fields = $option['credential_fields'] ?? $this->registry
            ->make($account->channel, $account->provider, [])
            ->credentialFields();

        $credentials = CredentialMasker::readSafely($account, 'credentials');
        $credentials = is_array($credentials) ? $credentials : null;

        if ($account->webhook_url_token === null && $account->exists) {
            $account->webhook_url_token = Str::random(40);
            $account->save();
        }

        $webhookUrl = $account->webhook_url_token !== null
            ? PublicUrlGuard::webhookUrl(
                "api/webhooks/{$account->provider->value}/{$account->webhook_url_token}"
            )
            : null;

        $payload = [
            'id' => $account->id,
            'scope' => $account->scope->value,
            'site_id' => $account->site_id,
            'channel' => $account->channel->value,
            'provider' => $account->provider->value,
            'is_active' => $account->is_active,
            'credentials' => CredentialMasker::maskFields($credentials, $fields),
            'credentials_unreadable' => CredentialMasker::isUnreadable($account, 'credentials'),
            'webhook_url' => $webhookUrl,
            'webhook_configured' => $account->webhook_configured_at !== null,
            'webhook_configured_at' => $account->webhook_configured_at?->toIso8601String(),
            'auto_registers_webhooks' => (bool) ($option['auto_registers_webhooks'] ?? false),
            'status' => $account->status->value,
            'verified_at' => $account->verified_at?->toIso8601String(),
            'last_error' => $account->last_error,
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];

        if ($account->channel === Channel::Call && $account->provider === Provider::Aircall) {
            $payload['dial_health'] = AircallUserDirectory::dialHealth();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function channelPayload(Channel $channel): array
    {
        $accounts = CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', $channel)
            ->get();

        $options = $this->registry->optionsFor($channel);
        $active = $accounts->first(fn (CommunicationAccount $a) => $a->is_active);

        return [
            'channel' => $channel->value,
            'label' => $channel->label(),
            'active_provider' => $active?->provider->value,
            'accounts' => $accounts->map(
                fn (CommunicationAccount $account) => $this->serializeAccount($account, $options)
            )->values()->all(),
            'provider_options' => $options,
        ];
    }

    private function syncSenderIdentityAccounts(Channel $channel, CommunicationAccount $account): void
    {
        if (! $account->is_active) {
            return;
        }

        SiteSenderIdentity::query()
            ->where('channel', $channel)
            ->update(['account_id' => $account->id]);
    }
}
