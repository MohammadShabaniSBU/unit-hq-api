<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Events\InboundMessageReceived;
use App\Models\CommsTriage;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Support\Communications\Results\InboundAttachment;
use App\Support\Communications\Results\InboundMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persist an inbound message (or park it in triage when the sender is unknown).
 */
final class InboundReceiptApplier
{
    /**
     * @return array{outcome: 'message'|'triage'|'duplicate'|'updated', message: ?Message, triage: ?CommsTriage}
     */
    public static function apply(
        Provider $provider,
        int $accountId,
        InboundMessage $inbound,
        ?Contact $forcedContact = null,
    ): array {
        $existing = Message::query()
            ->where('provider', $provider)
            ->where('provider_message_id', $inbound->providerMessageId)
            ->first();

        if ($existing !== null) {
            if ($inbound->channel === Channel::Call) {
                $updated = self::updateCallMessage($existing, $inbound);

                return ['outcome' => 'updated', 'message' => $updated, 'triage' => null];
            }

            return ['outcome' => 'duplicate', 'message' => $existing, 'triage' => null];
        }

        $existingTriage = CommsTriage::query()
            ->where('provider', $provider)
            ->where('provider_message_id', $inbound->providerMessageId)
            ->first();

        if ($existingTriage !== null && $forcedContact === null) {
            // Call lifecycle webhooks for an unknown number must refresh the parked
            // triage so the banner phase advances (ringing → ongoing → ended).
            if ($inbound->channel === Channel::Call && $existingTriage->status === 'pending') {
                $existingTriage = self::refreshCallTriage($existingTriage, $inbound);
            }

            return ['outcome' => 'triage', 'message' => null, 'triage' => $existingTriage];
        }

        $ambiguous = false;
        $contact = $forcedContact;

        if ($contact === null) {
            $match = ContactChannelMatcher::match($inbound->channel, $inbound->counterparty());
            $contact = $match['contact'];
            $ambiguous = $match['ambiguous'];
        }

        if ($contact === null) {
            $triage = self::parkTriage($provider, $accountId, $inbound, $existingTriage);
            self::maybeWriteStopSuppression($inbound, null);

            return ['outcome' => 'triage', 'message' => null, 'triage' => $triage];
        }

        $message = self::writeMessage($provider, $accountId, $inbound, $contact, $ambiguous);

        self::maybeWriteStopSuppression($inbound, $message->id);

        return ['outcome' => 'message', 'message' => $message, 'triage' => null];
    }

