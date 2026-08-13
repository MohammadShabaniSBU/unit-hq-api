<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\AiSummary;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\CallWrapup;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SystemEvent;
use App\Support\Communications\Channel;
use App\Support\RecordsActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class RedactContactCommand extends Command
{
    protected $signature = 'contacts:redact {contact : Contact id to redact from logs}';

    protected $description = 'Null allowlisted PII keys in activity_log, system_events, and automation run payloads for a contact; null ai_summaries content for the contact and its deals; log contact.redacted';

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

        $wrapupCount = $this->redactCallWrapupsAndRecordings($contact);
        $summaryCount = $this->redactAiSummaries($contact);

        RecordsActivity::core('contact.redacted', $contact, [
            'activity_rows' => $activityCount,
            'system_event_rows' => $systemCount,
            'automation_rows' => $automationCount,
            'call_wrapup_rows' => $wrapupCount,
            'ai_summary_rows' => $summaryCount,
        ]);

        $this->info("Redacted {$activityCount} activity, {$systemCount} system_event, {$automationCount} automation, {$wrapupCount} call wrap-up/recording, {$summaryCount} ai_summary row(s). Logged contact.redacted.");

        return self::SUCCESS;
    }

    private function redactCallWrapupsAndRecordings(Contact $contact): int
    {
        $threadIds = MessageThread::query()
            ->where('contact_id', $contact->id)
            ->where('channel', Channel::Call)
            ->pluck('id');

        if ($threadIds->isEmpty()) {
            return 0;
        }

        $messages = Message::query()
            ->whereIn('message_thread_id', $threadIds)
            ->get();

        $count = 0;
        foreach ($messages as $message) {
            $changed = false;

            $wrapup = CallWrapup::query()->where('message_id', $message->id)->first();
            if ($wrapup !== null && $wrapup->note !== null) {
                $wrapup->forceFill(['note' => null])->save();
                $changed = true;
            }

            $ref = is_array($message->source_ref) ? $message->source_ref : [];
            $mediaChanged = false;
            foreach (['recording_url', 'voicemail_url'] as $key) {
                if (isset($ref[$key]) && $ref[$key] !== null) {
                    $ref[$key] = null;
                    $mediaChanged = true;
                }
            }
            if (isset($ref['call']) && is_array($ref['call'])) {
                foreach (['recording', 'voicemail', 'recording_short_url', 'voicemail_short_url'] as $key) {
                    if (isset($ref['call'][$key]) && $ref['call'][$key] !== null) {
                        $ref['call'][$key] = null;
                        $mediaChanged = true;
                    }
                }
            }
            if (($ref['recording_redacted'] ?? false) !== true) {
                $ref['recording_redacted'] = true;
                $mediaChanged = true;
            }

            if ($mediaChanged) {
                $message->forceFill(['source_ref' => $ref])->save();
                $changed = true;
            }

            if ($changed) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Null summary content for the contact and every deal belonging to it.
     * Rows are retained; body/highlights/source_counts/error_code are destroyed.
     */
    private function redactAiSummaries(Contact $contact): int
    {
        $dealIds = Deal::query()
            ->where('contact_id', $contact->id)
            ->pluck('id')
            ->all();

        $query = AiSummary::query()->where(function ($q) use ($contact, $dealIds): void {
            $q->where(function ($inner) use ($contact): void {
                $inner->where('summarizable_type', $contact->getMorphClass())
                    ->where('summarizable_id', $contact->id);
            });

            if ($dealIds !== []) {
                $q->orWhere(function ($inner) use ($dealIds): void {
                    $inner->where('summarizable_type', 'deal')
                        ->whereIn('summarizable_id', $dealIds);
                });
            }
        });

        $count = 0;
        foreach ($query->cursor() as $summary) {
            /** @var AiSummary $summary */
            $changed = $summary->body !== null
                || $summary->highlights !== null
                || $summary->source_counts !== null
                || $summary->error_code !== null;

            if (! $changed) {
                continue;
            }

            $summary->forceFill([
                'body' => null,
                'highlights' => null,
                'source_counts' => null,
                'error_code' => null,
            ])->save();
            $count++;
        }

        return $count;
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
