<?php

namespace App\Http\Controllers\Facility;

use App\Enums\AttributeEntityType;
use App\Enums\UnitState;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Support\Auth\Permission;
use App\Support\Billing\OverdueContracts;
use App\Support\Occupancy\Availability;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    use SearchesWithFilters;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'site_id'      => ['nullable', 'integer', 'exists:sites,id'],
            'for_map'      => ['nullable', 'boolean'],
            'available'    => ['nullable', 'boolean'],
            'available_on' => ['nullable', 'date'],
            'state'        => ['nullable', Rule::enum(UnitState::class)],
            'state_group'  => ['nullable', 'in:out_of_service'],
        ]);

        $query = Unit::query()
            ->visibleTo($employee, Permission::UnitView)
            ->with(['site', 'unitClass'])
            ->latest();

        if (! empty($validated['site_id'])) {
            $query->where('site_id', $validated['site_id']);
        }

        $explicitOn = ! empty($validated['available_on'])
            ? CarbonImmutable::parse($validated['available_on'])->startOfDay()
            : null;

        if ($request->boolean('available') || $explicitOn !== null) {
            if ($explicitOn !== null) {
                Availability::scopeAvailableOn($query, $explicitOn);
            } else {
                Availability::scopeAvailableTodayPerSite($query);
            }
        }

        $this->applyStateFilter($query, $validated, $explicitOn);

        $hydrateOn = $explicitOn;

        if ($request->boolean('for_map') && ! empty($validated['site_id'])) {
            $units = $query->get();
            Availability::hydrateState($units, $hydrateOn);
            $this->hydrateMapOverdue($units);

            return $this->success(
                $units->map(fn (Unit $unit) => UnitResource::make($unit)),
                'Units retrieved successfully.',
            );
        }

        $paginator = $query->paginate($this->perPage());
        Availability::hydrateState(
            collect($paginator->items()),
            $hydrateOn,
        );

        return $this->paginated(
            $paginator->through(fn (Unit $unit) => UnitResource::make($unit)),
            'Units retrieved successfully.',
        );
    }

    public function filterSchema(): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value);

        return $this->respondFilterSchema(AttributeEntityType::Unit);
    }

    public function search(Request $request): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        return $this->searchWithFilters(
            $request,
            AttributeEntityType::Unit,
            Unit::query()->visibleTo($employee, Permission::UnitView)->with(['site', 'unitClass']),
            function (Unit $unit) {
                return UnitResource::make($unit);
            },
            'Units retrieved successfully.',
            function ($query, Request $request): void {
                if ($request->filled('site_id')) {
                    $query->where('site_id', $request->integer('site_id'));
                }

                $stateValidated = $request->validate([
                    'state' => ['nullable', Rule::enum(UnitState::class)],
                    'state_group' => ['nullable', 'in:out_of_service'],
                ]);

                $this->applyStateFilter($query, $stateValidated, null);
            },
            function ($units): void {
                Availability::hydrateState(collect($units));
            },
        );
    }

    /**
     * @param  Builder<Unit>  $query
     * @param  array{state?: mixed, state_group?: string|null}  $validated
     */
    private function applyStateFilter(Builder $query, array $validated, ?CarbonImmutable $explicitOn): void
    {
        if (! empty($validated['state_group'])) {
            if ($explicitOn !== null) {
                Availability::scopeStateGroupOn($query, (string) $validated['state_group'], $explicitOn);
            } else {
                Availability::scopeStateGroupTodayPerSite($query, (string) $validated['state_group']);
            }

            return;
        }

        if (empty($validated['state'])) {
            return;
        }

        $state = $validated['state'] instanceof UnitState
            ? $validated['state']
            : UnitState::from((string) $validated['state']);

        if ($explicitOn !== null) {
            Availability::scopeStateOn($query, $state, $explicitOn);
        } else {
            Availability::scopeStateTodayPerSite($query, $state);
        }
    }

    /**
     * Stamp derived is_overdue on each unit (query-time only — never stored).
     *
     * @param  \Illuminate\Support\Collection<int, Unit>|\Illuminate\Database\Eloquent\Collection<int, Unit>  $units
     */
    private function hydrateMapOverdue($units): void
    {
        $contractIds = [];

        foreach ($units as $unit) {
            if (! $unit->relationLoaded('coveringOccupancy')) {
                continue;
            }

            $occupancy = $unit->getRelation('coveringOccupancy');
            if ($occupancy !== null && $occupancy->contract_id !== null) {
                $contractIds[] = (int) $occupancy->contract_id;
            }
        }

        $overdueIds = array_fill_keys(
            OverdueContracts::idsAmong(array_values(array_unique($contractIds))),
            true,
        );

        foreach ($units as $unit) {
            $occupancy = $unit->relationLoaded('coveringOccupancy')
                ? $unit->getRelation('coveringOccupancy')
                : null;
            $contractId = $occupancy?->contract_id !== null
                ? (int) $occupancy->contract_id
                : null;

            $unit->setAttribute(
                'is_overdue',
                $contractId !== null && isset($overdueIds[$contractId]),
            );
        }
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::UnitManage->value);

        $validated = $request->validate([
            'site_id'       => ['required', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['required', 'integer', 'exists:unit_classes,id'],
            'unit_number'   => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'unit_number')->where('site_id', $request->integer('site_id')),
            ],
            'actual_width'  => ['nullable', 'numeric', 'min:0'],
            'actual_depth'  => ['nullable', 'numeric', 'min:0'],
            'actual_height' => ['nullable', 'numeric', 'min:0'],
            'note'          => ['nullable', 'string'],
            'enabled'       => ['nullable', 'boolean'],
        ]);

        $unit = Unit::query()->create($validated);

        return $this->created(
            UnitResource::make($unit),
            'Unit created successfully.'
        );
    }

    public function show(Unit $unit): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value, $unit);

        $unit->load(['site', 'unitClass', 'currentOccupancy.contract.contact', 'currentOccupancy.contract.items']);
        Availability::hydrateState(collect([$unit]));

        return $this->success(
            UnitResource::make($unit),
            'Unit retrieved successfully.'
        );
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        Gate::authorize(Permission::UnitManage->value, $unit);

        $siteId = $request->filled('site_id') ? $request->integer('site_id') : $unit->site_id;

        $validated = $request->validate([
            'site_id'       => ['sometimes', 'required', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['sometimes', 'required', 'integer', 'exists:unit_classes,id'],
            'unit_number'   => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'unit_number')
                    ->where('site_id', $siteId)
                    ->ignore($unit->id),
            ],
            'actual_width'  => ['nullable', 'numeric', 'min:0'],
            'actual_depth'  => ['nullable', 'numeric', 'min:0'],
            'actual_height' => ['nullable', 'numeric', 'min:0'],
            'note'          => ['nullable', 'string'],
            'enabled'       => ['nullable', 'boolean'],
        ]);

        $unit->update($validated);

        return $this->success(
            UnitResource::make($unit->fresh(['site', 'unitClass'])),
            'Unit updated successfully.'
        );
    }

    public function destroy(Unit $unit): JsonResponse
    {
        Gate::authorize(Permission::UnitManage->value, $unit);

        $unit->delete();

        return $this->noContent('Unit deleted successfully.');
    }

    public function options(Request $request): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['nullable', 'integer', 'exists:unit_classes,id'],
        ]);

        $query = Unit::query()
            ->visibleTo($employee, Permission::UnitView)
            ->with(['site', 'unitClass'])
            ->where('enabled', true)
            ->limit(100);

        if (! empty($validated['site_id'])) {
            $site = Site::query()->findOrFail($validated['site_id']);
            $query->where('site_id', $site->id)
                ->availableOn(SiteClock::today($site));
        } else {
            Availability::scopeAvailableTodayPerSite($query);
        }

        if (! empty($validated['unit_class_id'])) {
            $query->where('unit_class_id', $validated['unit_class_id']);
        }

        $units = $query->get();
        $rateMap = $this->buildUnitClassRateMap($units);

        $options = $units->map(function (Unit $unit) use ($rateMap) {
            $price = $rateMap[$unit->unit_class_id][$unit->site_id] ?? null;

            return [
                'value'          => $unit->id,
                'label'          => $unit->unit_number
                    . ($unit->site ? ' · ' . $unit->site->name : '')
                    . ($unit->unitClass ? ' · ' . $unit->unitClass->label : ''),
                'site_id'        => $unit->site_id,
                'price_amount'   => $price['amount'] ?? null,
                'price_currency' => $price['currency'] ?? null,
            ];
        });

        return $this->success($options, 'Unit options retrieved successfully.');
    }

    /** @param \Illuminate\Support\Collection<int, Unit> $units */
    /** @return array<int, array<int, array{amount: string, currency: string}>> */
    private function buildUnitClassRateMap($units): array
    {
        if ($units->isEmpty()) {
            return [];
        }

        $rateMap = [];

        UnitClassRate::query()
            ->with('price')
            ->whereIn('unit_class_id', $units->pluck('unit_class_id')->unique())
            ->whereIn('site_id', $units->pluck('site_id')->unique())
            ->get()
            ->each(function (UnitClassRate $rate) use (&$rateMap): void {
                if ($rate->price === null) {
                    return;
                }

                $rateMap[$rate->unit_class_id][$rate->site_id] = [
                    'amount'   => $rate->price->amount,
                    'currency' => $rate->price->currency,
                ];
            });

        return $rateMap;
    }
}
