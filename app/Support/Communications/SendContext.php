<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\OfferDelivery;

/**
 * Provenance for an outbound send — callers pass this so the message store
 * can stamp source / source_ref and optionally link an OfferDelivery.
 */
final readonly class SendContext
{
    /**
     * @param  array<string, mixed>|null  $sourceRef
     */
    public function __construct(
        public MessageSource $source,
        public ?array $sourceRef = null,
        public ?int $offerDeliveryId = null,
    ) {}

    public static function manual(): self
    {
        return new self(MessageSource::Manual);
    }

    public static function system(?array $sourceRef = null): self
    {
        return new self(MessageSource::System, $sourceRef);
    }

    public static function offer(OfferDelivery $delivery): self
    {
        return new self(
            MessageSource::Offer,
            ['offer_delivery_id' => $delivery->id, 'offer_id' => $delivery->offer_id],
            $delivery->id,
        );
    }

    public static function playbook(AutomationRun $run, AutomationRunStep $step): self
    {
        $run->loadMissing('automation');

        return new self(
            MessageSource::Playbook,
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
        $run->loadMissing('automation');

        if ($run->automation?->playbook_id !== null) {
            return self::playbook($run, $step);
        }

        return self::automation($run, $step);
    }
}