    private static function parkTriage(
        Provider $provider,
        int $accountId,
        InboundMessage $inbound,
        ?CommsTriage $existing,
    ): CommsTriage {
        if ($existing !== null) {
            if ($inbound->channel === Channel::Call && $existing->status === 'pending') {
                return self::refreshCallTriage($existing, $inbound);
            }

            return $existing;
        }

        $sender = ContactChannelMatcher::normalize($inbound->channel, $inbound->counterparty());
        $preview = self::triagePreview($inbound);

        try {
            return CommsTriage::query()->create([
                'communication_account_id' => $accountId,
                'provider' => $provider,
                'provider_message_id' => $inbound->providerMessageId,
                'channel' => $inbound->channel,
                'sender_value' => $sender !== '' ? $sender : $inbound->counterparty(),
                'preview' => $preview,
                'payload' => $inbound->raw,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return CommsTriage::query()
                ->where('provider', $provider)
                ->where('provider_message_id', $inbound->providerMessageId)
                ->firstOrFail();
        }
    }

    private static function refreshCallTriage(CommsTriage $triage, InboundMessage $inbound): CommsTriage
    {
        $triage->forceFill([
            'preview' => self::triagePreview($inbound),
            'payload' => $inbound->raw,
        ])->save();

        return $triage->fresh() ?? $triage;
    }

    /**
     * @return array<string, mixed>
     */
    private static function triagePreview(InboundMessage $inbound): array
    {
        return [
            'from' => $inbound->from,
            'to' => $inbound->to,
            'subject' => $inbound->subject,
            'body_text' => $inbound->bodyText !== null
                ? Str::limit($inbound->bodyText, 500)
                : null,
            'channel' => $inbound->channel->value,
        ];
    }

    private static function writeMessage(
        Provider $provider,
        int $accountId,
        InboundMessage $inbound,
        Contact $contact,
        bool $ambiguous,
    ): Message {
        return DB::transaction(function () use ($provider, $accountId, $inbound, $contact, $ambiguous): Message {
            $threadKey = match ($inbound->channel) {
                Channel::Email => (string) ($inbound->subject ?? ''),
                Channel::Sms, Channel::Call, Channel::Whatsapp => ContactChannelMatcher::normalize(
                    $inbound->channel,
                    $inbound->counterparty(),
                ),
            };

            $resolved = Threading::forInbound(
                $contact,
                $inbound->channel,
                $threadKey,
                $inbound->headers,
                $ambiguous,
            );

            $thread = $resolved['thread'];
            $evidence = $resolved['evidence'];
            $now = $inbound->occurredAt !== null
                ? \Illuminate\Support\Carbon::parse($inbound->occurredAt->toIso8601String())
                : now();

            $thread->forceFill([
                'last_message_at' => $now,
            ]);

            $sourceRef = $inbound->sourceRef;
            $unreadApplied = false;
            if ($inbound->shouldCountAsUnread()) {
                $thread->unread_count = (int) $thread->unread_count + 1;
                $thread->last_inbound_at = $now;
                $unreadApplied = true;
            }

            if ($sourceRef !== null) {
                $sourceRef['unread_applied'] = $unreadApplied;
            } elseif ($unreadApplied) {
                $sourceRef = ['unread_applied' => true];
            }

            $thread->save();

            $message = Message::query()->create([
                'message_thread_id' => $thread->id,
                'direction' => $inbound->direction,
                'status' => MessageStatus::Received,
                'body_text' => $inbound->bodyText,
                'body_html' => HtmlSanitizer::sanitize($inbound->bodyHtml),
                'from_address' => $inbound->from,
                'to_address' => $inbound->to !== '' ? $inbound->to : 'unknown',
                'provider' => $provider,
                'communication_account_id' => $accountId,
                'provider_message_id' => $inbound->providerMessageId,
                'threading_evidence' => $evidence,
                'source' => MessageSource::System,
                'source_ref' => $sourceRef,
                'auto_generated' => $inbound->autoGenerated,
                'sent_at' => null,
            ]);

            self::storeAttachments($message, $inbound->attachments);

            $metadata = self::callInteractionMetadata($inbound);

            Interaction::query()->create([
                'contact_id' => $contact->id,
                'deal_id' => null,
                'channel' => $inbound->channel->value,
                'direction' => $inbound->direction->value,
                'occurred_at' => $now,
                'content' => $inbound->bodyText !== null && $inbound->bodyText !== ''
                    ? $inbound->bodyText
                    : ($inbound->bodyHtml !== null ? strip_tags($inbound->bodyHtml) : null),
                'summary' => $inbound->channel === Channel::Email ? $inbound->subject : null,
                'metadata' => $metadata,
                'provider_message_id' => $inbound->providerMessageId,
                'communication_account_id' => $accountId,
                'message_id' => $message->id,
            ]);

            InboundMessageReceived::dispatch(
                $message->id,
                $thread->id,
                $contact->id,
                $inbound->channel,
                $inbound->autoGenerated,
            );

            $fresh = $message->fresh(['attachments', 'thread']) ?? $message;
            CallIntentCorrelator::correlate($fresh, $inbound);

            return $fresh->fresh(['attachments', 'thread']) ?? $fresh;
        });
    }

    private static function updateCallMessage(Message $message, InboundMessage $inbound): Message
    {
        return DB::transaction(function () use ($message, $inbound): Message {
            $now = $inbound->occurredAt !== null
                ? \Illuminate\Support\Carbon::parse($inbound->occurredAt->toIso8601String())
                : now();

            /** @var array<string, mixed> $existingRef */
            $existingRef = is_array($message->source_ref) ? $message->source_ref : [];
            /** @var array<string, mixed> $incomingRef */
            $incomingRef = $inbound->sourceRef ?? [];
            $mergedRef = array_merge($existingRef, $incomingRef);

            $thread = $message->thread;
            $unreadAlready = (bool) ($existingRef['unread_applied'] ?? false);

            if ($thread !== null) {
                $thread->forceFill([
                    'last_message_at' => $now,
                ]);

                if ($inbound->shouldCountAsUnread() && ! $unreadAlready) {
                    $thread->unread_count = (int) $thread->unread_count + 1;
                    $thread->last_inbound_at = $now;
                    $mergedRef['unread_applied'] = true;
                }

                $thread->save();
            }

            $message->forceFill([
                'direction' => $inbound->direction,
                'body_text' => $inbound->bodyText,
                'from_address' => $inbound->from,
                'to_address' => $inbound->to !== '' ? $inbound->to : $message->to_address,
                'source_ref' => $mergedRef,
            ])->save();

            $interaction = Interaction::query()
                ->where('message_id', $message->id)
                ->first();

            if ($interaction !== null) {
                $interaction->forceFill([
                    'direction' => $inbound->direction->value,
                    'content' => $inbound->bodyText,
                    'metadata' => self::callInteractionMetadata($inbound),
                    'occurred_at' => $now,
                ])->save();
            }

            return $message->fresh(['attachments', 'thread']) ?? $message;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function callInteractionMetadata(InboundMessage $inbound): ?array
    {
        if ($inbound->channel !== Channel::Call) {
            return null;
        }

        $ref = $inbound->sourceRef ?? [];

        return [
            'duration' => $ref['duration'] ?? null,
            'outcome' => $ref['outcome'] ?? null,
            'agent' => $ref['agent'] ?? null,
            'recording_url' => $ref['recording_url'] ?? null,
            'voicemail_url' => $ref['voicemail_url'] ?? null,
            'missed_call_reason' => $ref['missed_call_reason'] ?? null,
            'event' => $ref['event'] ?? null,
        ];
    }

    private static function maybeWriteStopSuppression(InboundMessage $inbound, ?int $messageId): void
    {
        if ($inbound->channel !== Channel::Sms) {
            return;
        }

        if (! SuppressionWriter::isStopKeyword($inbound->bodyText)) {
            return;
        }

        SuppressionWriter::fromStopKeyword($inbound->from, $messageId);
    }

    /**
     * @param  list<InboundAttachment>  $attachments
     */
    private static function storeAttachments(Message $message, array $attachments): void
    {
        $maxEach = (int) config('communications.inbound.max_attachment_bytes', 10 * 1024 * 1024);
        $maxTotal = (int) config('communications.inbound.max_total_attachment_bytes', 25 * 1024 * 1024);
        $storedTotal = 0;

        foreach ($attachments as $attachment) {
            $size = $attachment->sizeBytes;
            $overCap = $size > $maxEach || ($storedTotal + $size) > $maxTotal;

            if ($overCap || $attachment->contentBase64 === null || $attachment->contentBase64 === '') {
                MessageAttachment::query()->create([
                    'message_id' => $message->id,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mimeType,
                    'size_bytes' => $size,
                    'oversize' => true,
                    'disk_path' => null,
                ]);

                continue;
            }

            $binary = base64_decode($attachment->contentBase64, true);
            if ($binary === false) {
                MessageAttachment::query()->create([
                    'message_id' => $message->id,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mimeType,
                    'size_bytes' => $size,
                    'oversize' => true,
                    'disk_path' => null,
                ]);

                continue;
            }

            $actualSize = strlen($binary);
            if ($actualSize > $maxEach || ($storedTotal + $actualSize) > $maxTotal) {
                MessageAttachment::query()->create([
                    'message_id' => $message->id,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mimeType,
                    'size_bytes' => $actualSize,
                    'oversize' => true,
                    'disk_path' => null,
                ]);

                continue;
            }

            $safeName = Str::slug(pathinfo($attachment->filename, PATHINFO_FILENAME)) ?: 'attachment';
            $ext = pathinfo($attachment->filename, PATHINFO_EXTENSION);
            $path = sprintf(
                'message-attachments/%d/%s%s',
                $message->id,
                $safeName.'-'.Str::random(8),
                $ext !== '' ? '.'.$ext : '',
            );

            Storage::disk('local')->put($path, $binary);

            MessageAttachment::query()->create([
                'message_id' => $message->id,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mimeType,
                'size_bytes' => $actualSize,
                'oversize' => false,
                'disk_path' => $path,
            ]);

            $storedTotal += $actualSize;
        }
    }
}
