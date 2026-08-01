<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;

/**
 * Site + sender-identity resolution for the inbox composer (honesty rule).
 * Thread → most relevant contract → site → site_sender_identities, else first site.
 */
final class ComposerIdentity
{
    /**
     * @return array{site: Site|null, identity: array<string, string>|null}
     */
    public static function resolve(Contact $contact, Channel $channel): array
    {
        $site = self::resolveSite($contact);
        if ($site === null) {
            return ['site' => null, 'identity' => null];
        }

        $row = SiteSenderIdentity::query()
            ->where('site_id', $site->id)
            ->where('channel', $channel)
            ->first();

        $identity = self::mapIdentity($row, $channel);

        return ['site' => $site, 'identity' => $identity];
    }

    /**
     * @return array{site: Site|null, identity: array<string, string>|null}
     */
    public static function resolveForThread(MessageThread $thread): array
    {
        $channel = $thread->channel instanceof Channel
            ? $thread->channel
            : Channel::from((string) $thread->channel);

        $thread->loadMissing('contact');
        $contact = $thread->contact;
        if ($contact === null) {
            return ['site' => null, 'identity' => null];
        }

        return self::resolve($contact, $channel);
    }

    public static function resolveSite(Contact $contact): ?Site
    {
        $contract = self::mostRelevantContract($contact);
        if ($contract !== null) {
            $contract->loadMissing('unitItem.item.site');
            $site = $contract->unitItem?->item?->site;
            if ($site instanceof Site) {
                return $site;
            }
        }

        $deal = Deal::query()
            ->where('contact_id', $contact->id)
            ->whereNotIn('status', DealStatus::terminalValues())
            ->whereNotNull('site_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->with('site')
            ->first();

        if ($deal?->site instanceof Site) {
            return $deal->site;
        }

        return Site::query()->orderBy('id')->first();
    }

    public static function mostRelevantContract(Contact $contact): ?Contract
    {
        return Contract::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', [
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Reply subject with a single Re: prefix.
     */
    public static function replySubject(MessageThread $thread): string
    {
        $raw = (string) ($thread->subject ?? '');
        $normalized = Threading::normalizeSubject($raw);

        if ($normalized === '') {
            return 'Re:';
        }

        return 'Re: '.$normalized;
    }

    /**
     * In-Reply-To / References from the latest inbound Message-ID on the thread.
     *
     * @return array<string, string>
     */
    public static function replyHeaders(MessageThread $thread): array
    {
        $inbound = Message::query()
            ->where('message_thread_id', $thread->id)
            ->where('direction', MessageDirection::Inbound)
            ->orderByDesc('id')
            ->get(['provider_message_id', 'threading_evidence']);

        foreach ($inbound as $message) {
            $id = self::messageIdToken($message);
            if ($id === null) {
                continue;
            }

            $bracketed = '<'.$id.'>';

            return [
                'In-Reply-To' => $bracketed,
                'References' => $bracketed,
            ];
        }

        return [];
    }

    private static function messageIdToken(Message $message): ?string
    {
        if (is_string($message->provider_message_id) && trim($message->provider_message_id) !== '') {
            return Threading::normalizeMessageId($message->provider_message_id);
        }

        $evidence = $message->threading_evidence;
        if (! is_array($evidence)) {
            return null;
        }

        $raw = $evidence['message_id'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $normalized = Threading::normalizeMessageId($raw);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function mapIdentity(?SiteSenderIdentity $row, Channel $channel): ?array
    {
        if ($row === null) {
            return null;
        }

        if ($channel === Channel::Email) {
            $address = $row->from_email;
            if ($address === null || trim($address) === '') {
                return null;
            }

            $label = $row->from_name !== null && trim($row->from_name) !== ''
                ? $row->from_name
                : $address;

            return [
                'address' => $address,
                'label' => $label,
            ];
        }

        if ($channel === Channel::Sms) {
            $number = $row->from_number;
            if ($number === null || trim($number) === '') {
                return null;
            }

            $label = $row->from_name !== null && trim((string) $row->from_name) !== ''
                ? (string) $row->from_name
                : $number;

            return [
                'number' => $number,
                'label' => $label,
            ];
        }

        return null;
    }
}
