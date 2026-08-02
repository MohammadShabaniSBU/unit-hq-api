<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessGrantState;
use App\Enums\AccessPointType;
use App\Enums\AccessSuspensionReason;
use App\Enums\HoldType;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessSuspension;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-surface formatter for access state (S15-04). Pure — no provider calls.
 */
final class AccessState
{
    private const LIVE_GRANT_STATES = [
        AccessGrantState::Applying->value,
        AccessGrantState::Applied->value,
        AccessGrantState::Revoking->value,
        AccessGrantState::Failed->value,
    ];

    /**
     * @return array{
     *     active: bool,
     *     pending_restore: bool,
     *     reason: string|null,
     *     delinquency_id: int|null,
     *     created_at: string|null,
     *     created_by: array{id: int, name: string}|null,
     *     can_restore: bool
     * }
     */
    public static function suspensionBlock(Contract $contract): array
    {
        $active = AccessSuspension::query()
            ->active()
            ->where('contract_id', $contract->id)
            ->with('createdBy:id,name')
            ->first();

        if ($active === null) {
            return [
                'active' => false,
                'pending_restore' => false,
                'reason' => null,
                'delinquency_id' => null,
                'created_at' => null,
                'created_by' => null,
                'can_restore' => false,
            ];
        }

        $reason = $active->reason instanceof AccessSuspensionReason
            ? $active->reason->value
            : (string) $active->reason;

        $pendingRestore = false;
        if ($active->reason === AccessSuspensionReason::Delinquency && $active->delinquency_id !== null) {
            $case = Delinquency::query()->find($active->delinquency_id);
            $pendingRestore = $case !== null && ! $case->isOpen();
        }

        $createdBy = null;
        if ($active->createdBy !== null) {
            $createdBy = [
                'id' => (int) $active->createdBy->id,
                'name' => (string) $active->createdBy->name,
            ];
        }

        // Manual suspensions always restorable; delinquency pending_restore waits for
        // operator when auto_restore is off / case cured; open delinquency cases also
        // allow manual restore via the case actions.
        $canRestore = $active->reason === AccessSuspensionReason::Manual
            || $pendingRestore
            || $active->reason === AccessSuspensionReason::Delinquency;

        return [
            'active' => true,
            'pending_restore' => $pendingRestore,
            'reason' => $reason,
            'delinquency_id' => $active->delinquency_id !== null ? (int) $active->delinquency_id : null,
            'created_at' => $active->created_at?->toDateTimeString(),
            'created_by' => $createdBy,
            'can_restore' => $canRestore,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function contractGrants(Contract $contract): array
    {
        $grants = AccessGrant::query()
            ->where('contract_id', $contract->id)
            ->whereIn('state', self::LIVE_GRANT_STATES)
            ->with(['accessPoint:id,label,point_type,unit_id,site_id', 'contact:id,first_name,last_name'])
            ->orderBy('id')
            ->get();

        return $grants->map(fn (AccessGrant $grant): array => self::grantRow($grant))->values()->all();
    }

    /**
     * @return array{
     *     mapped: bool,
     *     point: array<string, mixed>|null,
     *     grants: list<array<string, mixed>>,
     *     overlock_denies_door: bool,
     *     suspension_denies: bool
     * }
     */
    public static function forUnit(Unit $unit): array
    {
        $point = AccessPoint::query()
            ->active()
            ->where('unit_id', $unit->id)
            ->where('point_type', AccessPointType::UnitDoor->value)
            ->first();

        $unit->loadMissing('site');
        $today = $unit->site !== null
            ? SiteClock::today($unit->site)->format('Y-m-d')
            : now()->toDateString();

        $overlockActive = UnitHold::query()
            ->where('unit_id', $unit->id)
            ->whereNull('released_at')
            ->where('hold_type', HoldType::Overlock->value)
            ->where('starts_on', '<=', $today)
            ->where(function ($q) use ($today): void {
                $q->whereNull('ends_on')->orWhere('ends_on', '>', $today);
            })
            ->exists();

        $grants = collect();
        if ($point !== null) {
            $grants = AccessGrant::query()
                ->where('access_point_id', $point->id)
                ->whereIn('state', self::LIVE_GRANT_STATES)
                ->with(['contact:id,first_name,last_name', 'accessPoint:id,label,point_type,unit_id,site_id'])
                ->orderBy('id')
                ->get();
        }

        $contractIds = $grants->pluck('contract_id')->unique()->filter()->all();
        $suspendedContractIds = $contractIds === []
            ? []
            : AccessSuspension::query()
                ->active()
                ->whereIn('contract_id', $contractIds)
                ->pluck('contract_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return [
            'mapped' => $point !== null,
            'point' => $point === null ? null : [
                'id' => $point->id,
                'label' => $point->label,
                'point_type' => $point->point_type instanceof AccessPointType
                    ? $point->point_type->value
                    : (string) $point->point_type,
                'provider_point_id' => $point->provider_point_id,
            ],
            'grants' => $grants->map(fn (AccessGrant $g): array => self::grantRow($g))->values()->all(),
            'overlock_denies_door' => $overlockActive,
            'suspension_denies' => $suspendedContractIds !== [],
        ];
    }

    /**
     * Inbox tenancy glyph payload.
     *
     * @return array{suspended: bool, reason: string|null, day_count: int|null}
     */
    public static function inboxAccess(Contract $contract, ?Unit $unit = null): array
    {
        $active = AccessSuspension::query()
            ->active()
            ->where('contract_id', $contract->id)
            ->first();

        if ($active === null) {
            return [
                'suspended' => false,
                'reason' => null,
                'day_count' => null,
            ];
        }

        $reason = $active->reason instanceof AccessSuspensionReason
            ? $active->reason->value
            : (string) $active->reason;

        $site = $unit?->site;
        if ($site === null && $unit === null) {
            $contract->loadMissing(['unitItem.item.site']);
            $item = $contract->unitItem?->item;
            if ($item instanceof Unit) {
                $site = $item->site;
            }
        }

        $today = $site !== null
            ? SiteClock::today($site)
            : CarbonImmutable::now()->startOfDay();

        $created = $active->created_at !== null
            ? CarbonImmutable::parse($active->created_at->toDateString())->startOfDay()
            : $today;

        $dayCount = max(0, (int) $created->diffInDays($today));

        return [
            'suspended' => true,
            'reason' => $reason,
            'day_count' => $dayCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function grantRow(AccessGrant $grant): array
    {
        $point = $grant->accessPoint;
        $contact = $grant->contact;
        $state = $grant->state instanceof AccessGrantState
            ? $grant->state->value
            : (string) $grant->state;

        return [
            'id' => $grant->id,
            'point_id' => $grant->access_point_id,
            'label' => $point?->label,
            'point_type' => $point?->point_type instanceof AccessPointType
                ? $point->point_type->value
                : ($point !== null ? (string) $point->point_type : null),
            'contact_id' => $grant->contact_id,
            'contact_name' => $contact !== null
                ? trim($contact->first_name.' '.$contact->last_name)
                : null,
            'contract_id' => $grant->contract_id,
            'state' => $state,
            'last_error' => $grant->last_error,
            'applied_at' => $grant->applied_at?->toDateTimeString(),
            'can_retry' => $state === AccessGrantState::Failed->value,
        ];
    }

    /**
     * @param  Collection<int, AccessGrant>  $grants
     * @return list<array<string, mixed>>
     */
    public static function grantRows(Collection $grants): array
    {
        return $grants->map(fn (AccessGrant $g): array => self::grantRow($g))->values()->all();
    }
}
