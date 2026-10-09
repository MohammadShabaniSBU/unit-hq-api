<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\PlaybookKind;
use App\Models\AgentConversation;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\OfferDelivery;
use Illuminate\Database\Eloquent\Model;

/**
 * Provenance for an outbound send — callers pass this so the message store
 * can stamp source / source_ref and optionally link an OfferDelivery.
 * `class` is required: senders refuse a missing classification.
 */
final readonly class SendContext
{
    /**
     * @param  array<string, mixed>|null  $sourceRef
     */
    public function __construct(
        public MessageSource $source,
        public SendClass $class,
        public ?array $sourceRef = null,
        public ?int $offerDeliveryId = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $sourceRef
     */
    public static function manual(SendClass $class = SendClass::Transactional, ?array $sourceRef = null): self
    {
        return new self(MessageSource::Manual, $class, $sourceRef);
    }

    public static function system(?array $sourceRef = null, SendClass $class = SendClass::Transactional): self
    {
        return new self(MessageSource::System, $class, $sourceRef);
    }

    /**
     * @param  array<string, mixed>|null  $sourceRef
     */
    public static function aiAgent(AgentConversation $conversation, ?int $agentConversationMessageId = null, ?array $sourceRef = null): self
    {
        return new self(
            MessageSource::AiAgent,
            SendClass::Transactional,
            [
                'ai_agent_id' => $conversation->ai_agent_id,
                'agent_conversation_id' => $conversation->id,
                'agent_conversation_message_id' => $agentConversationMessageId,
                ...($sourceRef ?? []),
            ],
        );
    }

    public static function offer(OfferDelivery $delivery): self
    {
        return new self(
            MessageSource::Offer,
            SendClass::Transactional,
            ['offer_delivery_id' => $delivery->id, 'offer_id' => $delivery->offer_id],
            $delivery->id,
        );
    }

    /**
     * Campaign provenance. Sprint 31 introduces the CampaignRecipient model and
     * narrows the parameter; until then the recipient is a nullable reference
     * read by attribute so the factory can land first.
     */
    public static function campaign(?Model $recipient = null, SendClass $class = SendClass::Marketing): self
    {
        return new self(
            MessageSource::Campaign,
            $class,
            [
                'campaign_id' => $recipient?->getAttribute('campaign_id'),
                'campaign_send_id' => $recipient?->getAttribute('campaign_send_id'),
                'campaign_recipient_id' => $recipient?->getKey(),
                'subscription_list_id' => $recipient?->getAttribute('subscription_list_id'),
            ],
        );
    }

    public static function playbook(AutomationRun $run, AutomationRunStep $step): self
    {
        $run->loadMissing('automation.playbook');

        return new self(
            MessageSource::Playbook,
            self::classForPlaybookRun($run),
            [
                'automation_id' => $run->automation_id,
                'automation_run_id' => $run->id,
                'automation_run_step_id' => $step->id,
                'playbook_id' => $run->automation?->playbook_id,
            ],
        );
    }

    public static function automation(AutomationRun $run, AutomationRunStep $step): self
    {
        return new self(
            MessageSource::Automation,
            SendClass::Transactional,
            [
                'automation_id' => $run->automation_id,
                'automation_run_id' => $run->id,
                'automation_run_step_id' => $step->id,
            ],
        );
    }

    /**
     * Pick playbook vs automation based on whether the run's definition is
     * backed by a Playbook row.
     */
    public static function forRun(AutomationRun $run, AutomationRunStep $step): self
    {
        $run->loadMissing('automation.playbook');

        if ($run->automation?->playbook_id !== null) {
            return self::playbook($run, $step);
        }

        return self::automation($run, $step);
    }

    private static function classForPlaybookRun(AutomationRun $run): SendClass
    {
        $kind = $run->automation?->playbook?->kind;

        return $kind === PlaybookKind::LeadChase
            ? SendClass::Marketing
            : SendClass::Transactional;
    }
}
