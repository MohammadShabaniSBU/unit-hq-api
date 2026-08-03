<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\ContractStatus;
use App\Models\AccessPoint;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\Comms\ContentLibrary;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Probabilistic texture after jobs: inbound volume, delivery lattice, light calls.
 * Sundays skip (realism + runtime lever).
 */
final class DayTexture
{
    private readonly ContentLibrary $library;

    private int $attachmentBudget = 3;

    /** Bea already earns one hard-bounce; texture supplies one more bounce + one complaint. */
    private int $extraHardBounces = 1;

    private int $complaintBudget = 1;

    private int $failedBudget = 4;

    private int $callBudgetRemaining;

    public function __construct(
        private readonly DemoRng $rng,
        private readonly int $maxInboundPerDay = 4,
        private readonly int $maxDoorEventsPerDay = 1,
        int $callBudget = 22,
    ) {
        $this->library = new ContentLibrary($rng);
        $this->callBudgetRemaining = $callBudget;
    }

    public function run(CarbonImmutable $date, DemoWorld $world): void
    {
        if ($date->isSunday()) {
            return;
        }

        $this->backfillDeliveries($world);

        $inboundBudget = $this->rng->int(1, $this->maxInboundPerDay);
        for ($i = 0; $i < $inboundBudget; $i++) {
            $this->maybeInbound($world, $date);
        }

        $doorBudget = $this->rng->int(0, $this->maxDoorEventsPerDay);
        for ($i = 0; $i < $doorBudget; $i++) {
            $this->maybeDoor($world);
        }

        if ($this->callBudgetRemaining > 0 && $this->rng->bool(0.08)) {
            $this->maybeCall($world, $date);
        }

        // Near seed-end, force remaining delivery lattice outcomes so suppressions land.
        $end = CarbonImmutable::parse(CastExecutor::SIM_END)->startOfDay();
        if ($date->diffInDays($end) <= 3) {
            $this->forceRemainingLattice($world);
        }
    }

    private function forceRemainingLattice(DemoWorld $world): void
    {
        while ($this->extraHardBounces > 0 || $this->complaintBudget > 0 || $this->failedBudget > 0) {
            $message = Message::query()
                ->where('direction', MessageDirection::Outbound)
                ->where('status', MessageStatus::Sent)
                ->where('provider', Provider::Brevo)
                ->whereNotNull('provider_message_id')
                ->orderBy('id')
                ->first();

            if ($message === null) {
                break;
            }

            if ($this->extraHardBounces > 0) {
                $world->delivery()->event($message, 'hard_bounce');
                $this->extraHardBounces--;

                continue;
            }

            if ($this->complaintBudget > 0) {
                $world->delivery()->event($message, 'spam');
                $this->complaintBudget--;

                continue;
            }

            $world->delivery()->event($message, 'failed');
            $this->failedBudget--;
        }
    }

    private function maybeInbound(DemoWorld $world, CarbonImmutable $date): void
    {
        $handle = $this->pickCrowdTenantHandle($world);
        if ($handle === null) {
            return;
        }

        $channel = $this->rng->pick(['sms', 'email', 'whatsapp']);

        if ($channel === 'email' && $this->attachmentBudget > 0 && $this->rng->bool(0.04)) {
            $attachments = match ($this->attachmentBudget) {
                3 => $this->library->dniAttachment(),
                2 => $this->library->photoAttachment(),
                default => $this->library->oversizeAttachment(),
            };
            $this->attachmentBudget--;
            $email = (string) $world->get("{$handle}.email");
            $thread = $world->has("{$handle}.email_thread")
                ? $world->get("{$handle}.email_thread")
                : null;
            $world->inbound()->email(
                $email,
                $this->library->emailBody('document'),
                $thread instanceof MessageThread ? $thread : null,
                $attachments,
            );

            return;
        }

        $body = match ($channel) {
            'sms' => $this->library->smsBody(),
            'whatsapp' => $this->library->whatsappBody(),
            default => $this->library->emailBody(),
        };

        match ($channel) {
            'sms' => JourneySupport::inboundSms($world, $handle, $body),
            'whatsapp' => JourneySupport::inboundWhatsApp($world, $handle, $body),
            default => JourneySupport::inboundEmail($world, $handle, $body),
        };

        // Occasional desk reply on email/SMS threads.
        if ($this->rng->bool(0.18) && ($channel === 'email' || $channel === 'sms')) {
            if ($channel === 'email') {
                JourneySupport::sendEmail(
                    $world,
                    $handle,
                    'Re: su mensaje',
                    'Gracias por escribirnos. Quedamos atentos — Unit HQ.',
                );
            } else {
                JourneySupport::sendSms($world, $handle, 'Recibido, gracias. Unit HQ');
            }
        }

        unset($date);
    }

