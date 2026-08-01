<?php

declare(strict_types=1);

namespace App\Support\Communications\Senders;

use App\Models\Contact;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\OutboundMessageRecorder;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\SuppressionWriter;
use App\Support\Communications\UnsubscribeToken;

final class EmailSender
{
    public function __construct(
        private readonly ProviderResolver $resolver,
    ) {}

    public function send(
        EmailMessage $message,
        Site $site,
        ?Contact $contact,
        SendContext $context,
        ?int $dealId = null,
        ?array $interactionMetadata = null,
        ?MessageThread $thread = null,
    ): SendResult {
        $resolved = $this->resolver->resolve(Channel::Email, $site);
        $adapter = $resolved->require(SendsEmail::class, 'sending email');

        $identity = SiteSenderIdentity::query()
            ->where('site_id', $site->id)
            ->where('channel', Channel::Email)
            ->first();

        if ($message->from === null && $identity?->from_email !== null) {
            $message = $message->withSender(
                new EmailAddress($identity->from_email, $identity->from_name),
                $message->replyTo
            );
        }

        if ($message->replyTo === null && $identity?->reply_to_email !== null) {
            $message = $message->withSender(
                $message->from,
                new EmailAddress($identity->reply_to_email)
            );
        }

        $tags = $message->tags;
        $siteTag = 'site:'.$site->id;

        if (! in_array($siteTag, $tags, true)) {
            $tags[] = $siteTag;
            $message = $message->withTags($tags);
        }

        $fromAddress = $message->from?->email ?? $identity?->from_email ?? '';
        $toAddress = $message->to[0]->email ?? '';

        $suppression = SuppressionWriter::blocks(Channel::Email, $toAddress, $context->class);
        if ($suppression !== null) {
            return $this->recordSuppressed(
                $message,
                $contact,
                $context,
                $fromAddress,
                $toAddress,
                $resolved->account->provider,
                $resolved->account->id,
                $suppression->reason->value,
                $dealId,
                $interactionMetadata,
                $thread,
            );
        }

        if ($context->class === SendClass::Marketing && $toAddress !== '') {
            $url = UnsubscribeToken::url($toAddress);
            $headers = $message->headers;
            $headers['List-Unsubscribe'] = '<'.$url.'>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
            $message = $message->withHeaders($headers);
        }

        try {
            $result = $adapter->sendEmail($message)->withAccountId($resolved->account->id);
        } catch (ProviderRequestFailed $exception) {
            if ($contact !== null) {
                OutboundMessageRecorder::record(
                    contact: $contact,
                    channel: Channel::Email,
                    threadKey: $message->subject,
                    status: MessageStatus::Failed,
                    context: $context,
                    fromAddress: $fromAddress,
                    toAddress: $toAddress,
                    bodyText: $message->text !== '' ? $message->text : null,
                    bodyHtml: $message->html !== '' ? $message->html : null,
                    provider: $resolved->account->provider,
                    accountId: $resolved->account->id,
                    providerMessageId: null,
                    dealId: $dealId,
                    interactionMetadata: $interactionMetadata,
                    thread: $thread,
                );
            }

            throw $exception;
        }

        if ($contact !== null) {
            $recorded = OutboundMessageRecorder::record(
                contact: $contact,
                channel: Channel::Email,
                threadKey: $message->subject,
                status: MessageStatus::Sent,
                context: $context,
                fromAddress: $fromAddress,
                toAddress: $toAddress,
                bodyText: $message->text !== '' ? $message->text : null,
                bodyHtml: $message->html !== '' ? $message->html : null,
                provider: $result->provider,
                accountId: $result->accountId,
                providerMessageId: $result->providerMessageId,
                dealId: $dealId,
                interactionMetadata: $interactionMetadata,
                thread: $thread,
            );

            return $result->withStoreIds($recorded['message']->id, $recorded['interaction']->id);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $interactionMetadata
     */
    private function recordSuppressed(
        EmailMessage $message,
        ?Contact $contact,
        SendContext $context,
        string $fromAddress,
        string $toAddress,
        Provider $provider,
        int $accountId,
        string $suppressedReason,
        ?int $dealId,
        ?array $interactionMetadata,
        ?MessageThread $thread,
    ): SendResult {
        $messageId = null;
        $interactionId = null;

        if ($contact !== null) {
            $recorded = OutboundMessageRecorder::record(
                contact: $contact,
                channel: Channel::Email,
                threadKey: $message->subject,
                status: MessageStatus::Failed,
                context: $context,
                fromAddress: $fromAddress,
                toAddress: $toAddress,
                bodyText: $message->text !== '' ? $message->text : null,
                bodyHtml: $message->html !== '' ? $message->html : null,
                provider: $provider,
                accountId: $accountId,
                providerMessageId: null,
                dealId: $dealId,
                interactionMetadata: $interactionMetadata,
                detail: ['suppressed_reason' => $suppressedReason],
                thread: $thread,
            );
            $messageId = $recorded['message']->id;
            $interactionId = $recorded['interaction']->id;
        }

        return new SendResult(
            providerMessageId: '',
            provider: $provider,
            accountId: $accountId,
            raw: ['suppressed' => true, 'reason' => $suppressedReason],
            messageId: $messageId,
            interactionId: $interactionId,
            suppressedReason: $suppressedReason,
        );
    }
}
