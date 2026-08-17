<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Enums\AnalyticsProvider as AnalyticsProviderName;
use App\Models\AnalyticsAccount;
use App\Support\Credentials\CredentialMasker;
use App\Support\Insights\Contracts\AnalyticsProvider;
use App\Support\Insights\Contracts\DescribesResourceParams;
use App\Support\Insights\Contracts\ListsResources;
use App\Support\Insights\Providers\IframeProvider;
use App\Support\Insights\Providers\MetabaseProvider;
use InvalidArgumentException;

/**
 * Maps analytics provider key → adapter class. Capability is interface
 * presence (instanceof), never a capabilities() boolean map.
 */
final class AnalyticsProviderRegistry
{
    /** @var array<string, class-string<AnalyticsProvider>> */
    private array $map;

    public function __construct()
    {
        $this->map = [
            AnalyticsProviderName::Metabase->value => MetabaseProvider::class,
            AnalyticsProviderName::Iframe->value => IframeProvider::class,
            // Superset / Power BI adapters land when needed:
            // 'superset' => SupersetProvider::class,
            // 'powerbi' => PowerBiProvider::class,
        ];
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->map);
    }

    public function supports(string $provider): bool
    {
        return isset($this->map[$provider]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function make(string $provider, array $credentials, string $baseUrl, ?string $privateBaseUrl = null): AnalyticsProvider
    {
        $class = $this->map[$provider] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('Unknown analytics provider: '.$provider);
        }

        return $class::make($credentials, $baseUrl, $privateBaseUrl);
    }

    public function forAccount(AnalyticsAccount $account): AnalyticsProvider
    {
        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        return $this->make(
            $account->provider->value,
            $credentials,
            $account->base_url,
            $account->private_base_url,
        );
    }

    /**
     * Shape consumed by the settings form: provider options + credentialFields
     * + interface-derived capability flags.
     *
     * @return list<array{
     *     key: string,
     *     label: string,
     *     credential_fields: array<string, array{label: string, secret: bool}>,
     *     resource_kinds: list<string>,
     *     lists_resources: bool,
     *     describes_params: bool
     * }>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->map as $provider => $class) {
            $adapter = $class::make([], '');
            $label = AnalyticsProviderName::tryFrom($provider)?->label() ?? ucfirst($provider);

            $options[] = [
                'key' => $provider,
                'label' => $label,
                'credential_fields' => $adapter->credentialFields(),
                'resource_kinds' => $adapter->resourceKinds(),
                'lists_resources' => is_subclass_of($class, ListsResources::class),
                'describes_params' => is_subclass_of($class, DescribesResourceParams::class),
            ];
        }

        return $options;
    }
}
