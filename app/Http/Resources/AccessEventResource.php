<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AccessEventType;
use App\Enums\AccessPointType;
use App\Enums\HoldType;
use App\Models\AccessEvent;
use App\Models\AccessSuspension;
use App\Models\UnitHold;
use App\Support\Time\SiteClock;
use Illuminate\Http\Request;

class AccessEventResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AccessEvent $event */
        $event = $this->resource;

        $point = $event->accessPoint;
        $contact = $event->contact;
        $type = $event->event_type instanceof AccessEventType
            ? $event->event_type->value
            : (string) $event->event_type;

        return [
            'id' => $event->id,
            'occurred_at' => $this->datetime($event->occurred_at),
            'event_type' => $type,
            'access_point_id' => $event->access_point_id,
            'point_label' => $point?->label,
            'provider_point_id' => $event->provider_point_id,
            'site_id' => $point?->site_id,
            'unit_id' => $point?->unit_id,
            'contact_id' => $event->contact_id,
            'contact_name' => $contact !== null
                ? trim($contact->first_name.' '.$contact->last_name)
                : null,
            'provider_credential_ref' => $event->provider_credential_ref,
            'access_grant_id' => $event->access_grant_id,
            'restriction_context' => $this->restrictionContext($event, $type),
        ];
    }

    private function restrictionContext(AccessEvent $event, string $type): ?string
    {
        if ($type !== AccessEventType::Denied->value) {
            return null;
        }

        $grant = $event->relationLoaded('accessGrant')
            ? $event->accessGrant
            : $event->accessGrant()->first();

        if ($grant !== null) {
            $suspended = AccessSuspension::query()
                ->where('contract_id', $grant->contract_id)
                ->where('created_at', '<=', $event->occurred_at)
                ->where(function ($q) use ($event): void {
                    $q->whereNull('lifted_at')
                        ->orWhere('lifted_at', '>', $event->occurred_at);
                })
                ->exists();

            if ($suspended) {
                return 'suspended';
            }
        }

        $point = $event->accessPoint;
        if (
            $point !== null
            && $point->point_type === AccessPointType::UnitDoor
            && $point->unit_id !== null
        ) {
            $point->loadMissing('site');
            $day = $event->occurred_at !== null
                ? $event->occurred_at->toDateString()
                : ($point->site !== null
                    ? SiteClock::today($point->site)->format('Y-m-d')
                    : now()->toDateString());

            $overlocked = UnitHold::query()
                ->where('unit_id', $point->unit_id)
                ->where('hold_type', HoldType::Overlock->value)
                ->where('starts_on', '<=', $day)
                ->where(function ($q) use ($day): void {
                    $q->whereNull('ends_on')->orWhere('ends_on', '>', $day);
                })
                ->where(function ($q) use ($event): void {
                    $q->whereNull('released_at')
                        ->orWhere('released_at', '>', $event->occurred_at);
                })
                ->exists();

            if ($overlocked) {
                return 'overlocked';
            }
        }

        return null;
    }
}
