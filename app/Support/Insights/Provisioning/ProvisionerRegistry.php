<?php

declare(strict_types=1);

namespace App\Support\Insights\Provisioning;

use App\Enums\AnalyticsProvider as AnalyticsProviderName;
use App\Models\AnalyticsAccount;
use App\Support\Credentials\CredentialMasker;
use App\Support\Insights\Contracts\ProvisionsResources;
use InvalidArgumentException;

/**
 * Maps analytics provider key → write provisioner. iframe has no entry —
 * that URL is the operator's.
 */
final class ProvisionerRegistry
{
    /** @var array<string, class-string<ProvisionsResources>> */
    private array $map;

    public function __construct()
    {
        $this->map = [
            AnalyticsProviderName::Metabase->value => MetabaseProvisioner::class,
        ];
    }

    public function supports(string $provider): bool
    {
        return isset($this->map[$provider]);
    }

    public function forAccount(AnalyticsAccount $account): ProvisionsResources
    {
        if (CredentialMasker::isUnreadable($account, 'credentials')) {
            throw ProvisioningException::credentialsUnreadable();
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $class = $this->map[$account->provider->value] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException(
                'No provisioner for analytics provider: '.$account->provider->value,
            );
        }

        return $class::make($credentials, $this->apiHost($account));
    }

    private function apiHost(AnalyticsAccount $account): string
    {
        $private = $account->private_base_url;

        return is_string($private) && $private !== ''
            ? $private
            : $account->base_url;
    }
}
