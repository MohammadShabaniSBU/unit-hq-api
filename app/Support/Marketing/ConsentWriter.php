<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\ContactChannelType;
use App\Enums\LogChannel;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sole insert path for the consent ledger. Automations and AI tools may call
 * withdraw() only. grant() stays off ToolRegistry and node handlers.
 */
final class ConsentWriter
{
    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public static function grant(
        Contact $contact,
        Channel $channel,
        SubscriptionList $list,
        ConsentSource $source,
        ?int $consentTextId = null,
        ?array $evidence = null,
        ?Model $causer = null,
        ?Carbon $occurredAt = null,
    ): ConsentEvent {
        self::assertMarketingChannel($channel);
        self::assertListAllows($list, $channel);

        $grantSources = [
            ConsentSource::Form,
            ConsentSource::DoubleOptIn,
            ConsentSource::Operator,
            ConsentSource::Import,
            ConsentSource::PreferenceCenter,
            ConsentSource::SoftOptIn,
        ];

        if (! in_array($source, $grantSources, true)) {
            throw new ConsentRuleException('grant_source');
        }

        if ($source === ConsentSource::Operator) {
            if (! $causer instanceof Employee) {
                throw new ConsentRuleException('operator_causer');
            }

            $note = trim((string) ($evidence['note'] ?? ''));
            if ($note === '') {
                throw new ConsentRuleException('operator_note');
            }
        } elseif ($consentTextId === null) {
            throw new ConsentRuleException('consent_text');
        }

        if ($source === ConsentSource::Import) {
            $batch = trim((string) ($evidence['import_batch'] ?? ''));
            $original = trim((string) ($evidence['original_source'] ?? ''));
            if ($batch === '' || $original === '') {
                throw new ConsentRuleException('import_evidence');
            }
        }

        self::assertTextExists($consentTextId);

        return self::insert(
            $contact,
            $channel,
            $list,
            ConsentAction::Granted,
            $source,
            $consentTextId,
            $evidence,
            $causer,
            $occurredAt,
        );
    }

