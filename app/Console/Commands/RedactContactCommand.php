<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\SystemEvent;
use App\Support\RecordsActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class RedactContactCommand extends Command
{
    protected $signature = 'contacts:redact {contact : Contact id to redact from logs}';

    protected $description = 'Null allowlisted PII keys in activity_log and system_events for a contact; log contact.redacted';

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

        RecordsActivity::core('contact.redacted', $contact, [
            'activity_rows' => $activityCount,
            'system_event_rows' => $systemCount,
        ]);

        $this->info("Redacted {$activityCount} activity row(s) and {$systemCount} system_event row(s). Logged contact.redacted.");

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