    private function backfillDeliveries(DemoWorld $world): void
    {
        $pending = Message::query()
            ->where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Sent)
            ->where('provider', Provider::Brevo)
            ->whereNotNull('provider_message_id')
            ->where('created_at', '<=', now()->subHour())
            ->orderBy('id')
            ->limit(12)
            ->get();

        foreach ($pending as $message) {
            $roll = $this->rng->int(1, 100);

            if ($this->extraHardBounces > 0 && $roll <= 3) {
                $world->delivery()->event($message, 'hard_bounce');
                $this->extraHardBounces--;

                continue;
            }

            if ($this->complaintBudget > 0 && $roll <= 6) {
                $world->delivery()->event($message, 'spam');
                $this->complaintBudget--;

                continue;
            }

            if ($this->failedBudget > 0 && $roll <= 10) {
                $world->delivery()->event($message, 'failed');
                $this->failedBudget--;

                continue;
            }

            $world->delivery()->event($message, 'delivered');

            if ($roll >= 93) {
                $world->delivery()->event($message->fresh() ?? $message, 'opened');
            }
        }
    }

    private function maybeCall(DemoWorld $world, CarbonImmutable $date): void
    {
        $handle = $this->pickCrowdTenantHandle($world);
        if ($handle === null) {
            return;
        }

        $phone = (string) $world->get("{$handle}.phone");
        $contact = $world->contact("{$handle}.contact");
        $kind = $this->rng->pickWeighted([
            'answered_promise' => 15,
            'answered_other' => 35,
            'missed' => 25,
            'voicemail' => 15,
            'outbound' => 10,
        ]);

        $employee = Employee::query()->orderBy('id')->first();

        match ($kind) {
            'answered_promise' => $world->aircall()->wrapup(
                $world->aircall()->answeredInbound($phone),
                'payment_promised',
                'Crowd texture promise',
                $employee,
            ),
            'answered_other' => $world->aircall()->wrapup(
                $world->aircall()->answeredInbound($phone),
                $this->rng->pick(['reached', 'callback_requested', 'resolved', 'other']),
                'Crowd texture call',
                $employee,
            ),
            'missed' => $world->aircall()->missedInbound($phone),
            'voicemail' => $world->aircall()->wrapup(
                $world->aircall()->voicemail($phone),
                'voicemail_left',
                'Crowd voicemail',
                $employee,
            ),
            default => (static function () use ($world, $contact, $phone, $employee): void {
                $intent = $world->aircall()->requestIntent($contact, $phone, $employee);
                $message = $world->aircall()->answeredOutbound($phone, $intent);
                $world->aircall()->wrapup($message, 'reached', 'Outbound texture', $employee);
            })(),
        };

        $this->callBudgetRemaining--;
        unset($date);
    }

    private function maybeDoor(DemoWorld $world): void
    {
        $tenants = Contact::query()
            ->where('source_detail', 'demo_crowd')
            ->where('status', 'tenant')
            ->orderBy('id')
            ->pluck('id');
        if ($tenants->isEmpty()) {
            return;
        }

        $id = $tenants[$this->rng->int(0, $tenants->count() - 1)];
        $contact = Contact::query()->find($id);
        if ($contact === null) {
            return;
        }

        $point = AccessPoint::query()->orderBy('id')->first();
        $pointRef = $point?->provider_point_id ?? 'fake-gate-1';

        $world->access()->doorEvent($pointRef, 'granted', $contact);
        $world->remember('jobs.force_access', true);
    }

    private function pickCrowdTenantHandle(DemoWorld $world): ?string
    {
        $handles = [];
        foreach ($world->payerEntries() as $entry) {
            $handle = $entry['handle'];
            if (! str_starts_with($handle, 'crowd_')) {
                continue;
            }
            if (! $world->has("{$handle}.contract")) {
                continue;
            }
            $contract = $world->get("{$handle}.contract");
            if (! $contract instanceof Contract) {
                continue;
            }
            $status = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::tryFrom((string) $contract->status);
            if ($status === ContractStatus::Active || $status === ContractStatus::NoticeGiven) {
                $handles[] = $handle;
            }
        }
        if ($handles === []) {
            return null;
        }

        return $handles[$this->rng->int(0, count($handles) - 1)];
    }
}
