<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessCredentialMode;
use App\Enums\AccessGrantState;
use App\Enums\CredentialStatus;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\Contract;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\RecordsActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Declarative access sync: desired vs grant cache vs (periodically) provider list.
 * Per-grant failure isolation; never holds a DB transaction across a provider call.
 */
final class AccessReconciler
{
    /**
     * @return array{
     *     to_grant: list<array{access_point_id: int, contact_id: int, contract_id: int}>,
     *     to_revoke: list<array{grant_id: int, access_point_id: int, contact_id: int, contract_id: int}>,
     *     stuck: list<array{grant_id: int, access_point_id: int, contact_id: int, contract_id: int, state: string}>,
     *     granted: int,
     *     revoked: int,
     *     failed: int,
     *     retried: int,
     *     dry_run: bool,
     *     drift: array{unknown: int, missing: int, denied_but_granted: int}
     * }
     */
    public function run(
        ?int $siteId = null,
        ?int $contractId = null,
        bool $dryRun = false,
        bool $withDrift = false,
    ): array {
        $desired = $this->desiredForScope($siteId, $contractId);
        $desiredByKey = $this->indexDesired($desired);

        $live = $this->liveGrantsForScope($siteId, $contractId);
        $liveByKey = $this->indexGrants($live);

        $stuckRows = $this->stuckGrantsForScope($siteId, $contractId);

        $toGrant = [];
        foreach ($desiredByKey as $key => $desiredGrant) {
            if (! isset($liveByKey[$key])) {
                $toGrant[] = [
                    'access_point_id' => $desiredGrant->accessPointId,
                    'contact_id' => $desiredGrant->contactId,
                    'contract_id' => $desiredGrant->contractId,
                ];
            }
        }

        $toRevoke = [];
        foreach ($live as $grant) {
            $key = $this->grantKey((int) $grant->access_point_id, (int) $grant->contact_id);
            if (! isset($desiredByKey[$key])) {
                $toRevoke[] = [
                    'grant_id' => (int) $grant->id,
                    'access_point_id' => (int) $grant->access_point_id,
                    'contact_id' => (int) $grant->contact_id,
                    'contract_id' => (int) $grant->contract_id,
                ];
            }
        }

        $stuck = [];
        foreach ($stuckRows as $grant) {
            $stuck[] = [
                'grant_id' => (int) $grant->id,
                'access_point_id' => (int) $grant->access_point_id,
                'contact_id' => (int) $grant->contact_id,
                'contract_id' => (int) $grant->contract_id,
                'state' => $grant->state instanceof AccessGrantState
                    ? $grant->state->value
                    : (string) $grant->state,
            ];
        }

        $summary = [
            'to_grant' => $toGrant,
            'to_revoke' => $toRevoke,
            'stuck' => $stuck,
            'granted' => 0,
            'revoked' => 0,
            'failed' => 0,
            'retried' => 0,
            'dry_run' => $dryRun,
            'drift' => ['unknown' => 0, 'missing' => 0, 'denied_but_granted' => 0],
        ];

        if ($dryRun) {
            return $summary;
        }

        $registry = app(AccessProviderRegistry::class);

        foreach ($toGrant as $spec) {
            $outcome = $this->convergeGrant(
                accessPointId: $spec['access_point_id'],
                contactId: $spec['contact_id'],
                contractId: $spec['contract_id'],
                registry: $registry,
            );
            match ($outcome) {
                'granted' => $summary['granted']++,
                'failed' => $summary['failed']++,
                default => null,
            };
        }

        foreach ($toRevoke as $spec) {
            $grant = AccessGrant::query()->find($spec['grant_id']);
            if ($grant === null) {
                continue;
            }
            $outcome = $this->convergeRevoke($grant, $registry);
            match ($outcome) {
                'revoked' => $summary['revoked']++,
                'failed' => $summary['failed']++,
                default => null,
            };
        }

        foreach ($stuckRows as $grant) {
            $grant->refresh();
            $key = $this->grantKey((int) $grant->access_point_id, (int) $grant->contact_id);
            $stillDesired = isset($desiredByKey[$key]);
            $outcome = $this->retryStuck($grant, $stillDesired, $registry);
            if ($outcome !== 'noop') {
                $summary['retried']++;
            }
            match ($outcome) {
                'granted' => $summary['granted']++,
                'revoked' => $summary['revoked']++,
                'failed' => $summary['failed']++,
                default => null,
            };
        }

        if ($withDrift) {
            $summary['drift'] = $this->detectDrift($desiredByKey, $registry, $siteId);
            $this->refreshAccountHealth($siteId);
        }

        return $summary;
    }

