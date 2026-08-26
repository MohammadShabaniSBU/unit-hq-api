<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\ContactChannelType;
use App\Models\AgentConversationMessage;
use App\Models\MessageThread;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\AgentSend;
use App\Support\Ai\AgentSendRefused;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Automation\SubjectChain;
use App\Support\Communications\Channel;
use App\Support\Communications\ComposerIdentity;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\SendRefused;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\WhatsAppWindow;
use App\Support\Leasing\LeasingActor;
use Throwable;

final class ChannelSendTool implements AgentTool, ProposableTool
{
    public function key(): string
    {
        return 'channel.send';
    }

    public function description(): string
    {
        return 'Send the draft reply on the inbox thread. Runtime-only — the model never calls this.';
    }

    public function schema(): array
    {
        return [
            'message_thread_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Inbox thread to reply on',
            ],
            'body' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Outbound body',
            ],
            'subject' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Email subject',
            ],
            'agent_conversation_message_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Assistant trace row this send belongs to',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::ChannelAsserted;
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function retainInSummary(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        if ($ctx?->agent === null) {
            return ToolResult::error('Agent context is required.');
        }

        $proposed = $this->propose($principal, $arguments, $ctx);
        if ($proposed->status !== ToolInvocationStatus::Ok) {
            return $proposed;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($proposed->data['payload'] ?? null) ? $proposed->data['payload'] : [];

        return $this->commit(LeasingActor::agent($ctx->agent), $payload, $principal, $ctx);
    }

    public function propose(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $threadId = isset($arguments['message_thread_id']) ? (int) $arguments['message_thread_id'] : 0;
        $thread = MessageThread::query()->with('contact.channels')->find($threadId);
        if ($thread === null) {
            return ToolResult::error('Thread was not found.');
        }

        $contactId = $ctx?->conversation->contact_id ?? $principal->contactId;
        if ($contactId === null || $thread->contact_id !== $contactId) {
            return ToolResult::error('Thread does not belong to this contact.');
        }

        $resolved = ComposerIdentity::resolveForThread($thread);
        if ($resolved['site'] === null || $resolved['identity'] === null) {
            return ToolResult::error(
                'Sender identity is not configured.',
                HandoffReason::ChannelConstraint,
            );
        }

        /** @var Site $site */
        $site = $resolved['site'];
        $siteId = $ctx?->conversation->site_id ?? $site->id;

        $channel = $thread->channel instanceof Channel
            ? $thread->channel
            : Channel::from((string) $thread->channel);

        $to = $this->destination($thread, $channel);
        if ($to === null || $to === '') {
            return ToolResult::error('No destination address on this thread.', HandoffReason::ChannelConstraint);
        }

        $windowClosesAt = null;
        if ($channel === Channel::Whatsapp) {
            if (! WhatsAppWindow::isOpen($thread)) {
                return ToolResult::error('WhatsApp session window is closed.', HandoffReason::ChannelConstraint);
            }
            $windowClosesAt = WhatsAppWindow::closesAt($thread)?->toIso8601String();
        }

        $body = trim((string) ($arguments['body'] ?? ''));
        if ($body === '') {
            return ToolResult::error('Body is empty.');
        }

        $subject = isset($arguments['subject']) && is_string($arguments['subject']) && $arguments['subject'] !== ''
            ? $arguments['subject']
            : null;

        $assistantMessageId = isset($arguments['agent_conversation_message_id'])
            ? (int) $arguments['agent_conversation_message_id']
            : null;

        $sms = $channel === Channel::Sms ? new SmsMessage($to, $body) : null;

        $payload = [
            'site_id' => $siteId,
            'message_thread_id' => $thread->id,
            'body' => $body,
        ];
        if ($subject !== null) {
            $payload['subject'] = $subject;
        }
        if ($assistantMessageId !== null && $assistantMessageId > 0) {
            $payload['agent_conversation_message_id'] = $assistantMessageId;
        }

        $identity = $resolved['identity'];
        $preview = [
            'from_identity' => $identity['label'] ?? $identity['address'] ?? $identity['number'] ?? null,
            'segments' => $sms?->segmentCount(),
            'encoding' => $sms?->encoding(),
            'window_closes_at' => $windowClosesAt,
        ];

        return ToolResult::ok(
            [
                'payload' => $payload,
                'preview' => $preview,
            ],
            '',
            new FactBag,
        );
    }

    public function commit(
        LeasingActor $actor,
        array $payload,
        AgentPrincipal $principal,
        ?AgentContext $ctx = null,
    ): ToolResult {
        if ($ctx?->conversation === null) {
            return ToolResult::error('Agent context is required.');
        }

        $assistant = null;
        $assistantId = isset($payload['agent_conversation_message_id'])
            ? (int) $payload['agent_conversation_message_id']
            : 0;
        if ($assistantId > 0) {
            $assistant = AgentConversationMessage::query()->find($assistantId);
        }

        try {
            $result = app(AgentSend::class)->send($ctx->conversation, $payload, $assistant);
        } catch (AgentSendRefused|SendRefused|ChannelNotConfigured $e) {
            return ToolResult::error($e->getMessage(), HandoffReason::ChannelConstraint);
        } catch (Throwable $e) {
            report($e);

            return ToolResult::error($e->getMessage(), HandoffReason::ChannelConstraint);
        }

        return ToolResult::ok(
            ['message_id' => $result->messageId],
            'Reply sent.',
            new FactBag,
            resultId: $result->messageId,
        );
    }

    private function destination(MessageThread $thread, Channel $channel): ?string
    {
        if (is_string($thread->channel_key) && $thread->channel_key !== '') {
            return $thread->channel_key;
        }

        $contact = $thread->contact;
        if ($contact === null) {
            return null;
        }

        $type = match ($channel) {
            Channel::Email => ContactChannelType::Email,
            Channel::Sms => ContactChannelType::Phone,
            Channel::Whatsapp => ContactChannelType::Whatsapp,
            default => null,
        };
        if ($type === null) {
            return null;
        }

        return SubjectChain::primaryChannel($contact, $type)?->value;
    }
}
