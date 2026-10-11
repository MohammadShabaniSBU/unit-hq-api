<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use App\Enums\ContactChannelType;
use App\Models\AdConversionDelivery;
use App\Models\AdConversionEvent;
use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\MarketingTouch;
use App\Models\SystemEvent;
use App\Support\Advertising\Contracts\ReportsConversions;
use App\Support\Advertising\Results\ConversionPayload;
use App\Support\Advertising\Results\DeliveryResult;
use App\Support\Marketing\AdvertisingConsent;
use App\Support\Marketing\ConsentChannel;
use App\Support\Marketing\ConsentState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Materialise, claim, gate, and send conversion deliveries.
 * One row per account and event. Claim before send.
 */
final class ConversionDelivery
{
    public const STALE_CLAIM_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    private const GOOGLE_BATCH = 2000;

    private const META_BATCH = 1000;

    private ?int $advertisingListId = null;

    private bool $listResolved = false;

    public function run(): void
    {
        $this->abandonStaleClaims();
        $this->materialise();

        $accountIds = DB::table('ad_conversion_deliveries')
            ->where('status', AdDeliveryStatus::Pending->value)
            ->distinct()
            ->orderBy('ad_platform_account_id')
            ->pluck('ad_platform_account_id');

        foreach ($accountIds as $accountId) {
            $account = AdPlatformAccount::query()->find((int) $accountId);
            if ($account === null) {
                continue;
            }

            $this->deliverAccount($account);
        }
    }

    private function abandonStaleClaims(): void
    {
        $cutoff = now()->subMinutes(self::STALE_CLAIM_MINUTES);

        AdConversionDelivery::query()
            ->where('status', AdDeliveryStatus::Claimed)
            ->where('claimed_at', '<', $cutoff)
            ->orderBy('id')
            ->each(function (AdConversionDelivery $delivery): void {
                $delivery->status = AdDeliveryStatus::Failed;
                $delivery->error = 'claim_abandoned';
                $delivery->processed_at = now();
                $delivery->save();
            });
    }

    private function materialise(): void
    {
        $rows = DB::table('ad_conversion_events as e')
            ->join('ad_conversion_mappings as m', function ($join): void {
                $join->on('m.event_key', '=', 'e.event_key')->where('m.is_active', true);
            })
            ->join('ad_platform_accounts as a', function ($join): void {
                $join->on('a.id', '=', 'm.ad_platform_account_id')
                    ->where('a.is_active', true)
                    ->whereNull('a.archived_at');
            })
            ->select('e.id as ad_conversion_event_id', 'a.id as ad_platform_account_id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'ad_conversion_event_id' => (int) $row->ad_conversion_event_id,
                'ad_platform_account_id' => (int) $row->ad_platform_account_id,
                'status' => AdDeliveryStatus::Pending->value,
                'identifiers_used' => '[]',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('ad_conversion_deliveries')->insertOrIgnore($chunk);
        }
    }

    private function deliverAccount(AdPlatformAccount $account): void
    {
        $batchSize = match ($account->provider) {
            AdPlatform::GoogleAds => self::GOOGLE_BATCH,
            AdPlatform::Meta => self::META_BATCH,
            AdPlatform::OpenAi => 0,
        };

        if ($batchSize === 0) {
            return;
        }

        do {
            $ids = $this->claim((int) $account->id, $batchSize);
            if ($ids === []) {
                return;
            }

            $this->sendClaimed($account, $ids);
        } while (true);
    }

