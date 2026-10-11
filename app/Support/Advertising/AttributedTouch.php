<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use App\Models\MarketingTouch;
use Carbon\CarbonImmutable;

/**
 * Last paid touch inside a window, computed per query. Nothing is stored.
 * A paid touch has a non-null platform of google_ads or meta (or the
 * requested platform). No match means organic.
 */
final class AttributedTouch
{
    public const DEFAULT_WINDOW_DAYS = 90;

    /**
     * @param  list<array{id: int, contact_id: int|null, anchor: CarbonImmutable}>  $subjects
     * @return array<int, MarketingTouch|null> keyed by subject id
     */
    public static function latestPaid(array $subjects, int $windowDays, ?AdPlatform $platform): array
    {
        $result = [];
        foreach ($subjects as $subject) {
            $result[$subject['id']] = null;
        }

        if ($subjects === []) {
            return $result;
        }

        $platforms = $platform !== null
            ? [$platform->value]
            : [AdPlatform::GoogleAds->value, AdPlatform::Meta->value];

        $contactIds = [];
        $min = null;
        $max = null;
        foreach ($subjects as $subject) {
            if ($subject['contact_id'] === null) {
                continue;
            }

            $contactIds[] = $subject['contact_id'];
            $start = $subject['anchor']->subDays($windowDays);
            if ($min === null || $start->lt($min)) {
                $min = $start;
            }
            if ($max === null || $subject['anchor']->gt($max)) {
                $max = $subject['anchor'];
            }
        }

        $contactIds = array_values(array_unique($contactIds));
        if ($contactIds === [] || $min === null || $max === null) {
            return $result;
        }

        $touches = MarketingTouch::query()
            ->whereIn('contact_id', $contactIds)
            ->whereIn('platform', $platforms)
            ->where('occurred_at', '>=', $min->toDateTimeString())
            ->where('occurred_at', '<=', $max->toDateTimeString())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        /** @var array<int, list<MarketingTouch>> $byContact */
        $byContact = [];
        foreach ($touches as $touch) {
            $contactId = (int) $touch->contact_id;
            $byContact[$contactId][] = $touch;
        }

        foreach ($subjects as $subject) {
            if ($subject['contact_id'] === null) {
                continue;
            }

            $anchor = $subject['anchor'];
            $windowStart = $anchor->subDays($windowDays);
            foreach ($byContact[$subject['contact_id']] ?? [] as $touch) {
                $at = CarbonImmutable::parse($touch->occurred_at);
                if ($at->greaterThan($anchor) || $at->lessThan($windowStart)) {
                    continue;
                }

                $result[$subject['id']] = $touch;
                break;
            }
        }

        return $result;
    }
}
