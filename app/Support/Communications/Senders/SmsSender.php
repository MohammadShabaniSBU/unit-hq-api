<?php

declare(strict_types=1);

namespace App\Support\Communications\Senders;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\SendsSms;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\Results\SendResult;

final class SmsSender
{
    public function __construct(
        private readonly ProviderResolver $resolver,
    ) {}

    public function send(SmsMessage $message, Site $site, ?Contact $contact = null): SendResult
    {
        $resolved = $this->resolver->resolve(Channel::Sms, $site);
        $adapter = $resolved->require(SendsSms::class, 'sending SMS');

        $identity = SiteSenderIdentity::query()
            ->where('site_id', $site->id)
            ->where('channel', Channel::Sms)
            ->first();

        if ($message->from === null && $identity?->from_number !== null) {
            $message = $message->withSender($identity->from_number);
        }

        $result = $adapter->sendSms($message)->withAccountId($resolved->account->id);

        if ($contact !== null) {
            Interaction::query()->create([
                'contact_id' => $contact->id,
                'channel' => Channel::Sms->value,
                'direction' => 'outbound',
                'occurred_at' => now(),
                'content' => $message->body,
                'summary' => null,
                'provider_message_id' => $result->providerMessageId,
                'communication_account_id' => $result->accountId,
            ]);
        }

        return $result;
    }
}
