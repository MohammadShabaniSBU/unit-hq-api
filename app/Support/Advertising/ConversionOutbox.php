<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use App\Jobs\DeliverAdConversions;
use App\Models\AdConversionMapping;
use App\Models\Contact;
use App\Models\Site;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Inserts one conversion event inside the caller's transaction.
 * Delivery rows are created later, after commit.
 */
final class ConversionOutbox
{
    public static function record(
        ConversionEventKey $eventKey,
        Model $subject,
        Contact $contact,
        ?Site $site,
        ?string $value,
        ?string $currency,
        CarbonImmutable $occurredAt,
    ): void {
        if (! self::hasActiveMapping($eventKey)) {
            return;
        }

        [$storedValue, $storedCurrency] = self::storedValue($eventKey, $value, $currency);

        $inserted = DB::table('ad_conversion_events')->insertOrIgnore([
            'event_key' => $eventKey->value,
            'dedupe_id' => self::dedupeId($eventKey, $subject),
            'contact_id' => $contact->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'site_id' => $site?->id,
            'value' => $storedValue,
            'currency' => $storedCurrency,
            'occurred_at' => $occurredAt,
            'created_at' => now(),
        ]);

        if ($inserted !== 0 && $inserted !== false) {
            DB::afterCommit(static fn () => DeliverAdConversions::dispatch());
        }
    }

    public static function dedupeId(ConversionEventKey $eventKey, Model $subject): string
    {
        $id = $subject->getKey();

        return match ($eventKey) {
            ConversionEventKey::Lead => 'deal:'.$id.':lead',
            ConversionEventKey::Reservation => 'reservation:'.$id.':created',
            ConversionEventKey::MoveIn => 'contract:'.$id.':move_in',
        };
    }

    private static function hasActiveMapping(ConversionEventKey $eventKey): bool
    {
        return AdConversionMapping::query()
            ->where('event_key', $eventKey)
            ->where('is_active', true)
            ->whereHas('account', fn ($query) => $query->eligible())
            ->exists();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function storedValue(ConversionEventKey $eventKey, ?string $value, ?string $currency): array
    {
        if ($eventKey !== ConversionEventKey::MoveIn || $currency === null || $currency === '') {
            return [null, null];
        }

        $wantsValue = AdConversionMapping::query()
            ->where('event_key', ConversionEventKey::MoveIn)
            ->where('value_rule', ConversionValueRule::FirstPeriodNet)
            ->where('is_active', true)
            ->whereHas('account', fn ($query) => $query->eligible())
            ->exists();

        if (! $wantsValue) {
            return [null, null];
        }

        return [BillingMath::round2($value ?? '0.00'), $currency];
    }
}
