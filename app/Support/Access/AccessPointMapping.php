<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessPointType;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mapping read/write helpers for S15-04: discovered ↔ assigned ↔ vanished rows,
 * label-pattern bulk suggestions.
 */
final class AccessPointMapping
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(?AccessProviderAccount $account): array
    {
        if ($account === null) {
            return [];
        }

        $discovered = self::discoveredList($account);
        $discoveredIds = collect($discovered)->pluck('provider_point_id')->all();

        $assigned = AccessPoint::query()
            ->active()
            ->where('access_provider_account_id', $account->id)
            ->with(['site:id,name', 'unit:id,unit_number,site_id'])
            ->orderBy('id')
            ->get();

        $assignedByProviderId = $assigned->keyBy('provider_point_id');

        $rows = [];

        foreach ($discovered as $point) {
            $providerPointId = $point['provider_point_id'];
            $existing = $assignedByProviderId->get($providerPointId);

            if ($existing instanceof AccessPoint) {
                $rows[] = self::assignedRow($existing, $point['kind_hint'], 'assigned');
                continue;
            }

            $rows[] = [
                'id' => null,
                'status' => 'unassigned',
                'provider_point_id' => $providerPointId,
                'label' => $point['label'],
                'kind_hint' => $point['kind_hint'],
                'point_type' => self::hintToType($point['kind_hint'])?->value,
                'site_id' => null,
                'site_name' => null,
                'unit_id' => null,
                'unit_number' => null,
                'archived_at' => null,
            ];
        }

        foreach ($assigned as $point) {
            if (in_array($point->provider_point_id, $discoveredIds, true)) {
                continue;
            }

            $rows[] = self::assignedRow($point, null, 'vanished');
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     provider_point_id: string,
     *     label: string,
     *     suggested_site_id: int,
     *     suggested_unit_id: int,
     *     suggested_point_type: string,
     *     confidence: string
     * }>
     */
    public static function suggest(?AccessProviderAccount $account): array
    {
        if ($account === null) {
            return [];
        }

        $assignedIds = AccessPoint::query()
            ->active()
            ->where('access_provider_account_id', $account->id)
            ->pluck('provider_point_id')
            ->all();

        $units = Unit::query()
            ->whereHas('site', fn ($q) => $q->whereNull('archived_at'))
            ->with('site:id,name')
            ->get(['id', 'site_id', 'unit_number']);

        $suggestions = [];

        foreach (self::discoveredList($account) as $point) {
            if (in_array($point['provider_point_id'], $assignedIds, true)) {
                continue;
            }

            $type = self::hintToType($point['kind_hint']);
            if ($type !== null && $type !== AccessPointType::UnitDoor) {
                continue;
            }

            $match = self::matchUnit($point['label'], $units);
            if ($match === null) {
                continue;
            }

            $suggestions[] = [
                'provider_point_id' => $point['provider_point_id'],
                'label' => $point['label'],
                'suggested_site_id' => (int) $match['unit']->site_id,
                'suggested_unit_id' => (int) $match['unit']->id,
                'suggested_point_type' => AccessPointType::UnitDoor->value,
                'confidence' => $match['confidence'],
            ];
        }

        return $suggestions;
    }

    /**
     * @param  list<array{
     *     provider_point_id: string,
     *     site_id: int,
     *     unit_id: int|null,
     *     point_type: string,
     *     label?: string|null
     * }>  $assignments
     * @return array{confirmed_count: int, points: list<AccessPoint>}
     */
    public static function bulkAssign(AccessProviderAccount $account, array $assignments): array
    {
        $discovered = collect(self::discoveredList($account))->keyBy('provider_point_id');

        $created = DB::transaction(function () use ($account, $assignments, $discovered): Collection {
            $points = collect();

            foreach ($assignments as $row) {
                $providerPointId = (string) $row['provider_point_id'];
                $discoveredRow = $discovered->get($providerPointId);
                if ($discoveredRow === null) {
                    throw ValidationException::withMessages([
                        'assignments' => ["Unknown discovered point: {$providerPointId}"],
                    ]);
                }

                $existing = AccessPoint::query()
                    ->active()
                    ->where('access_provider_account_id', $account->id)
                    ->where('provider_point_id', $providerPointId)
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'assignments' => ["Point already assigned: {$providerPointId}"],
                    ]);
                }

                $type = AccessPointType::from((string) $row['point_type']);
                $unitId = isset($row['unit_id']) ? (int) $row['unit_id'] : null;
                self::assertTypeRules($type, $unitId, (int) $row['site_id']);

                $points->push(AccessPoint::query()->create([
                    'access_provider_account_id' => $account->id,
                    'site_id' => (int) $row['site_id'],
                    'unit_id' => $unitId,
                    'point_type' => $type,
                    'provider_point_id' => $providerPointId,
                    'label' => is_string($row['label'] ?? null) && $row['label'] !== ''
                        ? $row['label']
                        : $discoveredRow['label'],
                ]));
            }

            return $points;
        });

        return [
            'confirmed_count' => $created->count(),
            'points' => $created->all(),
        ];
    }

    /**
     * @return array{provider_point_id: string, label: string, kind_hint: string|null}
     */
    public static function discoveredById(AccessProviderAccount $account, string $providerPointId): ?array
    {
        foreach (self::discoveredList($account) as $point) {
            if ($point['provider_point_id'] === $providerPointId) {
                return $point;
            }
        }

        return null;
    }

    public static function assertTypeRules(AccessPointType $type, ?int $unitId, int $siteId): void
    {
        if ($type === AccessPointType::UnitDoor) {
            if ($unitId === null) {
                throw ValidationException::withMessages([
                    'unit_id' => ['Unit door points require a unit.'],
                ]);
            }

            $unit = Unit::query()->find($unitId);
            if ($unit === null || (int) $unit->site_id !== $siteId) {
                throw ValidationException::withMessages([
                    'unit_id' => ['Unit must belong to the selected site.'],
                ]);
            }

            $taken = AccessPoint::query()
                ->active()
                ->where('unit_id', $unitId)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'unit_id' => ['This unit already has an active door mapping.'],
                ]);
            }

            return;
        }

        if ($unitId !== null) {
            throw ValidationException::withMessages([
                'unit_id' => ['Gate and zone points cannot be assigned to a unit.'],
            ]);
        }
    }

    public static function hintToType(?string $hint): ?AccessPointType
    {
        if ($hint === null || $hint === '') {
            return null;
        }

        return AccessPointType::tryFrom($hint);
    }

    /**
     * @return list<array{provider_point_id: string, label: string, kind_hint: string|null}>
     */
    private static function discoveredList(AccessProviderAccount $account): array
    {
        $raw = $account->discovered_points;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['provider_point_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'provider_point_id' => $id,
                'label' => (string) ($row['label'] ?? $id),
                'kind_hint' => isset($row['kind_hint']) && is_string($row['kind_hint'])
                    ? $row['kind_hint']
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function assignedRow(AccessPoint $point, ?string $kindHint, string $status): array
    {
        return [
            'id' => $point->id,
            'status' => $status,
            'provider_point_id' => $point->provider_point_id,
            'label' => $point->label,
            'kind_hint' => $kindHint,
            'point_type' => $point->point_type instanceof AccessPointType
                ? $point->point_type->value
                : (string) $point->point_type,
            'site_id' => $point->site_id,
            'site_name' => $point->relationLoaded('site') ? $point->site?->name : null,
            'unit_id' => $point->unit_id,
            'unit_number' => $point->relationLoaded('unit') ? $point->unit?->unit_number : null,
            'archived_at' => null,
        ];
    }

    /**
     * @param  Collection<int, Unit>  $units
     * @return array{unit: Unit, confidence: string}|null
     */
    private static function matchUnit(string $label, Collection $units): ?array
    {
        $normalizedLabel = mb_strtolower($label);
        $tokens = preg_split('/[\s,;|\/\\\\]+/', $normalizedLabel) ?: [];

        $exact = null;
        $substring = null;

        foreach ($units as $unit) {
            $number = mb_strtolower((string) $unit->unit_number);
            if ($number === '') {
                continue;
            }

            foreach ($tokens as $token) {
                if ($token === $number) {
                    $exact = $unit;
                    break 2;
                }
            }

            if ($substring === null && str_contains($normalizedLabel, $number)) {
                $substring = $unit;
            }
        }

        if ($exact !== null) {
            return ['unit' => $exact, 'confidence' => 'exact'];
        }

        if ($substring !== null) {
            return ['unit' => $substring, 'confidence' => 'substring'];
        }

        return null;
    }
}
