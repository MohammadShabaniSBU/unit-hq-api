<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read and write path for the public preference center and one-click unsubscribe.
 * GET callers must not use the write methods.
 */
final class PreferenceCenter
{
    /**
     * @return array<string, mixed>
     */
    public static function evidence(Request $request, PreferenceClaims $claims): array
    {
        $evidence = [
            'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ];

        if ($claims->messageId !== null) {
            $evidence['message_id'] = $claims->messageId;
        }

        return $evidence;
    }

    /**
     * @return array{contact_first_name: string|null, locale: string|null, lists: list<array<string, mixed>>}
     */
    public static function payload(PreferenceClaims $claims): array
    {
        $contacts = self::contacts($claims);
        $display = self::displayContact($claims, $contacts);
        $locale = $display?->locale;

        $lists = [];
        foreach (self::lists() as $list) {
            $channels = [];
            $anySubscribed = false;

            foreach ($list->marketingChannels() as $channel) {
                $subscribed = self::anyGranted($contacts, $channel, $list->id);
                $anySubscribed = $anySubscribed || $subscribed;
                $channels[] = [
                    'channel' => $channel->value,
                    'subscribed' => $subscribed,
                ];
            }

            if ($channels === []) {
                continue;
            }

            if ($list->isArchived() && ! $anySubscribed) {
                continue;
            }

            $lists[] = [
                'id' => $list->id,
                'key' => $list->key,
                'label' => self::text($list->labels, $locale, $list->key),
                'description' => self::text($list->descriptions, $locale, ''),
                'channels' => $channels,
            ];
        }

        return [
            'contact_first_name' => $display?->first_name,
            'locale' => $locale,
            'lists' => $lists,
        ];
    }

    /**
     * @param  list<array{list_id: int, channel: string, subscribed: bool}>  $changes
     * @param  array<string, mixed>  $evidence
     */
    public static function apply(PreferenceClaims $claims, array $changes, array $evidence): void
    {
        if ($changes === []) {
            return;
        }

        $contacts = self::contacts($claims);
        $display = self::displayContact($claims, $contacts);

        DB::transaction(function () use ($changes, $evidence, $contacts, $display): void {
            foreach ($changes as $change) {
                $list = SubscriptionList::query()->find($change['list_id']);
                if ($list === null) {
                    throw new ConsentRuleException('list_channel');
                }

                $channel = Channel::from($change['channel']);
                if (! $list->allows($channel)) {
                    throw new ConsentRuleException('list_channel');
                }

                if ($change['subscribed']) {
                    self::grant($display, $channel, $list, $evidence);
                } else {
                    self::withdrawGranted($contacts, $channel, $list, $evidence);
                }
            }
        });
    }

    /**
     * Leave every list and write the marketing suppression for this address.
     *
     * @param  array<string, mixed>  $evidence
     */
    public static function unsubscribeAll(PreferenceClaims $claims, array $evidence): void
    {
        $contacts = self::contacts($claims);

        DB::transaction(function () use ($claims, $evidence, $contacts): void {
            foreach (SubscriptionList::query()->orderBy('id')->get() as $list) {
                foreach ($list->marketingChannels() as $channel) {
                    self::withdrawGranted($contacts, $channel, $list, $evidence);
                }
            }

            self::suppress($claims);
        });
    }

    /**
     * RFC 8058. Withdraws the token list, or every list when the token has none.
     * Suppression is written only for the all-lists case.
     *
     * @param  array<string, mixed>  $evidence
     */
    public static function oneClick(PreferenceClaims $claims, array $evidence): void
    {
        if ($claims->listId === null) {
            DB::transaction(function () use ($claims, $evidence): void {
                ConsentWriter::withdrawAllListsForAddress(
                    $claims->channel,
                    $claims->address,
                    ConsentSource::OneClick,
                    $evidence,
                );
                self::withdrawUnmatchedContact($claims, $evidence);
                self::suppress($claims);
            });

            return;
        }

        $list = SubscriptionList::query()->find($claims->listId);
        if ($list === null || ! $list->allows($claims->channel)) {
            throw new ConsentRuleException('list_channel');
        }

        DB::transaction(function () use ($claims, $evidence, $list): void {
            foreach (self::contacts($claims) as $contact) {
                ConsentWriter::withdraw(
                    $contact,
                    $claims->channel,
                    $list,
                    ConsentSource::OneClick,
                    $evidence,
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $map
     */
    private static function text(?array $map, ?string $locale, string $fallback): string
    {
        if ($map === null || $map === []) {
            return $fallback;
        }

        $code = strtolower(substr(trim((string) $locale), 0, 2));
        if ($code !== '' && isset($map[$code]) && is_string($map[$code]) && $map[$code] !== '') {
            return $map[$code];
        }

        if (isset($map['en']) && is_string($map['en']) && $map['en'] !== '') {
            return $map['en'];
        }

        $first = reset($map);

        return is_string($first) && $first !== '' ? $first : $fallback;
    }

    /**
     * @return Collection<int, SubscriptionList>
     */
    private static function lists(): Collection
    {
        return SubscriptionList::query()
            ->orderByDesc('is_system')
            ->orderBy('key')
            ->get();
    }

    /**
     * @return Collection<int, Contact>
     */
    private static function contacts(PreferenceClaims $claims): Collection
    {
        $ids = ConsentWriter::contactIdsForAddress($claims->channel, $claims->address);
        if ($claims->contactId !== null) {
            $ids[] = $claims->contactId;
        }

        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return new Collection;
        }

        return Contact::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     */
    private static function displayContact(PreferenceClaims $claims, Collection $contacts): ?Contact
    {
        if ($claims->contactId !== null) {
            $match = $contacts->firstWhere('id', $claims->contactId);
            if ($match instanceof Contact) {
                return $match;
            }
        }

        $latest = $contacts->sortByDesc('id')->first();

        return $latest instanceof Contact ? $latest : null;
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     */
    private static function anyGranted(Collection $contacts, Channel $channel, int $listId): bool
    {
        foreach ($contacts as $contact) {
            if (ConsentState::isGranted($contact->id, $channel, $listId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private static function grant(?Contact $contact, Channel $channel, SubscriptionList $list, array $evidence): void
    {
        if ($contact === null) {
            throw new ConsentRuleException('consent_text');
        }

        if ($list->isArchived()) {
            throw new ConsentRuleException('list_archived');
        }

        if (ConsentState::isGranted($contact->id, $channel, $list->id)) {
            return;
        }

        $textId = ConsentEvent::query()
            ->where('contact_id', $contact->id)
            ->where('channel', $channel->value)
            ->where('subscription_list_id', $list->id)
            ->whereNotNull('consent_text_id')
            ->orderByDesc('id')
            ->value('consent_text_id');

        if ($textId === null) {
            throw new ConsentRuleException('consent_text');
        }

        ConsentWriter::grant(
            $contact,
            $channel,
            $list,
            ConsentSource::PreferenceCenter,
            (int) $textId,
            $evidence,
        );
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @param  array<string, mixed>  $evidence
     */
    private static function withdrawGranted(
        Collection $contacts,
        Channel $channel,
        SubscriptionList $list,
        array $evidence,
    ): void {
        foreach ($contacts as $contact) {
            $action = self::latestAction($contact->id, $channel, $list->id);
            if ($action !== ConsentAction::Granted->value && $action !== ConsentAction::Pending->value) {
                continue;
            }

            ConsentWriter::withdraw(
                $contact,
                $channel,
                $list,
                ConsentSource::PreferenceCenter,
                $evidence,
            );
        }
    }

    private static function latestAction(int $contactId, Channel $channel, int $listId): ?string
    {
        $action = DB::table('consent_events')
            ->where('contact_id', $contactId)
            ->where('channel', $channel->value)
            ->where('subscription_list_id', $listId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('action');

        return $action !== null ? (string) $action : null;
    }

    /**
     * Token contact whose address no longer matches still loses every list.
     *
     * @param  array<string, mixed>  $evidence
     */
    private static function withdrawUnmatchedContact(PreferenceClaims $claims, array $evidence): void
    {
        if ($claims->contactId === null) {
            return;
        }

        $matched = ConsentWriter::contactIdsForAddress($claims->channel, $claims->address);
        if (in_array($claims->contactId, $matched, true)) {
            return;
        }

        $contact = Contact::query()->find($claims->contactId);
        if ($contact === null) {
            return;
        }

        $lists = SubscriptionList::query()
            ->whereJsonContains('channels', $claims->channel->value)
            ->get();

        foreach ($lists as $list) {
            ConsentWriter::withdraw($contact, $claims->channel, $list, ConsentSource::OneClick, $evidence);
        }
    }

    private static function suppress(PreferenceClaims $claims): void
    {
        if ($claims->channel === Channel::Email) {
            SuppressionWriter::fromUnsubscribe($claims->address, $claims->messageId);

            return;
        }

        SuppressionWriter::write(
            $claims->channel,
            $claims->address,
            SuppressionScope::Marketing,
            SuppressionReason::Unsubscribed,
            $claims->messageId,
        );
    }
}
