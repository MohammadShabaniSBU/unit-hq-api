<?php

declare(strict_types=1);

namespace App\Support\Communications\Senders;

use App\Models\Contact;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\OutboundMessageRecorder;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\SendContext;

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
            );

            return $result->withStoreIds($recorded['message']->id, $recorded['interaction']->id);
        }

        return $result;
    }
}
