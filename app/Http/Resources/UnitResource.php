<?php

namespace App\Http\Resources;

use App\Enums\UnitState;
use App\Enums\UnitStatus;
use App\Models\Unit;
use App\Support\Time\SiteClock;
use Illuminate\Http\Request;

class UnitResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $unit = $this->resource instanceof Unit ? $this->resource : null;
        $state = $this->resolveState($unit);
        $occupancy = $unit?->relationLoaded('coveringOccupancy')
            ? $unit->getRelation('coveringOccupancy')
            : ($unit?->relationLoaded('currentOccupancy') ? $unit->currentOccupancy : null);
        $hold = $unit?->relationLoaded('coveringHold')
            ? $unit->getRelation('coveringHold')
            : null;

        $payload = [
            'id'            => $this->id,
            'site_id'       => $this->site_id,
            'unit_class_id' => $this->unit_class_id,
            'unit_number'   => $this->unit_number,
            'actual_width'  => $this->actual_width,
            'actual_depth'  => $this->actual_depth,
            'actual_height' => $this->actual_height,
            'note'          => $this->note,
            'enabled'       => $this->enabled,
            'status'        => $this->resolveLegacyStatus($unit, $state),
            'state'         => $state?->value,
            'current_occupancy_id' => $state === UnitState::Occupied ? $occupancy?->id : null,
            'current_hold_id' => ($state !== null && $state !== UnitState::Occupied && $state !== UnitState::Available)
                ? $hold?->id
                : null,
            'current_occupancy' => $this->when(
                $occupancy !== null || ($unit?->relationLoaded('currentOccupancy') ?? false),
                fn () => $occupancy !== null
                    ? [
                        'contract_id' => $occupancy->contract_id,
                        'started_on'  => $this->date($occupancy->started_on),
                    ]
                    : null,
            ),
            'current_hold' => $this->when(
                $hold !== null && $state !== null && $state !== UnitState::Occupied && $state !== UnitState::Available,
                fn () => [
                    'id'         => $hold->id,
                    'hold_type'  => $hold->hold_type instanceof \BackedEnum
                        ? $hold->hold_type->value
                        : $hold->hold_type,
                    'starts_on'  => $this->date($hold->starts_on),
                    'ends_on'    => $this->date($hold->ends_on),
                    'reason'     => $hold->reason,
                    'created_by' => $hold->created_by,
                ],
            ),
            'created_at'    => $this->datetime($this->created_at),
            'updated_at'    => $this->datetime($this->updated_at),
            'site'          => SiteResource::make($this->whenLoaded('site')),
            'unit_class'    => UnitClassResource::make($this->whenLoaded('unitClass')),
        ];

        if ($state === UnitState::Occupied && $occupancy !== null) {
            $contract = $occupancy->relationLoaded('contract') ? $occupancy->contract : null;
            $contact = $contract?->relationLoaded('contact') ? $contract->contact : null;
            $unitItem = $contract?->relationLoaded('items')
                ? $contract->items->firstWhere('item_type', 'unit')
                : null;

            $payload['tenant_name'] = $contact !== null
                ? trim($contact->first_name.' '.$contact->last_name)
                : null;
            $payload['contract_id'] = $occupancy->contract_id;
            $payload['amount'] = $unitItem?->amount;
            $payload['currency'] = $unitItem?->currency ?? $contract?->currency;
        }

        return $payload;
    }

    private function resolveState(?Unit $unit): ?UnitState
    {
        if ($unit === null) {
            return null;
        }

        if (isset($unit->derived_state)) {
            return UnitState::from((string) $unit->derived_state);
        }

        $unit->loadMissing('site');

        return $unit->stateOn(SiteClock::today($unit->site));
    }

    private function resolveLegacyStatus(?Unit $unit, ?UnitState $state): ?string
    {
        if ($unit === null) {
            return null;
        }

        if (! $unit->enabled) {
            return UnitStatus::Archived->value;
        }

        return match ($state) {
            UnitState::Occupied => UnitStatus::Occupied->value,
            UnitState::Reserved => UnitStatus::Reserved->value,
            default => UnitStatus::Free->value,
        };
    }
}
