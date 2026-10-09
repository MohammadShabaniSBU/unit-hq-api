<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentRuleException;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ConsentWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ContactConsentController extends Controller
{
    public function index(Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value, $contact);

        $history = ConsentEvent::query()
            ->where('contact_id', $contact->id)
            ->with('subscriptionList:id,key')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ConsentEvent $event): array => $this->eventPayload($event))
            ->all();

        $lists = SubscriptionList::query()
            ->whereNull('archived_at')
            ->orderBy('key')
            ->get(['id', 'key', 'labels', 'channels'])
            ->map(fn (SubscriptionList $list): array => [
                'id' => $list->id,
                'key' => $list->key,
                'labels' => $list->labels,
                'channels' => $list->channels,
            ])
            ->all();

        return $this->success([
            'state' => ConsentState::for($contact)->all(),
            'history' => $history,
            'lists' => $lists,
        ], 'Consents retrieved successfully.');
    }

    public function store(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ConsentManage->value, $contact);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in([
                Channel::Email->value,
                Channel::Sms->value,
                Channel::Whatsapp->value,
            ])],
            'subscription_list_id' => ['required', 'integer', 'exists:subscription_lists,id'],
            'action' => ['required', 'string', Rule::in([
                ConsentAction::Granted->value,
                ConsentAction::Withdrawn->value,
            ])],
            'consent_text_id' => ['nullable', 'integer', 'exists:consent_texts,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $list = SubscriptionList::query()->findOrFail($validated['subscription_list_id']);
        $channel = Channel::from($validated['channel']);
        $action = ConsentAction::from($validated['action']);
        $note = trim((string) ($validated['note'] ?? ''));
        $textId = isset($validated['consent_text_id']) ? (int) $validated['consent_text_id'] : null;
        $evidence = $note !== '' ? ['note' => $note] : null;

        try {
            $event = $action === ConsentAction::Granted
                ? ConsentWriter::grant(
                    $contact,
                    $channel,
                    $list,
                    ConsentSource::Operator,
                    $textId,
                    $evidence,
                    $employee,
                )
                : ConsentWriter::withdraw(
                    $contact,
                    $channel,
                    $list,
                    ConsentSource::Operator,
                    $evidence,
                    $employee,
                );
        } catch (ConsentRuleException $exception) {
            return $this->error(__('errors.consent.'.$exception->errorKey), statusCode: 422);
        }

        $event->load('subscriptionList:id,key');

        return $this->created($this->eventPayload($event), 'Consent recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(ConsentEvent $event): array
    {
        $note = is_array($event->evidence) ? ($event->evidence['note'] ?? null) : null;

        return [
            'id' => $event->id,
            'list' => [
                'id' => $event->subscription_list_id,
                'key' => $event->subscriptionList?->key,
            ],
            'channel' => $event->channel->value,
            'action' => $event->action->value,
            'source' => $event->source->value,
            'consent_text_id' => $event->consent_text_id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'note' => is_string($note) ? $note : null,
        ];
    }
}
