<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\ContactChannelType;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Contact;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Automation\SubjectChain;
use App\Support\Communications\Channel;
use App\Support\Communications\ComposerIdentity;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\SendRefused;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Messages\WhatsAppSessionMessage;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\Senders\WhatsAppSender;
use App\Support\Communications\Threading;
use RuntimeException;
use Throwable;

/**
 * Sole path from an agent draft to a real send (invariant 38 / 69).
 * Never inserts a `messages` row itself — the channel sender does.
 */
final class AgentSend
{
    public function __construct(
        private readonly EmailSender $email,
        private readonly SmsSender $sms,
        private readonly WhatsAppSender $whatsapp,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(
        AgentConversation $conversation,
        array $payload,
        ?AgentConversationMessage $assistantMessage = null,
    ): SendResult {
        $thread = $this->thread($payload);
        $contact = $thread->contact;
        if ($contact === null) {
            throw new RuntimeException('Thread has no contact.');
        }

        $site = $this->site($payload, $thread);
        $body = trim((string) ($payload['body'] ?? ''));
        $subject = isset($payload['subject']) && is_string($payload['subject']) && $payload['subject'] !== ''
            ? $payload['subject']
            : null;
        $assistantId = $assistantMessage?->id
            ?? (isset($payload['agent_conversation_message_id']) ? (int) $payload['agent_conversation_message_id'] : null);

        $context = SendContext::aiAgent($conversation, $assistantId);
        Threading::forExplicitThread($thread);

        try {
            $result = match ($thread->channel instanceof Channel ? $thread->channel : Channel::from((string) $thread->channel)) {
                Channel::Email => $this->sendEmail($thread, $contact, $site, $body, $subject, $context),
                Channel::Sms => $this->sendSms($thread, $contact, $site, $body, $context),
                Channel::Whatsapp => $this->sendWhatsApp($thread, $contact, $site, $body, $context),
                default => throw new RuntimeException('Unsupported channel for agent send.'),
            };
        } catch (SendRefused|ChannelNotConfigured $e) {
            $this->refuse($conversation, $thread, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->refuse($conversation, $thread, $e);

            throw $e;
        }

        if ($result->wasSuppressed()) {
            SystemEvent::record('ai.send.refused', $conversation, [
                'reason' => 'suppressed',
                'suppressed_reason' => $result->suppressedReason,
                'message_thread_id' => $thread->id,
            ]);

            throw AgentSendRefused::suppressed($result->suppressedReason);
        }

        if ($assistantMessage !== null && $result->messageId !== null) {
            $assistantMessage->forceFill(['emitted_message_id' => $result->messageId])->save();
        }

        if ($conversation->message_thread_id !== $thread->id) {
            $conversation->forceFill(['message_thread_id' => $thread->id])->save();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function thread(array $payload): MessageThread
    {
        $threadId = isset($payload['message_thread_id']) ? (int) $payload['message_thread_id'] : 0;
        if ($threadId <= 0) {
            throw new RuntimeException('Agent send is missing message_thread_id.');
        }

        return MessageThread::query()->with('contact.channels')->findOrFail($threadId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function site(array $payload, MessageThread $thread): Site
    {
        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : 0;
        if ($siteId > 0) {
            return Site::query()->findOrFail($siteId);
        }

        $resolved = ComposerIdentity::resolveForThread($thread);
        if ($resolved['site'] instanceof Site) {
            return $resolved['site'];
        }

        throw new RuntimeException('Agent send could not resolve a site.');
    }

    private function sendEmail(
        MessageThread $thread,
        Contact $contact,
        Site $site,
        string $body,
        ?string $subject,
        SendContext $context,
    ): SendResult {
        $to = $thread->channel_key
            ?: SubjectChain::primaryChannel($contact, ContactChannelType::Email)?->value;
        if ($to === null || $to === '') {
            $to = $contact->email;
        }
        if ($to === null || $to === '') {
            throw new RuntimeException('Contact has no email address.');
        }

        $message = new EmailMessage(
            to: [new EmailAddress($to)],
            subject: $subject ?? ComposerIdentity::replySubject($thread),
            html: $body,
            text: $body,
            headers: ComposerIdentity::replyHeaders($thread),
        );

        return $this->email->send(
            $message,
            $site,
            $contact,
            $context,
            thread: $thread,
        );
    }

    private function sendSms(
        MessageThread $thread,
        Contact $contact,
        Site $site,
        string $body,
        SendContext $context,
    ): SendResult {
        $to = $thread->channel_key
            ?: SubjectChain::primaryChannel($contact, ContactChannelType::Phone)?->value;
        if ($to === null || $to === '') {
            throw new RuntimeException('Contact has no phone number.');
        }

        return $this->sms->send(
            new SmsMessage(to: $to, body: $body),
            $site,
            $contact,
            $context,
            thread: $thread,
        );
    }

    private function sendWhatsApp(
        MessageThread $thread,
        Contact $contact,
        Site $site,
        string $body,
        SendContext $context,
    ): SendResult {
        $to = $thread->channel_key
            ?: SubjectChain::primaryChannel($contact, ContactChannelType::Whatsapp)?->value;
        if ($to === null || $to === '') {
            throw new RuntimeException('Contact has no WhatsApp number.');
        }

        return $this->whatsapp->sendSession(
            new WhatsAppSessionMessage(to: $to, body: $body),
            $site,
            $contact,
            $context,
            $thread,
        );
    }

    private function refuse(AgentConversation $conversation, MessageThread $thread, Throwable $e): void
    {
        SystemEvent::record('ai.send.refused', $conversation, [
            'reason' => $e instanceof SendRefused ? $e->reasonKey : $e::class,
            'message' => $e->getMessage(),
            'message_thread_id' => $thread->id,
        ]);
    }
}
