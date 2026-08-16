<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\VerificationLevel;

/**
 * Who the agent is talking to. Rebuilt from conversation facts each turn (D-AI-1).
 * Never resolve from auth(), request(), or the container.
 */
final readonly class AgentPrincipal
{
    private function __construct(
        public AgentAudience $audience,
        public VerificationLevel $verification,
        public ?int $contactId,
        public ?int $employeeId,
        public ?int $siteId,
        public string $locale,
    ) {}

    public static function anonymous(?int $siteId, string $locale): self
    {
        return new self(
            AgentAudience::Customer,
            VerificationLevel::Anonymous,
            null,
            null,
            $siteId,
            $locale,
        );
    }

    public static function channelAsserted(int $contactId, ?int $siteId, string $locale): self
    {
        return new self(
            AgentAudience::Customer,
            VerificationLevel::ChannelAsserted,
            $contactId,
            null,
            $siteId,
            $locale,
        );
    }

    public static function verified(int $contactId, ?int $siteId, string $locale): self
    {
        return new self(
            AgentAudience::Customer,
            VerificationLevel::Verified,
            $contactId,
            null,
            $siteId,
            $locale,
        );
    }

    public static function employee(int $employeeId, ?int $siteId, string $locale): self
    {
        return new self(
            AgentAudience::Internal,
            VerificationLevel::Verified,
            null,
            $employeeId,
            $siteId,
            $locale,
        );
    }

    public function ownsContact(int $contactId): bool
    {
        return $this->contactId !== null && $this->contactId === $contactId;
    }
}