    /**
     * @return Collection<int, DesiredGrant>
     */
    private function desiredForScope(?int $siteId, ?int $contractId): Collection
    {
        if ($contractId !== null) {
            $contract = Contract::query()->find($contractId);
            if ($contract === null) {
                return collect();
            }

            return DesiredAccess::forContract($contract);
        }

        $sites = Site::query()
            ->when($siteId !== null, fn ($q) => $q->whereKey($siteId))
            ->whereIn('id', AccessPoint::query()->active()->select('site_id'))
            ->get();

        /** @var Collection<int, DesiredGrant> $all */
        $all = collect();
        foreach ($sites as $site) {
            $all = $all->concat(DesiredAccess::forSite($site));
        }

        return $all->values();
    }

    /**
     * @param  Collection<int, DesiredGrant>  $desired
     * @return array<string, DesiredGrant>
     */
    private function indexDesired(Collection $desired): array
    {
        $out = [];
        foreach ($desired as $g) {
            $out[$this->grantKey($g->accessPointId, $g->contactId)] = $g;
        }

        return $out;
    }

    /**
     * @param  Collection<int, AccessGrant>  $grants
     * @return array<string, AccessGrant>
     */
    private function indexGrants(Collection $grants): array
    {
        $out = [];
        foreach ($grants as $g) {
            $out[$this->grantKey((int) $g->access_point_id, (int) $g->contact_id)] = $g;
        }

        return $out;
    }

    private function grantKey(int $accessPointId, int $contactId): string
    {
        return $accessPointId.'|'.$contactId;
    }

    /**
     * @return Collection<int, AccessGrant>
     */
    private function liveGrantsForScope(?int $siteId, ?int $contractId): Collection
    {
        return AccessGrant::query()
            ->whereIn('state', [AccessGrantState::Applying->value, AccessGrantState::Applied->value])
            ->when($contractId !== null, fn ($q) => $q->where('contract_id', $contractId))
            ->when($siteId !== null, function ($q) use ($siteId): void {
                $q->whereIn(
                    'access_point_id',
                    AccessPoint::query()->where('site_id', $siteId)->select('id'),
                );
            })
            ->get();
    }

    /**
     * @return Collection<int, AccessGrant>
     */
    private function stuckGrantsForScope(?int $siteId, ?int $contractId): Collection
    {
        $threshold = now()->subSeconds((int) config('access.stuck_threshold_seconds', 300));

        return AccessGrant::query()
            ->where(function ($q) use ($threshold): void {
                $q->where('state', AccessGrantState::Failed->value)
                    ->orWhere(function ($inner) use ($threshold): void {
                        $inner->whereIn('state', [
                            AccessGrantState::Applying->value,
                            AccessGrantState::Revoking->value,
                        ])->where('updated_at', '<', $threshold);
                    });
            })
            ->when($contractId !== null, fn ($q) => $q->where('contract_id', $contractId))
            ->when($siteId !== null, function ($q) use ($siteId): void {
                $q->whereIn(
                    'access_point_id',
                    AccessPoint::query()->where('site_id', $siteId)->select('id'),
                );
            })
            ->get();
    }

