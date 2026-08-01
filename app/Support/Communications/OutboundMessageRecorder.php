<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\OfferDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Writes the canonical message + Interaction (+ optional OfferDelivery stamp)
 * for an outbound send. Used by EmailSender / SmsSender after provider accept
 * or reject.
 */
final class OutboundMessageRecorder
{
    /**
     * @param  array<string, mixed>|null  $interactionMetadata
     * @return array{message: Message, interaction: Interaction}
     */
    public static function record(
        Contact $contact,
        Channel $channel,
        string $threadKey,
        MessageStatus $status,
        SendContext $context,
        string $fromAddress,
        string $toAddress,
        ?string $bodyText,
        ?string $bodyHtml,
        ?Provider $provider,
        ?int $accountId,
        ?string $providerMessageId,
        ?int $dealId = null,
        ?array $interactionMetadata = null,
    ): array {
        return DB::transaction(function () use (
            $contact,
            $channel,
            $threadKey,
            $status,
            $context,
            $fromAddress,
            $toAddress,
            $bodyText,
            $bodyHtml,
            $provider,
            $accountId,
            $providerMessageId,
            $dealId,
            $interactionMetadata,
        ): array {
            $resolved = Threading::forOutbound($contact, $channel, $threadKey);
            $thread = $resolved['thread'];
            $evidence = $resolved['evidence'];

            $now = now();
            $thread->forceFill(['last_message_at' => $now])->save();

            $resolvedProviderId = ($providerMessageId !== null && $providerMessageId !== '')
                ? $providerMessageId
                : null;

            $message = Message::query()->create([
                'message_thread_id' => $thread->id,
                'direction' => MessageDirection::Outbound,
                'status' => $status,
                'body_text' => $bodyText,
                'body_html' => HtmlSanitizer::sanitize($bodyHtml),
                'from_address' => $fromAddress,
                'to_address' => $toAddress,
                'provider' => $provider,
                'communication_account_id' => $accountId,
                'provider_message_id' => $resolvedProviderId,
                'threading_evidence' => $evidence,
                'source' => $context->source,
                'source_ref' => $context->sourceRef,
                'sent_at' => $status === MessageStatus::Failed ? null : $now,
            ]);

            $interaction = Interaction::query()->create([
                'contact_id' => $contact->id,
                'deal_id' => $dealId,
                'channel' => $channel->value,
                'direction' => MessageDirection::Outbound->value,
                'occurred_at' => $now,
                'content' => $bodyText !== null && $bodyText !== ''
                    ? $bodyText
                    : ($bodyHtml !== null ? strip_tags($bodyHtml) : null),
                'summary' => $channel === Channel::Email ? $threadKey : null,
                'metadata' => $interactionMetadata,
                'provider_message_id' => $resolvedProviderId,
                'communication_account_id' => $accountId,
                'message_id' => $message->id,
            ]);

            if ($context->offerDeliveryId !== null) {
                $delivery = OfferDelivery::query()->find($context->offerDeliveryId);
                if ($delivery !== null) {
                    $delivery->message_id = $message->id;
                    if ($resolvedProviderId !== null) {
                        $delivery->provider_message_id = $resolvedProviderId;
                    }
                    if ($accountId !== null) {
                        $delivery->communication_account_id = $accountId;
                    }
                    if ($status === MessageStatus::Failed) {
                        $delivery->delivery_status = 'failed';
                    } elseif (in_array($delivery->delivery_status, ['queued', ''], true)) {
                        $delivery->delivery_status = 'sent';
                    }
                    $delivery->save();
                }
            }

            return ['message' => $message, 'interaction' => $interaction];
        });
    }
}
