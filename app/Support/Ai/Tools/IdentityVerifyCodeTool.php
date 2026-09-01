<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Identity\VerificationChallenge;

final class IdentityVerifyCodeTool implements AgentTool
{
    public function key(): string
    {
        return 'identity.verify_code';
    }

    public function description(): string
    {
        return 'Confirm a one-time verification code sent to a channel on file for this contact.';
    }

    public function schema(): array
    {
        return [
            'code' => [
                'type' => 'string',
                'required' => true,
                'description' => 'The verification code the customer received',
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
            return $this->invalid();
        }

        $contact = Contact::query()->find($principal->contactId);
        if ($contact === null) {
            return $this->invalid();
        }

        $code = trim((string) $arguments['code']);
        $outcome = VerificationChallenge::verify($contact, $code);
        if ($outcome['ok'] === false) {
            return $this->invalid();
        }

        return ToolResult::ok(
            ['ok' => true, 'reason' => 'verified'],
            'Identity verified.',
            new FactBag,
        );
    }

    private function invalid(): ToolResult
    {
        return ToolResult::fail(ToolError::unavailable(
            'Verification failed.',
            [
                'tool' => 'identity.request_code',
                'hint' => 'ask the customer for the code again, or request a new one',
            ],
        ));
    }
}