    /**
     * @return 'granted'|'failed'|'noop'
     */
    private function convergeGrant(
        int $accessPointId,
        int $contactId,
        int $contractId,
        AccessProviderRegistry $registry,
    ): string {
        $existingFailed = AccessGrant::query()
            ->where('access_point_id', $accessPointId)
            ->where('contact_id', $contactId)
            ->where('state', AccessGrantState::Failed->value)
            ->orderByDesc('id')
            ->first();

        if ($existingFailed !== null) {
            $existingFailed->forceFill([
                'contract_id' => $contractId,
                'state' => AccessGrantState::Applying,
                'last_error' => null,
                'provider_grant_id' => null,
            ])->save();

            return $this->callGrant($existingFailed, $registry);
        }

        try {
            $grant = AccessGrant::query()->create([
                'access_point_id' => $accessPointId,
                'contact_id' => $contactId,
                'contract_id' => $contractId,
                'provider_grant_id' => null,
                'state' => AccessGrantState::Applying,
                'last_error' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'noop';
        }

        return $this->callGrant($grant, $registry);
    }

    /**
     * @return 'granted'|'failed'|'noop'
     */
    private function callGrant(AccessGrant $grant, AccessProviderRegistry $registry): string
    {
        $grant->loadMissing(['accessPoint.accessProviderAccount', 'contact']);

        $point = $grant->accessPoint;
        if ($point === null || $point->archived_at !== null) {
            $grant->forceFill([
                'state' => AccessGrantState::Failed,
                'last_error' => 'Access point missing or archived.',
            ])->save();
            SystemEvent::record('access.grant.failed', $grant, [
                'error' => 'Access point missing or archived.',
            ]);

            return 'failed';
        }

        $contact = $grant->contact;
        if ($contact === null) {
            $grant->forceFill([
                'state' => AccessGrantState::Failed,
                'last_error' => 'Contact missing.',
            ])->save();
            SystemEvent::record('access.grant.failed', $grant, [
                'error' => 'Contact missing.',
            ]);

            return 'failed';
        }

        $account = $point->accessProviderAccount;
        $provider = $account !== null
            ? $registry->forAccount($account)
            : $registry->active();

        $modes = $provider->credentialModes();
        $mode = $modes[0] ?? AccessCredentialMode::AppInvite->value;

        if ($mode === AccessCredentialMode::AppInvite->value) {
            $email = $contact->email;
            if (! is_string($email) || $email === '') {
                $grant->forceFill([
                    'state' => AccessGrantState::Failed,
                    'last_error' => 'app_invite mode requires contact email.',
                ])->save();
                SystemEvent::record('access.grant.failed', $grant, [
                    'error' => 'app_invite mode requires contact email.',
                    'contact_id' => $contact->id,
                ]);

                return 'failed';
            }
        }

        $person = [
            'name' => trim($contact->first_name.' '.$contact->last_name),
            'email' => $contact->email,
            'phone' => null,
        ];

        try {
            $ref = $provider->grant(new GrantSpec(
                providerPointId: $point->provider_point_id,
                person: $person,
                mode: $mode,
                metadata: ['contract_id' => (int) $grant->contract_id],
            ));
        } catch (Throwable $e) {
            $grant->forceFill([
                'state' => AccessGrantState::Failed,
                'last_error' => $e->getMessage(),
            ])->save();
            SystemEvent::record('access.grant.failed', $grant, [
                'error' => $e->getMessage(),
                'access_point_id' => $point->id,
                'contact_id' => $contact->id,
            ]);
            report($e);

            return 'failed';
        }

        $grant->forceFill([
            'state' => AccessGrantState::Applied,
            'provider_grant_id' => $ref->providerGrantId,
            'last_error' => null,
            'applied_at' => now(),
        ])->save();

        if ($ref->pin !== null && $ref->pin !== '') {
            $grant->storePin($ref->pin);
        }

        return 'granted';
    }

    /**
     * @return 'revoked'|'failed'|'noop'
     */
    private function convergeRevoke(AccessGrant $grant, AccessProviderRegistry $registry): string
    {
        if ($grant->state === AccessGrantState::Revoked) {
            return 'noop';
        }

        $grant->forceFill([
            'state' => AccessGrantState::Revoking,
            'last_error' => null,
        ])->save();

        return $this->callRevoke($grant, $registry);
    }

    /**
     * @return 'revoked'|'failed'|'noop'
     */
    private function callRevoke(AccessGrant $grant, AccessProviderRegistry $registry): string
    {
        $grant->loadMissing(['accessPoint.accessProviderAccount']);

        $providerGrantId = $grant->provider_grant_id;
        if ($providerGrantId === null || $providerGrantId === '') {
            $grant->forceFill([
                'state' => AccessGrantState::Revoked,
                'revoked_at' => now(),
                'last_error' => null,
            ])->save();

            return 'revoked';
        }

        $point = $grant->accessPoint;
        $account = $point?->accessProviderAccount;
        $provider = $account !== null
            ? $registry->forAccount($account)
            : $registry->active();

        try {
            $provider->revoke($providerGrantId);
        } catch (Throwable $e) {
            // Idempotent revoke: already absent at the provider is convergence, not failure.
            if (! str_contains(strtolower($e->getMessage()), 'unknown grant')) {
                $grant->forceFill([
                    'state' => AccessGrantState::Failed,
                    'last_error' => $e->getMessage(),
                ])->save();
                SystemEvent::record('access.grant.failed', $grant, [
                    'error' => $e->getMessage(),
                    'phase' => 'revoke',
                ]);
                report($e);

                return 'failed';
            }
        }

        $grant->forceFill([
            'state' => AccessGrantState::Revoked,
            'revoked_at' => now(),
            'last_error' => null,
        ])->save();

        return 'revoked';
    }

    /**
     * @return 'granted'|'revoked'|'failed'|'noop'
     */
    private function retryStuck(
        AccessGrant $grant,
        bool $stillDesired,
        AccessProviderRegistry $registry,
    ): string {
        $state = $grant->state instanceof AccessGrantState
            ? $grant->state
            : AccessGrantState::from((string) $grant->state);

        if ($state === AccessGrantState::Revoking) {
            return $this->callRevoke($grant, $registry);
        }

        if ($state === AccessGrantState::Applying) {
            if (! $stillDesired) {
                return $this->convergeRevoke($grant, $registry);
            }

            return $this->callGrant($grant, $registry);
        }

        if ($state === AccessGrantState::Failed) {
            if ($stillDesired) {
                $grant->forceFill([
                    'state' => AccessGrantState::Applying,
                    'last_error' => null,
                    'provider_grant_id' => null,
                ])->save();

                return $this->callGrant($grant, $registry);
            }

            if ($grant->provider_grant_id !== null && $grant->provider_grant_id !== '') {
                $grant->forceFill([
                    'state' => AccessGrantState::Revoking,
                    'last_error' => null,
                ])->save();

                return $this->callRevoke($grant, $registry);
            }

            $grant->forceFill([
                'state' => AccessGrantState::Revoked,
                'revoked_at' => now(),
                'last_error' => null,
            ])->save();

            return 'revoked';
        }

        return 'noop';
    }

    /**
     * @param  array<string, DesiredGrant>  $desiredByKey
     * @return array{unknown: int, missing: int, denied_but_granted: int}
     */
    private function detectDrift(
        array $desiredByKey,
        AccessProviderRegistry $registry,
        ?int $siteId,
    ): array {
        $counts = ['unknown' => 0, 'missing' => 0, 'denied_but_granted' => 0];

        $accounts = AccessProviderAccount::query()
            ->where('is_active', true)
            ->where('status', CredentialStatus::Connected)
            ->get();

        if ($accounts->isEmpty()) {
            // Tests often override the registry with Fake without a Connected account
            // wired to sensorberg — still drift-check the active adapter against mapped points.
            $this->driftAgainstProvider(
                $registry->active(),
                null,
                $desiredByKey,
                $siteId,
                $counts,
            );

            return $counts;
        }

        foreach ($accounts as $account) {
            $this->driftAgainstProvider(
                $registry->forAccount($account),
                $account,
                $desiredByKey,
                $siteId,
                $counts,
            );
        }

        return $counts;
    }

    /**
     * @param  array<string, DesiredGrant>  $desiredByKey
     * @param  array{unknown: int, missing: int, denied_but_granted: int}  $counts
     */
    private function driftAgainstProvider(
        AccessProvider $provider,
        ?AccessProviderAccount $account,
        array $desiredByKey,
        ?int $siteId,
        array &$counts,
    ): void {
        try {
            $remote = $provider->listGrants();
        } catch (Throwable $e) {
            SystemEvent::record('access.drift.list_failed', $account, [
                'error' => $e->getMessage(),
            ]);
            report($e);

            return;
        }

        $pointQuery = AccessPoint::query()->active();
        if ($account !== null) {
            $pointQuery->where('access_provider_account_id', $account->id);
        }
        if ($siteId !== null) {
            $pointQuery->where('site_id', $siteId);
        }
        $points = $pointQuery->get()->keyBy('provider_point_id');

        $knownRefs = AccessGrant::query()
            ->whereNotNull('provider_grant_id')
            ->when($account !== null, function ($q) use ($account): void {
                $q->whereIn(
                    'access_point_id',
                    AccessPoint::query()
                        ->where('access_provider_account_id', $account->id)
                        ->select('id'),
                );
            })
            ->pluck('id', 'provider_grant_id');

        $remoteRefs = [];
        foreach ($remote as $row) {
            $ref = (string) ($row['grant_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $remoteRefs[$ref] = $row;
            $providerPointId = (string) ($row['provider_point_id'] ?? '');
            $point = $points->get($providerPointId);

            if (! $knownRefs->has($ref)) {
                $counts['unknown']++;
                SystemEvent::record('access.drift.unknown_grant', $account ?? $point, [
                    'grant_ref' => $ref,
                    'provider_point_id' => $providerPointId,
                ]);

                continue;
            }

            /** @var AccessGrant|null $local */
            $local = AccessGrant::query()->find($knownRefs->get($ref));
            if ($local === null || $point === null) {
                continue;
            }

            $key = $this->grantKey((int) $local->access_point_id, (int) $local->contact_id);
            if (! isset($desiredByKey[$key])) {
                $counts['denied_but_granted']++;
                $this->forceProviderRevoke($local->fresh() ?? $local, $provider);
                RecordsActivity::core('access.drift_denied_but_granted', $local, [
                    'grant_ref' => $ref,
                    'access_point_id' => $local->access_point_id,
                    'contact_id' => $local->contact_id,
                    'contract_id' => $local->contract_id,
                ], anonymous: true);
                if ($account !== null) {
                    $this->appendDriftIncident($account, [
                        'contract_id' => (int) $local->contract_id,
                        'grant_ref' => $ref,
                        'access_point_id' => (int) $local->access_point_id,
                        'contact_id' => (int) $local->contact_id,
                        'occurred_at' => now()->toIso8601String(),
                    ]);
                }
            }
        }

        $applied = AccessGrant::query()
            ->where('state', AccessGrantState::Applied->value)
            ->whereNotNull('provider_grant_id')
            ->when($account !== null, function ($q) use ($account): void {
                $q->whereIn(
                    'access_point_id',
                    AccessPoint::query()
                        ->where('access_provider_account_id', $account->id)
                        ->select('id'),
                );
            })
            ->when($siteId !== null, function ($q) use ($siteId): void {
                $q->whereIn(
                    'access_point_id',
                    AccessPoint::query()->where('site_id', $siteId)->select('id'),
                );
            })
            ->get();

        $registry = app(AccessProviderRegistry::class);
        foreach ($applied as $grant) {
            $ref = (string) $grant->provider_grant_id;
            if ($ref === '' || isset($remoteRefs[$ref])) {
                continue;
            }

            $counts['missing']++;
            SystemEvent::record('access.drift.missing_at_provider', $grant, [
                'grant_ref' => $ref,
                'access_point_id' => $grant->access_point_id,
                'contact_id' => $grant->contact_id,
            ]);

            $grant->forceFill([
                'state' => AccessGrantState::Applying,
                'provider_grant_id' => null,
                'last_error' => null,
            ])->save();
            $this->callGrant($grant, $registry);
        }

        if ($account !== null) {
            $unknown = [];
            foreach ($remote as $row) {
                $ref = (string) ($row['grant_ref'] ?? '');
                if ($ref !== '' && ! $knownRefs->has($ref)) {
                    $unknown[] = [
                        'grant_ref' => $ref,
                        'provider_point_id' => (string) ($row['provider_point_id'] ?? ''),
                        'credential_ref' => $row['credential_ref'] ?? null,
                    ];
                }
            }

            $account->forceFill([
                'sync_attention' => array_merge(
                    is_array($account->sync_attention) ? $account->sync_attention : [],
                    ['unknown_grants' => $unknown],
                ),
            ])->save();
        }
    }

    /**
     * Drift revoke: always hit the provider even when our cache already says revoked.
     */
    private function forceProviderRevoke(AccessGrant $grant, AccessProvider $provider): void
    {
        $ref = $grant->provider_grant_id;
        if (is_string($ref) && $ref !== '') {
            try {
                $provider->revoke($ref);
            } catch (Throwable $e) {
                if (! str_contains(strtolower($e->getMessage()), 'unknown grant')) {
                    $grant->forceFill([
                        'state' => AccessGrantState::Failed,
                        'last_error' => $e->getMessage(),
                    ])->save();
                    SystemEvent::record('access.grant.failed', $grant, [
                        'error' => $e->getMessage(),
                        'phase' => 'drift_revoke',
                    ]);
                    report($e);

                    return;
                }
            }
        }

        if ($grant->state !== AccessGrantState::Revoked) {
            $grant->forceFill([
                'state' => AccessGrantState::Revoked,
                'revoked_at' => now(),
                'last_error' => null,
            ])->save();
        }
    }

    private function refreshAccountHealth(?int $siteId): void
    {
        $accounts = AccessProviderAccount::query()
            ->where('is_active', true)
            ->get();

        foreach ($accounts as $account) {
            $pointIds = AccessPoint::query()
                ->where('access_provider_account_id', $account->id)
                ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
                ->pluck('id');

            $applied = AccessGrant::query()
                ->whereIn('access_point_id', $pointIds)
                ->where('state', AccessGrantState::Applied->value)
                ->count();
            $failed = AccessGrant::query()
                ->whereIn('access_point_id', $pointIds)
                ->where('state', AccessGrantState::Failed->value)
                ->count();

            $attention = is_array($account->sync_attention) ? $account->sync_attention : [];
            $attention['applied_count'] = $applied;
            $attention['failed_count'] = $failed;
            $attention['unknown_grants'] = $attention['unknown_grants'] ?? [];
            $attention['drift_denied_but_granted'] = $this->pruneDriftIncidents(
                is_array($attention['drift_denied_but_granted'] ?? null)
                    ? $attention['drift_denied_but_granted']
                    : [],
            );

            $account->forceFill([
                'last_full_synced_at' => now(),
                'sync_attention' => $attention,
            ])->save();
        }
    }

    /**
     * @param  array{contract_id: int, grant_ref: string, access_point_id: int, contact_id: int, occurred_at: string}  $incident
     */
    private function appendDriftIncident(AccessProviderAccount $account, array $incident): void
    {
        $attention = is_array($account->sync_attention) ? $account->sync_attention : [];
        $list = is_array($attention['drift_denied_but_granted'] ?? null)
            ? $attention['drift_denied_but_granted']
            : [];
        $list[] = $incident;
        $attention['drift_denied_but_granted'] = $this->pruneDriftIncidents($list);
        $account->forceFill(['sync_attention' => $attention])->save();
    }

    /**
     * Keep drift incidents for 30 days (operator queue without a dismiss API).
     *
     * @param  list<mixed>  $incidents
     * @return list<array<string, mixed>>
     */
    private function pruneDriftIncidents(array $incidents): array
    {
        $cutoff = now()->subDays(30);
        $out = [];

        foreach ($incidents as $row) {
            if (! is_array($row)) {
                continue;
            }
            $occurred = isset($row['occurred_at']) ? (string) $row['occurred_at'] : '';
            if ($occurred === '') {
                continue;
            }
            try {
                if (\Illuminate\Support\Carbon::parse($occurred)->lt($cutoff)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $out[] = $row;
        }

        return array_values($out);
    }
}
