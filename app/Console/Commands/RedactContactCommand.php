<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Contact;
use App\Models\SystemEvent;
use App\Support\RecordsActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class RedactContactCommand extends Command
{
    protected $signature = 'contacts:redact {contact : Contact id to redact from logs}';

    protected $description = 'Null allowlisted PII keys in activity_log, system_events, and automation run payloads for a contact; log contact.redacted';

    public function handle(): int
    {
        $contactId = (int) $this->argument('contact');
        $contact = Contact::withoutGlobalScopes()->find($contactId);

        if ($contact === null) {
            $this->error("Contact {$contactId} not found.");

            return self::FAILURE;
        }

        $keys = config('redaction.property_keys', []);
        $activityCount = 0;
        $systemCount = 0;
        $automationCount = 0;

        $activities = Activity::query()
            ->where(function ($q) use ($contact): void {
                $q->where(function ($inner) use ($contact): void {
                    $inner->where('subject_type', $contact->getMorphClass())
                        ->where('subject_id', $contact->id);
                })->orWhere(function ($inner) use ($contact): void {
                    $inner->where('subject_type', Contact::class)
                        ->where('subject_id', $contact->id);
                });
            })
            ->get();

        foreach ($activities as $activity) {
            $properties = $activity->properties?->toArray() ?? [];
            $redacted = $this->nullKeys($properties, $keys);
            if ($redacted !== $properties) {
                $activity->properties = collect($redacted);
                $activity->save();
                $activityCount++;
            }
        }

        $events = SystemEvent::query()
            ->where(function ($q) use ($contact): void {
                $q->where(function ($inner) use ($contact): void {
                    $inner->where('subject_type', $contact->getMorphClass())
                        ->where('subject_id', $contact->id);
                })->orWhere(function ($inner) use ($contact): void {
                    $inner->where('subject_type', Contact::class)
                        ->where('subject_id', $contact->id);
                });
            })
            ->get();

        foreach ($events as $event) {
            $payload = $event->payload ?? [];
            $redacted = $this->nullKeys($payload, $keys);
            if ($redacted !== $payload) {
                $event->payload = $redacted;
                $event->save();
                $systemCount++;
            }
        }

        $runs = AutomationRun::query()
            ->where(function ($q) use ($contact): void {
                $q->where(function ($inner) use ($contact): void {
                    $inner->where('subject_type', $contact->getMorphClass())
                        ->where('subject_id', $contact->id);
                })->orWhere(function ($inner) use ($contact): void {
                    $inner->where('subject_type', Contact::class)
                        ->where('subject_id', $contact->id);
                });
            })
            ->with('steps')
            ->get();

        foreach ($runs as $run) {
            $payload = $run->trigger_payload ?? [];
            $redactedPayload = $this->nullKeys($payload, $keys);
            if ($redactedPayload !== $payload) {
                $run->trigger_payload = $redactedPayload;
                $run->save();
                $automationCount++;
            }

            foreach ($run->steps as $step) {
                /** @var AutomationRunStep $step */
                $input = $step->input ?? [];
                $output = $step->output ?? [];
                $redactedInput = $this->nullKeys($input, $keys);
                $redactedOutput = $this->nullKeys($output, $keys);
                if ($redactedInput !== $input || $redactedOutput !== $output) {
                    $step->input = $redactedInput;
                    $step->output = $redactedOutput;
                    $step->save();
                    $automationCount++;
                }
            }
        }

        RecordsActivity::core('contact.redacted', $contact, [
            'activity_rows' => $activityCount,
            'system_event_rows' => $systemCount,
            'automation_rows' => $automationCount,
        ]);

        $this->info("Redacted {$activityCount} activity, {$systemCount} system_event, {$automationCount} automation row(s). Logged contact.redacted.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function nullKeys(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (Arr::has($data, $key)) {
                Arr::set($data, $key, null);
            }
        }

        return $data;
    }
}
