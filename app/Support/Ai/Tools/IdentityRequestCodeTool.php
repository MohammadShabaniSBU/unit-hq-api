<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\ContactVerification;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Identity\MaskedDestination;
use App\Support\Ai\Identity\VerificationChallenge;
use App\Support\Ai\Identity\VerificationDestination;
use App\Support\Communications\Channel;
use App\Support\Communications\ComposerIdentity;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use Throwable;

final class IdentityRequestCodeTool implements AgentTool
{
    /**
     * Destination is never an argument. channel_type is a type preference
     * only — never a value. A request naming an address is dropped at the
     * schema gate because no such argument exists.
     */
    public function key(): string
    {
        return 'identity.request_code';
    }

    public function description(): string
    {
        return 'Send a one-time verification code to a channel already on file for this contact. Never supply a destination address.';
    }

    public function schema(): array
    {
        return [
            'channel_type' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['email', 'sms'],
                'description' => 'Optional type preference (email or sms). Never a destination value.',
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
        if ($principal->contactId === null) {
            return ToolResult::error('This tool requires a contact principal.');
        }

        $contact = Contact::query()->find($principal->contactId);
        if ($contact === null) {
            return ToolResult::error('Contact was not found.');
        }

        $preference = isset($arguments['channel_type']) ? (string) $arguments['channel_type'] : null;
        $channel = VerificationDestination::resolve($contact, $preference);
        if ($channel === null) {
            return $this->escalate('No on-file channel can receive a verification code.');
        }

        // Fail closed on the destination, not the site. A suppressed
        // all-scope address cannot receive a code — do not send, do not issue.
        if (VerificationDestination::isSuppressed($channel)) {
            return $this->escalate('This address cannot receive a verification code.');
        }

        $site = ComposerIdentity::resolveSite($contact);
        if ($site === null) {
            return $this->escalate('No on-file channel can receive a verification code.');
        }

        $issued = VerificationChallenge::issue(
            $contact,
            $channel,
            $ctx?->conversation->id,
            $site->id,
        );
        if ($issued['ok'] === false) {
            return $this->escalate('A verification code cannot be sent right now.');
        }

        try {
            $this->deliver($issued['row'], $issued['code'], $channel, $contact, $site);
        } catch (ChannelNotConfigured|ProviderRequestFailed|Throwable) {
            VerificationChallenge::close($issued['row']);

            return $this->escalate('A verification code cannot be sent right now.');
        }

        $masked = MaskedDestination::mask($channel);
        $facts = MaskedDestination::license(new FactBag, $masked, $channel->value);

        return ToolResult::ok(
            [
                'destination_masked' => $masked,
                'channel_type' => VerificationDestination::preferenceOf($channel),
            ],
            "A verification code was sent to {$masked}.",
            $facts,
        );
    }

    private function deliver(
        ContactVerification $row,
        string $code,
        ContactChannel $channel,
        Contact $contact,
        Site $site,
    ): void {
        $minutes = (int) config('agents.verification.ttl_minutes', 10);
        $body = "Your verification code is {$code}. It expires in {$minutes} minutes.";
        $context = SendContext::system([
            'contact_verification_id' => $row->id,
        ]);

        if (VerificationDestination::deliveryChannel($channel) === Channel::Email) {
            app(EmailSender::class)->send(
                new EmailMessage(
                    to: [new EmailAddress($channel->value)],
                    subject: 'Your verification code',
                    html: $body,
                    text: $body,
                ),
                $site,
                $contact,
                $context,
            );

            return;
        }

        app(SmsSender::class)->send(
            new SmsMessage(to: $channel->value, body: $body),
            $site,
            $contact,
            $context,
        );
    }

    private function escalate(string $message): ToolResult
    {
        return ToolResult::fail(ToolError::unavailable($message, [
            'tool' => 'agent.escalate',
            'hint' => 'escalate rather than retry — this address cannot receive a verification code',
        ]));
    }
}
