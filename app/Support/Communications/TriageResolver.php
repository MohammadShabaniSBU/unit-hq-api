<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\ContactChannelType;
use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactRecordStatus;
use App\Enums\ContactSource;
use App\Models\CommsTriage;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Message;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\ProviderRegistry;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolve a pending triage row into a real message (or discard it).
 */
final class TriageResolver
{
    public static function attach(CommsTriage $triage, Contact $contact, ProviderRegistry $registry): Message
    {
        return self::resolveWithContact($triage, $contact, $registry);
    }

    /**
     * @param  array{first_name?: string, last_name?: string}|null  $names
     */
    public static function createAndAttach(
        CommsTriage $triage,
        ProviderRegistry $registry,
        ?array $names = null,
    ): Message {
        return DB::transaction(function () use ($triage, $registry, $names): Message {
            $first = trim((string) ($names['first_name'] ?? 'Unknown'));
            $last = trim((string) ($names['last_name'] ?? 'Sender'));

            $channelType = match ($triage->channel) {
                Channel::Email => ContactChannelType::Email,
                Channel::Sms, Channel::Call => ContactChannelType::Phone,
                Channel::Whatsapp => ContactChannelType::Whatsapp,
            };

            $contact = Contact::query()->create([
                'first_name' => $first !== '' ? $first : 'Unknown',
                'last_name' => $last !== '' ? $last : 'Sender',
                'email' => $channelType === ContactChannelType::Email ? $triage->sender_value : null,
                'status' => ContactLifecycleStatus::Prospect,
                'contact_status' => ContactRecordStatus::Active,
                'source' => ContactSource::EmailConversations,
                'source_detail' => 'comms_triage',
            ]);

            ContactChannel::query()->create([
                'contact_id' => $contact->id,
                'type' => $channelType,
                'value' => $triage->sender_value,
                'label' => 'Primary',
                'is_primary' => true,
                'opted_in' => true,
            ]);

            return self::resolveWithContact($triage->fresh() ?? $triage, $contact, $registry);
        });
    }

    public static function discard(CommsTriage $triage): CommsTriage
    {
        if ($triage->status !== 'pending') {
            throw new InvalidArgumentException('Triage row is not pending.');
        }

        $triage->forceFill([
            'status' => 'discarded',
            'resolved_at' => now(),
        ])->save();

        return $triage;
    }

    private static function resolveWithContact(
        CommsTriage $triage,
        Contact $contact,
        ProviderRegistry $registry,
    ): Message {
        if ($triage->status !== 'pending') {
            throw new InvalidArgumentException('Triage row is not pending.');
        }

        $account = $triage->communicationAccount;
        if ($account === null) {
            throw new RuntimeException('Triage row has no communication account.');
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $adapter = $registry->make($account->channel, $account->provider, $credentials);
        if (! $adapter instanceof ReceivesInbound) {
            throw new RuntimeException('Account adapter cannot parse inbound payloads.');
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($triage->payload) ? $triage->payload : [];
        $inbound = $adapter->parseInbound($payload);
        if ($inbound === null) {
            throw new RuntimeException('Stored triage payload is not a valid inbound message.');
        }

        $result = InboundReceiptApplier::apply(
            $triage->provider,
            (int) $account->id,
            $inbound,
            $contact,
        );

        if ($result['message'] === null) {
            throw new RuntimeException('Failed to create message from triage.');
        }

        $triage->forceFill([
            'status' => 'resolved',
            'resolved_contact_id' => $contact->id,
            'resolved_message_id' => $result['message']->id,
            'resolved_at' => now(),
        ])->save();

        return $result['message'];
    }
}
