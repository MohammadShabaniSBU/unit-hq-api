<?php

declare(strict_types=1);

namespace App\Support\Communications\Senders;

use App\Models\Contact;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\SendsSms;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\OutboundMessageRecorder;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\SendContext;

final class SmsSender
{
    public function __construct(
        private readonly ProviderResolver $resolver,
    ) {}

    public function send(
        SmsMessage $message,
        Site $site,
        ?Contact $contact,
        SendContext $context,
        ?int $dealId = null,
        ?array $interactionMetadata = null,
    ): SendResult {
        $resolved = $this->resolver->resolve(Channel::Sms, $site);
        $adapter = $resolved->require(SendsSms::class, 'sending SMS');

        $identity = SiteSenderIdentity::query()
            ->where('site_id', $site->id)
            ->where('channel', Channel::Sms)
            ->first();

        if ($message->from === null && $identity?->from_number !== null) {
            $message = $message->withSender($identity->from_number);
        }

        $fromAddress = $message->from ?? $identity?->from_number ?? '';

        try {
            $result = $adapter->sendSms($message)->withAccountId($resolved->account->id);
        } catch (ProviderRequestFailed $exception) {
            if ($contact !== null) {
                OutboundMessageRecorder::record(
                    contact: $contact,
                    channel: Channel::Sms,
                    threadKey: $message->to,
                    status: MessageStatus::Failed,
                    context: $context,
                    fromAddress: $fromAddress,
                    toAddress: $message->to,
                    bodyText: $message->body,
                    bodyHtml: null,
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
                channel: Channel::Sms,
                threadKey: $message->to,
                status: MessageStatus::Sent,
                context: $context,
                fromAddress: $fromAddress,
                toAddress: $message->to,
                bodyText: $message->body,
                bodyHtml: null,
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
