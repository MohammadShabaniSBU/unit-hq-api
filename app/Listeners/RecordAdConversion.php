<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Deal;
use App\Models\Reservation;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionOutbox;
use Carbon\CarbonImmutable;

/**
 * Lead and reservation events. Move-in is recorded from ContractSigning
 * because the value depends on charges written in that same call.
 */
final class RecordAdConversion
{
    public function dealCreated(Deal $deal): void
    {
        $deal->loadMissing(['contact', 'site']);

        if ($deal->contact === null) {
            return;
        }

        ConversionOutbox::record(
            ConversionEventKey::Lead,
            $deal,
            $deal->contact,
            $deal->site,
            null,
            null,
            CarbonImmutable::parse($deal->created_at ?? now()),
        );
    }

    public function reservationCreated(Reservation $reservation): void
    {
        $reservation->loadMissing(['contact', 'unit.site']);

        if ($reservation->contact === null) {
            return;
        }

        ConversionOutbox::record(
            ConversionEventKey::Reservation,
            $reservation,
            $reservation->contact,
            $reservation->unit?->site,
            null,
            null,
            CarbonImmutable::parse($reservation->created_at ?? now()),
        );
    }
}
