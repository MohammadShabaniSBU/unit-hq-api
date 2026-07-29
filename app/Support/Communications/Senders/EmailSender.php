<?php

declare(strict_types=1);

namespace App\Support\Communications\Senders;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\ProviderResolver;
use App\Support\Communications\Results\SendResult;

final class EmailSender
{
    public function __construct(
        private readonly ProviderResolver $resolver,
    ) {}

    public function send(EmailMessage $message, Site $site, ?Contact $contact = null): SendResult
    {
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

        $result = $adapter->sendEmail($message)->withAccountId($resolved->account->id);

        if ($contact !== null) {
            Interaction::query()->create([
                'contact_id' => $contact->id,
                'channel' => Channel::Email->value,
                'direction' => 'outbound',
                'occurred_at' => now(),
                'content' => $message->text !== '' ? $message->text : $message->html,
                'summary' => $message->subject,
                'provider_message_id' => $result->providerMessageId,
                'communication_account_id' => $result->accountId,
            ]);
        }

        return $result;
    }
}