    /**
     * @return list<int>
     */
    private function claim(int $accountId, int $limit): array
    {
        $ids = DB::table('ad_conversion_deliveries')
            ->where('ad_platform_account_id', $accountId)
            ->where('status', AdDeliveryStatus::Pending->value)
            ->where(function ($query): void {
                $query->where('attempts', 0)
                    ->orWhere(function ($query): void {
                        $query->where('attempts', 1)->where('processed_at', '<=', now()->subMinute());
                    })
                    ->orWhere(function ($query): void {
                        $query->where('attempts', 2)->where('processed_at', '<=', now()->subMinutes(5));
                    })
                    ->orWhere(function ($query): void {
                        $query->where('attempts', 3)->where('processed_at', '<=', now()->subMinutes(30));
                    })
                    ->orWhere(function ($query): void {
                        $query->where('attempts', 4)->where('processed_at', '<=', now()->subMinutes(120));
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $now = now()->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, $ids->count(), '?'));
        $bindings = array_merge(
            [AdDeliveryStatus::Claimed->value, $now, $now],
            $ids->all(),
            [AdDeliveryStatus::Pending->value],
        );

        $claimed = DB::select(
            'UPDATE ad_conversion_deliveries SET status = ?, claimed_at = ?, updated_at = ?'
            .' WHERE id IN ('.$placeholders.') AND status = ? RETURNING id',
            $bindings,
        );

        return array_map(static fn (object $row): int => (int) $row->id, $claimed);
    }

    /**
     * @param  list<int>  $ids
     */
    private function sendClaimed(AdPlatformAccount $account, array $ids): void
    {
        $deliveries = AdConversionDelivery::query()
            ->with(['event.contact.channels', 'account'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        /** @var list<array{delivery: AdConversionDelivery, payload: ConversionPayload, names: list<string>}> $ready */
        $ready = [];

        foreach ($deliveries as $delivery) {
            $built = $this->prepare($account, $delivery);
            if ($built === null) {
                continue;
            }

            $ready[] = $built;
        }

        if ($ready === []) {
            return;
        }

        $payloads = array_map(static fn (array $row): ConversionPayload => $row['payload'], $ready);

        try {
            $adapter = app(AdPlatformRegistry::class)->forAccount($account);
            if (! $adapter instanceof ReportsConversions) {
                throw new AdPlatformException('unsupported', false);
            }

            $results = $adapter->deliver($payloads);
        } catch (AdPlatformException $exception) {
            SystemEvent::record('ads.delivery.batch_failed', $account, [
                'account_id' => $account->id,
                'count' => count($ready),
                'error_class' => $exception::class,
            ]);

            foreach ($ready as $row) {
                $this->failOrRetry($row['delivery'], $exception->retryable, class_basename($exception), null, $row['names']);
            }

            return;
        } catch (Throwable $exception) {
            SystemEvent::record('ads.delivery.batch_failed', $account, [
                'account_id' => $account->id,
                'count' => count($ready),
                'error_class' => $exception::class,
            ]);

            foreach ($ready as $row) {
                $this->failOrRetry($row['delivery'], false, class_basename($exception), null, $row['names']);
            }

            return;
        }

        $byDedupe = [];
        foreach ($results as $result) {
            $byDedupe[$result->dedupeId] = $result;
        }

        $failed = 0;
        foreach ($ready as $row) {
            $result = $byDedupe[$row['payload']->dedupeId] ?? new DeliveryResult(
                dedupeId: $row['payload']->dedupeId,
                ok: false,
                retryable: true,
                error: 'missing_result',
            );

            if ($result->ok) {
                $this->markSent($row['delivery'], $result, $row['names']);

                continue;
            }

            $failed++;
            $this->failOrRetry(
                $row['delivery'],
                $result->retryable,
                $result->error ?? 'delivery_failed',
                $result->providerRequestId,
                $row['names'],
            );
        }

        if ($failed > 0) {
            SystemEvent::record('ads.delivery.partial_failure', $account, [
                'account_id' => $account->id,
                'count' => $failed,
            ]);
        }
    }

    /**
     * @return array{delivery: AdConversionDelivery, payload: ConversionPayload, names: list<string>}|null
     */
    private function prepare(AdPlatformAccount $account, AdConversionDelivery $delivery): ?array
    {
        $event = $delivery->event;
        $contact = $event?->contact;

        if ($event === null || $contact === null) {
            $this->markSkipped($delivery, AdDeliverySkipReason::NoIdentifier, null, []);

            return null;
        }

        if (! $account->is_active || $account->archived_at !== null) {
            $this->markSkipped($delivery, AdDeliverySkipReason::AccountInactive, null, []);

            return null;
        }

        $mapping = AdConversionMapping::query()
            ->where('ad_platform_account_id', $account->id)
            ->where('event_key', $event->event_key)
            ->first();

        if ($mapping === null || ! $mapping->is_active) {
            $this->markSkipped($delivery, AdDeliverySkipReason::MappingInactive, null, []);

            return null;
        }

        $listId = $this->advertisingListId();
        if ($listId === null || ! ConsentState::isGranted($contact->id, ConsentChannel::Ads, $listId)) {
            $this->markSkipped($delivery, AdDeliverySkipReason::NoConsent, null, []);

            return null;
        }

        $touch = $this->chooseTouch($contact, $account->provider, CarbonImmutable::parse($event->occurred_at));
        $built = $this->buildPayload($account, $event, $contact, $touch, $mapping);

        if ($built['skip'] !== null) {
            $this->markSkipped($delivery, $built['skip'], $touch?->id, []);

            return null;
        }

        $delivery->marketing_touch_id = $touch?->id;

        return [
            'delivery' => $delivery,
            'payload' => $built['payload'],
            'names' => $built['names'],
        ];
    }

    /**
     * @return array{payload: ConversionPayload, names: list<string>, skip: ?AdDeliverySkipReason}
     */
    private function buildPayload(
        AdPlatformAccount $account,
        AdConversionEvent $event,
        Contact $contact,
        ?MarketingTouch $touch,
        AdConversionMapping $mapping,
    ): array {
        $emailHash = $this->emailHash($contact);
        $phoneHash = $this->phoneHash($contact);
        $clickIds = is_array($touch?->click_ids) ? $touch->click_ids : [];
        $occurredAt = CarbonImmutable::parse($event->occurred_at);

        if ($account->provider === AdPlatform::GoogleAds) {
            return $this->googlePayload($account, $event, $mapping, $touch, $clickIds, $emailHash, $phoneHash, $occurredAt);
        }

        return $this->metaPayload($event, $mapping, $contact, $clickIds, $emailHash, $phoneHash, $occurredAt);
    }

    /**
     * @param  array<string, mixed>  $clickIds
     * @return array{payload: ConversionPayload, names: list<string>, skip: ?AdDeliverySkipReason}
     */
    private function googlePayload(
        AdPlatformAccount $account,
        AdConversionEvent $event,
        AdConversionMapping $mapping,
        ?MarketingTouch $touch,
        array $clickIds,
        ?string $emailHash,
        ?string $phoneHash,
        CarbonImmutable $occurredAt,
    ): array {
        $click = $this->firstClick($clickIds, ['gclid', 'gbraid', 'wbraid']);
        if ($click === null) {
            return $this->skipped(AdDeliverySkipReason::NoIdentifier);
        }

        if ($touch !== null && CarbonImmutable::parse($touch->occurred_at)->lt($occurredAt->subDays(90))) {
            return $this->skipped(AdDeliverySkipReason::ClickTooOld);
        }

        $names = [$click['name']];
        $userIdentifiers = [];
        if ($emailHash !== null) {
            $userIdentifiers[] = ['hashedEmail' => $emailHash];
            $names[] = 'hashed_email';
        }
        if ($phoneHash !== null) {
            $userIdentifiers[] = ['hashedPhoneNumber' => $phoneHash];
            $names[] = 'hashed_phone_number';
        }

        $personalization = ($touch?->consent_snapshot['ad_personalization'] ?? false) === true
            ? 'GRANTED'
            : 'DENIED';

        [$value, $currency] = $this->valueFor($event, $mapping);

        return [
            'payload' => new ConversionPayload(
                dedupeId: $event->dedupe_id,
                externalRef: $mapping->external_ref,
                gclid: $click['name'] === 'gclid' ? $click['value'] : null,
                gbraid: $click['name'] === 'gbraid' ? $click['value'] : null,
                wbraid: $click['name'] === 'wbraid' ? $click['value'] : null,
                conversionDateTime: $this->googleDateTime($occurredAt, $account->timezone),
                value: $value,
                currency: $currency,
                userIdentifiers: $userIdentifiers,
                adUserData: 'GRANTED',
                adPersonalization: $personalization,
            ),
            'names' => $names,
            'skip' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $clickIds
     * @return array{payload: ConversionPayload, names: list<string>, skip: ?AdDeliverySkipReason}
     */
    private function metaPayload(
        AdConversionEvent $event,
        AdConversionMapping $mapping,
        Contact $contact,
        array $clickIds,
        ?string $emailHash,
        ?string $phoneHash,
        CarbonImmutable $occurredAt,
    ): array {
        $names = [];
        $userData = [];
        $fbc = $this->stringClick($clickIds, 'fbc');
        $fbp = $this->stringClick($clickIds, 'fbp');

        if ($emailHash !== null) {
            $userData['em'] = [$emailHash];
            $names[] = 'em';
        }
        if ($phoneHash !== null) {
            $userData['ph'] = [$phoneHash];
            $names[] = 'ph';
        }
        if ($fbc !== null) {
            $userData['fbc'] = $fbc;
            $names[] = 'fbc';
        }
        if ($fbp !== null) {
            $userData['fbp'] = $fbp;
            $names[] = 'fbp';
        }

        if ($names === []) {
            return $this->skipped(AdDeliverySkipReason::NoIdentifier);
        }

        if ($occurredAt->lt(CarbonImmutable::now()->subDays(7))) {
            return $this->skipped(AdDeliverySkipReason::EventTooOld);
        }

        $userData['external_id'] = [Hashing::sha256('contact:'.$contact->id)];
        $names[] = 'external_id';

        [$value, $currency] = $this->valueFor($event, $mapping);
        $custom = null;
        if ($value !== null && $currency !== null) {
            $custom = ['value' => $value, 'currency' => $currency];
        }

        return [
            'payload' => new ConversionPayload(
                dedupeId: $event->dedupe_id,
                externalRef: $mapping->external_ref,
                eventTime: $occurredAt->getTimestamp(),
                metaUserData: $userData,
                metaCustomData: $custom,
            ),
            'names' => $names,
            'skip' => null,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function valueFor(AdConversionEvent $event, AdConversionMapping $mapping): array
    {
        if (
            $event->event_key !== ConversionEventKey::MoveIn
            || $mapping->value_rule !== ConversionValueRule::FirstPeriodNet
            || $event->value === null
            || $event->currency === null
        ) {
            return [null, null];
        }

        return [(string) $event->value, (string) $event->currency];
    }

    /**
     * @param  list<string>  $names
     */
    private function markSkipped(
        AdConversionDelivery $delivery,
        AdDeliverySkipReason $reason,
        ?int $touchId,
        array $names,
    ): void {
        $delivery->status = AdDeliveryStatus::Skipped;
        $delivery->skip_reason = $reason;
        $delivery->marketing_touch_id = $touchId;
        $delivery->identifiers_used = $names;
        $delivery->processed_at = now();
        $delivery->save();
    }

    /**
     * @param  list<string>  $names
     */
    private function markSent(AdConversionDelivery $delivery, DeliveryResult $result, array $names): void
    {
        $delivery->status = AdDeliveryStatus::Sent;
        $delivery->identifiers_used = $names;
        $delivery->processed_at = now();
        $delivery->error = null;
        $delivery->provider_response = $this->providerResponse($result->providerRequestId, null);
        $delivery->save();
    }

    /**
     * @param  list<string>  $names
     */
    private function failOrRetry(
        AdConversionDelivery $delivery,
        bool $retryable,
        string $error,
        ?string $requestId,
        array $names,
    ): void {
        $error = mb_substr($error, 0, 255);
        $next = (int) $delivery->attempts + 1;
        $delivery->identifiers_used = $names;
        $delivery->provider_response = $this->providerResponse($requestId, $error);
        $delivery->error = $error;
        $delivery->processed_at = now();
        $delivery->attempts = $next;

        if (! $retryable || $next >= self::MAX_ATTEMPTS) {
            $delivery->status = AdDeliveryStatus::Failed;
            $delivery->save();

            return;
        }

        $delivery->status = AdDeliveryStatus::Pending;
        $delivery->claimed_at = null;
        $delivery->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function providerResponse(?string $requestId, ?string $code): array
    {
        $response = [];
        if ($requestId !== null && $requestId !== '') {
            $response['request_id'] = $requestId;
        }
        if ($code !== null && $code !== '') {
            $response['codes'] = [$code];
        }

        return $response;
    }

    private function chooseTouch(Contact $contact, AdPlatform $platform, CarbonImmutable $occurredAt): ?MarketingTouch
    {
        return MarketingTouch::query()
            ->where('contact_id', $contact->id)
            ->where('platform', $platform->value)
            ->where('occurred_at', '<=', $occurredAt)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    private function emailHash(Contact $contact): ?string
    {
        $email = trim((string) ($contact->email ?? ''));
        if ($email === '') {
            $channel = $contact->channels
                ->where('type', ContactChannelType::Email)
                ->sortByDesc(fn (ContactChannel $channel): int => $channel->is_primary ? 1 : 0)
                ->first();
            $email = trim((string) ($channel?->value ?? ''));
        }

        if ($email === '') {
            return null;
        }

        $normalised = Hashing::normaliseEmail($email);

        return $normalised === '' ? null : Hashing::sha256($normalised);
    }

    private function phoneHash(Contact $contact): ?string
    {
        $rank = [
            ContactChannelType::Phone->value => 0,
            ContactChannelType::Sms->value => 1,
            ContactChannelType::Whatsapp->value => 2,
        ];

        $channel = $contact->channels
            ->filter(fn (ContactChannel $row): bool => isset($rank[$row->type->value]))
            ->sort(function (ContactChannel $left, ContactChannel $right) use ($rank): int {
                $byType = $rank[$left->type->value] <=> $rank[$right->type->value];
                if ($byType !== 0) {
                    return $byType;
                }

                return ($right->is_primary ? 1 : 0) <=> ($left->is_primary ? 1 : 0);
            })
            ->first();

        if ($channel === null) {
            return null;
        }

        $normalised = Hashing::normalisePhoneE164((string) $channel->value);

        return $normalised === '' ? null : Hashing::sha256($normalised);
    }

    /**
     * @param  array<string, mixed>  $clickIds
     * @param  list<string>  $keys
     * @return array{name: string, value: string}|null
     */
    private function firstClick(array $clickIds, array $keys): ?array
    {
        foreach ($keys as $key) {
            $value = $this->stringClick($clickIds, $key);
            if ($value !== null) {
                return ['name' => $key, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clickIds
     */
    private function stringClick(array $clickIds, string $key): ?string
    {
        $value = $clickIds[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function googleDateTime(CarbonImmutable $occurredAt, ?string $timezone): string
    {
        $zone = $timezone !== null && $timezone !== '' ? $timezone : 'UTC';

        try {
            return $occurredAt->timezone($zone)->format('Y-m-d H:i:sP');
        } catch (Throwable) {
            return $occurredAt->utc()->format('Y-m-d H:i:sP');
        }
    }

    private function advertisingListId(): ?int
    {
        if (! $this->listResolved) {
            $this->advertisingListId = AdvertisingConsent::list()?->id;
            $this->listResolved = true;
        }

        return $this->advertisingListId;
    }

    /**
     * @return array{payload: ConversionPayload, names: list<string>, skip: AdDeliverySkipReason}
     */
    private function skipped(AdDeliverySkipReason $reason): array
    {
        return [
            'payload' => new ConversionPayload(dedupeId: '', externalRef: ''),
            'names' => [],
            'skip' => $reason,
        ];
    }
}