    /**
     * Signup form waiting on double opt-in. Not exposed on the operator API.
     *
     * @param  array<string, mixed>|null  $evidence
     */
    public static function pending(
        Contact $contact,
        Channel $channel,
        SubscriptionList $list,
        ConsentSource $source,
        int $consentTextId,
        ?array $evidence = null,
        ?Carbon $occurredAt = null,
    ): ConsentEvent {
        self::assertMarketingChannel($channel);
        self::assertListAllows($list, $channel);

        if ($source !== ConsentSource::Form) {
            throw new ConsentRuleException('pending_source');
        }

        self::assertTextExists($consentTextId);

        return self::insert(
            $contact,
            $channel,
            $list,
            ConsentAction::Pending,
            $source,
            $consentTextId,
            $evidence,
            null,
            $occurredAt,
            logActivity: false,
        );
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public static function withdraw(
        Contact $contact,
        Channel $channel,
        SubscriptionList $list,
        ConsentSource $source,
        ?array $evidence = null,
        ?Model $causer = null,
        ?Carbon $occurredAt = null,
    ): ConsentEvent {
        self::assertMarketingChannel($channel);
        self::assertListAllows($list, $channel, allowArchived: true);

        if ($source === ConsentSource::Operator && ! $causer instanceof Employee) {
            throw new ConsentRuleException('operator_causer');
        }

        return self::insert(
            $contact,
            $channel,
            $list,
            ConsentAction::Withdrawn,
            $source,
            null,
            $evidence,
            $causer,
            $occurredAt,
        );
    }

    /**
     * Withdraw every list that allows this channel, for every contact that owns the address.
     *
     * @param  array<string, mixed>|null  $evidence
     */
    public static function withdrawAllListsForAddress(
        Channel $channel,
        string $address,
        ConsentSource $source,
        ?array $evidence = null,
    ): void {
        self::assertMarketingChannel($channel);

        $contactIds = self::contactIdsForAddress($channel, $address);
        if ($contactIds === []) {
            return;
        }

        $lists = SubscriptionList::query()
            ->whereJsonContains('channels', $channel->value)
            ->get();

        if ($lists->isEmpty()) {
            return;
        }

        $contacts = Contact::query()->whereIn('id', $contactIds)->get()->keyBy('id');

        DB::transaction(function () use ($contacts, $lists, $channel, $source, $evidence): void {
            foreach ($contacts as $contact) {
                foreach ($lists as $list) {
                    self::withdraw($contact, $channel, $list, $source, $evidence);
                }
            }
        });
    }

    public static function redact(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            DB::table('consent_events')
                ->where('contact_id', $contact->id)
                ->whereNotNull('evidence')
                ->update(['evidence' => null]);

            foreach (ConsentState::for($contact) as $row) {
                if ($row['action'] !== ConsentAction::Granted->value) {
                    continue;
                }

                $list = SubscriptionList::query()->find($row['list']['id']);
                $channel = Channel::tryFrom($row['channel']);
                if ($list === null || $channel === null) {
                    continue;
                }

                self::withdraw($contact, $channel, $list, ConsentSource::Redaction);
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    private static function insert(
        Contact $contact,
        Channel $channel,
        SubscriptionList $list,
        ConsentAction $action,
        ConsentSource $source,
        ?int $consentTextId,
        ?array $evidence,
        ?Model $causer,
        ?Carbon $occurredAt,
        bool $logActivity = true,
    ): ConsentEvent {
        $event = ConsentEvent::query()->create([
            'contact_id' => $contact->id,
            'channel' => ConsentChannel::fromComms($channel),
            'subscription_list_id' => $list->id,
            'action' => $action,
            'source' => $source,
            'consent_text_id' => $consentTextId,
            'evidence' => $evidence,
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            'occurred_at' => $occurredAt ?? now(),
        ]);

        Cache::forget(SubscriptionList::memberCountCacheKey($list->id));

        if ($logActivity && $action !== ConsentAction::Pending) {
            $description = $action === ConsentAction::Granted
                ? 'contact.consent.granted'
                : 'contact.consent.withdrawn';

            RecordsActivity::log(
                LogChannel::Comms,
                $description,
                $contact,
                [
                    'channel' => $channel->value,
                    'subscription_list_id' => $list->id,
                    'source' => $source->value,
                    'consent_text_id' => $consentTextId,
                    'consent_event_id' => $event->id,
                ],
                $causer,
                anonymous: $causer === null,
            );
        }

        return $event;
    }

    private static function assertMarketingChannel(Channel $channel): void
    {
        if (! in_array($channel, [Channel::Email, Channel::Sms, Channel::Whatsapp], true)) {
            throw new ConsentRuleException('channel');
        }
    }

    private static function assertListAllows(SubscriptionList $list, Channel $channel, bool $allowArchived = false): void
    {
        if (! $allowArchived && $list->archived_at !== null) {
            throw new ConsentRuleException('list_archived');
        }

        if (! $list->allows($channel)) {
            throw new ConsentRuleException('list_channel');
        }
    }

    private static function assertTextExists(?int $consentTextId): void
    {
        if ($consentTextId === null) {
            return;
        }

        if (! ConsentText::query()->whereKey($consentTextId)->exists()) {
            throw new ConsentRuleException('text_missing');
        }
    }

    /**
     * @return list<int>
     */
    public static function contactIdsForAddress(Channel $channel, string $address): array
    {
        $normalized = ContactChannelMatcher::normalize($channel, $address);
        if ($normalized === '') {
            return [];
        }

        $ids = [];

        if ($channel === Channel::Email) {
            $ids = Contact::query()
                ->whereNotNull('email')
                ->whereRaw('lower(trim(email)) = ?', [$normalized])
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $types = match ($channel) {
            Channel::Email => [ContactChannelType::Email],
            Channel::Sms => [ContactChannelType::Phone, ContactChannelType::Sms],
            Channel::Whatsapp => [ContactChannelType::Whatsapp],
            Channel::Call => [],
        };

        if ($types !== []) {
            $typeValues = array_map(static fn (ContactChannelType $type): string => $type->value, $types);

            $channels = ContactChannel::query()
                ->whereIn('type', $typeValues)
                ->get(['contact_id', 'value']);

            foreach ($channels as $row) {
                if (ContactChannelMatcher::normalize($channel, (string) $row->value) === $normalized) {
                    $ids[] = (int) $row->contact_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
