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
use App\Support\Ai\QuoteDelivery;
use App\Support\Communications\Channel;
use App\Support\Communications\ComposerIdentity;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\SuppressionWriter;
use Throwable;

/**
 * Sends a catalogue quote to a server-resolved destination.
 * Display contains no figure so this result does not license money tokens
 * the spoken reply did not already earn from pricing.quote.
 */
final class SalesSendQuoteTool implements AgentTool
{
    public function key(): string
    {
        return 'sales.send_quote';
    }

    public function description(): string
    {
        return 'Send the exact catalogue quote to the contact by text or email after speaking the figures that matter. Never supply a destination.';
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
            'via' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['sms', 'email'],
                'description' => 'Optional delivery preference (sms or email). Never a destination value.',
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
                'A contact is required before a quote can be sent.',
                [
                    'tool' => 'crm.create_contact',
                    'hint' => 'create a contact first, then send the quote',
                ],
            ));
        }

        $contact = Contact::query()->find($contactId);
        if ($contact === null) {
            return ToolResult::fail(ToolError::unavailable(
                'A contact is required before a quote can be sent.',
                [
                    'tool' => 'crm.create_contact',
                    'hint' => 'create a contact first, then send the quote',
                ],
            ));
        }

        $via = isset($arguments['via']) ? trim((string) $arguments['via']) : '';
        $via = $via !== '' ? $via : null;
        $route = $this->resolveRoute($contact, $ctx, $via);
        if ($route === null) {
            return $this->missingDestination($contact, $ctx, $via);
        }

        [$channel, $to] = $route;

        if (SuppressionWriter::blocks($channel, $to, SendClass::Transactional) !== null) {
            return $this->suppressed($channel);
        }

        $site = ComposerIdentity::resolveSite($contact);
        if ($site === null) {
            return $this->missingDestination($contact, $ctx, $via);
        }

        $quoted = (new PricingQuoteTool)->handle($principal, $arguments, $ctx, preferWrittenQuote: true);
        if ($quoted->status !== ToolInvocationStatus::Ok) {
            return $quoted;
        }

        $locale = $ctx?->conversation->locale ?? $principal->locale;

        try {
            $result = app(QuoteDelivery::class)->send(
                $channel,
                $to,
                $quoted->display,
                $site,
                $contact,
                SendContext::system([
                    'tool' => $this->key(),
                    'agent_conversation_id' => $ctx?->conversation->id,
                ]),
                $locale,
            );
        } catch (ChannelNotConfigured|ProviderRequestFailed|Throwable) {
            return $this->sendFailed($channel);
        }

        if ($result->wasSuppressed()) {
            return $this->suppressed($channel);
        }

        return ToolResult::ok(
            ['sent' => true, 'via' => $channel->value],
            $channel === Channel::Email
                ? "I've sent the exact quote by email."
                : "I've sent the exact quote by text.",
            new FactBag,
            entities: $quoted->entities,
        );
    }

    /**
     * @return array{0: Channel, 1: string}|null
     */
    private function resolveRoute(Contact $contact, ?AgentContext $ctx, ?string $via): ?array
    {
        if ($via === 'email') {
            return $this->emailRoute($contact);
        }

        if ($via === 'sms') {
            return $this->smsRoute($contact, $ctx);
        }

        return $this->smsRoute($contact, $ctx) ?? $this->emailRoute($contact);
    }

    /**
     * @return array{0: Channel, 1: string}|null
     */
    private function smsRoute(Contact $contact, ?AgentContext $ctx): ?array
    {
        $channel = VerificationDestination::resolve($contact, 'sms');
        if ($channel instanceof ContactChannel) {
            return [Channel::Sms, $channel->value];
        }

        $number = $this->sessionCallerNumber($ctx);

        return $number !== null ? [Channel::Sms, $number] : null;
    }

    /**
     * @return array{0: Channel, 1: string}|null
     */
    private function emailRoute(Contact $contact): ?array
    {
        $channel = VerificationDestination::resolve($contact, 'email');
        if ($channel instanceof ContactChannel) {
            return [Channel::Email, $channel->value];
        }

        return null;
    }

    private function sessionCallerNumber(?AgentContext $ctx): ?string
    {
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

    private function missingDestination(Contact $contact, ?AgentContext $ctx, ?string $via): ToolResult
    {
        if ($via === 'email') {
            $hint = $this->smsRoute($contact, $ctx) !== null
                ? 'this contact has no email; send the quote by text instead'
                : 'this contact has no email that can receive a quote';

            return ToolResult::fail(ToolError::unavailable(
                'No email on file for this contact.',
                ['hint' => $hint],
            ));
        }

        if ($via === 'sms') {
            return ToolResult::fail(ToolError::unavailable(
                'No phone on file for this contact.',
                ['hint' => 'this contact has no phone that can receive a text'],
            ));
        }

        return ToolResult::fail(ToolError::unavailable(
            'No phone or email on file for this contact.',
            ['hint' => 'this contact has no phone or email that can receive a quote'],
        ));
    }

    private function suppressed(Channel $channel): ToolResult
    {
        $noun = $channel === Channel::Email ? 'address' : 'number';
        $verb = $channel === Channel::Email ? 'an email' : 'a text';

        return ToolResult::fail(ToolError::unavailable(
            "This {$noun} cannot receive {$verb}.",
            [
                'tool' => 'agent.escalate',
                'hint' => "escalate rather than retry — this {$noun} cannot receive {$verb}",
            ],
        ));
    }

    private function sendFailed(Channel $channel): ToolResult
    {
        $how = $channel === Channel::Email ? 'email' : 'text';

        return ToolResult::fail(ToolError::unavailable(
            "The quote could not be sent by {$how}.",
            [
                'tool' => 'agent.escalate',
                'hint' => "escalate rather than retry — the {$how} could not be sent",
            ],
        ));
    }
}
