<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\VoiceSession;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Identity\VerificationDestination;
use App\Support\Communications\Channel;
use App\Support\Communications\ComposerIdentity;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\SuppressionWriter;
use Throwable;

/**
 * Texts a catalogue quote. Display contains no figure — VoiceNumberGuard
 * is the runtime digit rule; this result must not license money tokens.
 */
final class VoiceSendQuoteByTextTool implements AgentTool
{
    public function key(): string
    {
        return 'voice.send_quote_by_text';
    }

    public function description(): string
    {
        return 'Text the exact catalogue quote to the contact. Never supply a destination. The spoken reply must not contain the figure.';
    }

    public function schema(): array
    {
        return [
            'unit_class_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Unit class id',
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Site id',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
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
        return [
            'unit_class_id' => EntityType::UnitClass,
            'site_id' => EntityType::Site,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $contactId = $principal->contactId ?? $ctx?->conversation->contact_id;
        if ($contactId === null) {
            return ToolResult::fail(ToolError::unavailable(
                'A contact is required before a quote can be texted.',
                [
                    'tool' => 'crm.create_contact',
                    'hint' => 'create a contact first, then send the quote by text',
                ],
            ));
        }

        $contact = Contact::query()->find($contactId);
        if ($contact === null) {
            return ToolResult::fail(ToolError::unavailable(
                'A contact is required before a quote can be texted.',
                [
                    'tool' => 'crm.create_contact',
                    'hint' => 'create a contact first, then send the quote by text',
                ],
            ));
        }

        $to = $this->resolveDestination($contact, $ctx);
        if ($to === null) {
            return ToolResult::fail(ToolError::unavailable(
                'No phone on file for this contact.',
                ['hint' => 'this contact has no phone that can receive a text'],
            ));
        }

        if (SuppressionWriter::blocks(Channel::Sms, $to, SendClass::Transactional) !== null) {
            return ToolResult::fail(ToolError::unavailable(
                'This number cannot receive a text.',
                [
                    'tool' => 'agent.escalate',
                    'hint' => 'escalate rather than retry — this number cannot receive a text',
                ],
            ));
        }

        $site = ComposerIdentity::resolveSite($contact);
        if ($site === null) {
            return ToolResult::fail(ToolError::unavailable(
                'No phone on file for this contact.',
                ['hint' => 'this contact has no phone that can receive a text'],
            ));
        }

        $quoted = (new PricingQuoteTool)->handle($principal, $arguments, $ctx);
        if ($quoted->status !== ToolInvocationStatus::Ok) {
            return $quoted;
        }

        try {
            app(SmsSender::class)->send(
                new SmsMessage(to: $to, body: $quoted->display),
                $site,
                $contact,
                SendContext::system([
                    'tool' => $this->key(),
                    'agent_conversation_id' => $ctx?->conversation->id,
                ]),
            );
        } catch (ChannelNotConfigured|ProviderRequestFailed|Throwable) {
            return ToolResult::fail(ToolError::unavailable(
                'The quote could not be sent by text.',
                [
                    'tool' => 'agent.escalate',
                    'hint' => 'escalate rather than retry — the text could not be sent',
                ],
            ));
        }

        return ToolResult::ok(
            ['sent' => true],
            "I've sent the exact quote by text.",
            new FactBag,
            entities: $quoted->entities,
        );
    }

    private function resolveDestination(Contact $contact, ?AgentContext $ctx): ?string
    {
        $channel = VerificationDestination::resolve($contact, 'sms');
        if ($channel instanceof ContactChannel) {
            return $channel->value;
        }

        if ($ctx === null) {
            return null;
        }

        $session = VoiceSession::query()
            ->where('agent_conversation_id', $ctx->conversation->id)
            ->whereNotNull('caller_number')
            ->first();

        $number = trim((string) $session?->caller_number);

        return $number !== '' ? $number : null;
    }
}
