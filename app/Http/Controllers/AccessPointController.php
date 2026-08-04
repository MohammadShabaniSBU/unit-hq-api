<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccessPointType;
use App\Http\Resources\AccessPointResource;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Support\Access\AccessPointMapping;
use App\Support\Access\AccessSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Access point mapping workflow (S15-04).
 */
class AccessPointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $account = $this->activeAccount();

        return $this->success([
            'rows' => AccessPointMapping::rows($account, $employee, Permission::AccessView),
        ], 'Access points retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value);

        $account = $this->requireActiveAccount();

        $validated = $request->validate([
            'provider_point_id' => ['required', 'string', 'max:128'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'point_type' => ['required', 'string', Rule::enum(AccessPointType::class)],
            'label' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $discovered = AccessPointMapping::discoveredById($account, $validated['provider_point_id']);
        if ($discovered === null) {
            throw ValidationException::withMessages([
                'provider_point_id' => ['Point is not in the discovered list. Refresh points first.'],
            ]);
        }

        $existing = AccessPoint::query()
            ->active()
            ->where('access_provider_account_id', $account->id)
            ->where('provider_point_id', $validated['provider_point_id'])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'provider_point_id' => ['This provider point is already mapped.'],
            ]);
        }

        $type = AccessPointType::from($validated['point_type']);
        $unitId = isset($validated['unit_id']) ? (int) $validated['unit_id'] : null;
        AccessPointMapping::assertTypeRules($type, $unitId, (int) $validated['site_id']);

        $point = AccessPoint::query()->create([
            'access_provider_account_id' => $account->id,
            'site_id' => (int) $validated['site_id'],
            'unit_id' => $unitId,
            'point_type' => $type,
            'provider_point_id' => $validated['provider_point_id'],
            'label' => is_string($validated['label'] ?? null) && $validated['label'] !== ''
                ? $validated['label']
                : $discovered['label'],
        ]);

        $point->load(['site:id,name', 'unit:id,unit_number,site_id']);
        AccessSync::nudgeSite((int) $point->site_id);

        return $this->created(
            AccessPointResource::make($point)->resolve(),
            'Access point mapped successfully.',
        );
    }

    public function update(Request $request, AccessPoint $accessPoint): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value, $accessPoint);

        if ($accessPoint->isArchived()) {
            throw ValidationException::withMessages([
                'access_point' => ['Archived points cannot be updated.'],
            ]);
        }

        $validated = $request->validate([
            'site_id' => ['sometimes', 'required', 'integer', 'exists:sites,id'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'point_type' => ['sometimes', 'required', 'string', Rule::enum(AccessPointType::class)],
            'label' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $siteId = (int) ($validated['site_id'] ?? $accessPoint->site_id);
        $type = isset($validated['point_type'])
            ? AccessPointType::from($validated['point_type'])
            : ($accessPoint->point_type instanceof AccessPointType
                ? $accessPoint->point_type
                : AccessPointType::from((string) $accessPoint->point_type));
        $unitId = array_key_exists('unit_id', $validated)
            ? (isset($validated['unit_id']) ? (int) $validated['unit_id'] : null)
            : ($accessPoint->unit_id !== null ? (int) $accessPoint->unit_id : null);

        // Temporarily clear unit so uniqueness check ignores this row.
        $previousUnitId = $accessPoint->unit_id;
        if ($previousUnitId !== null && $unitId === (int) $previousUnitId) {
            // Same unit — skip taken check by asserting against other rows only.
            $taken = AccessPoint::query()
                ->active()
                ->where('unit_id', $unitId)
                ->where('id', '!=', $accessPoint->id)
                ->exists();
            if ($type === AccessPointType::UnitDoor && $taken) {
                throw ValidationException::withMessages([
                    'unit_id' => ['This unit already has an active door mapping.'],
                ]);
            }
            if ($type !== AccessPointType::UnitDoor && $unitId !== null) {
                throw ValidationException::withMessages([
                    'unit_id' => ['Gate and zone points cannot be assigned to a unit.'],
                ]);
            }
            if ($type === AccessPointType::UnitDoor) {
                $unit = \App\Models\Unit::query()->find($unitId);
                if ($unit === null || (int) $unit->site_id !== $siteId) {
                    throw ValidationException::withMessages([
                        'unit_id' => ['Unit must belong to the selected site.'],
                    ]);
                }
            }
        } else {
            AccessPointMapping::assertTypeRules($type, $unitId, $siteId);
        }

        $accessPoint->fill([
            'site_id' => $siteId,
            'unit_id' => $unitId,
            'point_type' => $type,
            'label' => array_key_exists('label', $validated) && is_string($validated['label']) && $validated['label'] !== ''
                ? $validated['label']
                : $accessPoint->label,
        ])->save();

        $accessPoint->load(['site:id,name', 'unit:id,unit_number,site_id']);
        AccessSync::nudgeSite($siteId);
        if ($previousUnitId !== null && (int) ($accessPoint->site_id) !== $siteId) {
            // site already nudged; no-op
        }

        return $this->success(
            AccessPointResource::make($accessPoint)->resolve(),
            'Access point updated successfully.',
        );
    }

    public function archive(AccessPoint $accessPoint): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value, $accessPoint);

        $siteId = (int) $accessPoint->site_id;
        $accessPoint->archive();
        AccessSync::nudgeSite($siteId);

        $accessPoint->load(['site:id,name', 'unit:id,unit_number,site_id']);

        return $this->success(
            AccessPointResource::make($accessPoint)->resolve(),
            'Access point archived successfully.',
        );
    }

    public function suggest(): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value);

        $account = $this->activeAccount();

        return $this->success([
            'suggestions' => AccessPointMapping::suggest($account),
        ], 'Access point suggestions retrieved successfully.');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value);

        $account = $this->requireActiveAccount();

        $validated = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.provider_point_id' => ['required', 'string', 'max:128'],
            'assignments.*.site_id' => ['required', 'integer', 'exists:sites,id'],
            'assignments.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'assignments.*.point_type' => ['required', 'string', Rule::enum(AccessPointType::class)],
            'assignments.*.label' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $result = AccessPointMapping::bulkAssign($account, $validated['assignments']);

        $siteIds = collect($result['points'])->pluck('site_id')->unique()->all();
        foreach ($siteIds as $siteId) {
            AccessSync::nudgeSite((int) $siteId);
        }

        $points = collect($result['points'])
            ->each(fn (AccessPoint $p) => $p->load(['site:id,name', 'unit:id,unit_number,site_id']))
            ->map(fn (AccessPoint $p) => AccessPointResource::make($p)->resolve())
            ->values()
            ->all();

        return $this->created([
            'confirmed_count' => $result['confirmed_count'],
            'points' => $points,
        ], 'Access points bulk-assigned successfully.');
    }

    private function activeAccount(): ?AccessProviderAccount
    {
        return AccessProviderAccount::query()
            ->where('is_active', true)
            ->first();
    }

    private function requireActiveAccount(): AccessProviderAccount
    {
        $account = $this->activeAccount();
        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => ['Connect and activate an access provider first.'],
            ]);
        }

        return $account;
    }
}
