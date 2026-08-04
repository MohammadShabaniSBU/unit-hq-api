<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Models\CommsTriage;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Communications\Contracts\ReceivesInbound;
use App\Support\Communications\HtmlSanitizer;
use App\Support\Communications\ProviderRegistry;
use App\Support\Communications\TriageResolver;
use App\Support\Credentials\CredentialMasker;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class CommsTriageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::InboxView->value);

        $validated = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $page = CommsTriage::query()
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $validated['cursor'] ?? null);

        $data = collect($page->items())
            ->map(fn (CommsTriage $row) => $this->mapSummary($row))
            ->values()
            ->all();

        return $this->cursorPaginated(
            $data,
            optional($page->nextCursor())->encode(),
            'Triage queue retrieved successfully.',
        );
    }

    public function show(CommsTriage $commsTriage, ProviderRegistry $registry): JsonResponse
    {
        Gate::authorize(Permission::InboxView->value, $commsTriage);

        if ($commsTriage->status !== 'pending') {
            return $this->error('Triage row is not pending.', statusCode: 422);
        }

        $summary = $this->mapSummary($commsTriage);
        $summary['body'] = $this->resolveBody($commsTriage, $registry);

        return $this->success($summary, 'Triage item retrieved successfully.');
    }

    public function attach(Request $request, CommsTriage $commsTriage, ProviderRegistry $registry): JsonResponse
    {
        Gate::authorize(Permission::InboxAssign->value, $commsTriage);

        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
        ]);

        $contact = Contact::query()->findOrFail($validated['contact_id']);

        /** @var Employee $actor */
        $actor = $request->user();

        try {
            $message = TriageResolver::attach($commsTriage, $contact, $registry);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        RecordsActivity::log(
            LogChannel::Comms,
            'triage.resolved',
            $commsTriage,
            [
                'how' => 'attach',
                'triage_id' => $commsTriage->id,
                'contact_id' => $contact->id,
                'message_id' => $message->id,
            ],
            causer: $actor,
        );

        return $this->success([
            'triage_id' => $commsTriage->id,
            'message_id' => $message->id,
            'message_thread_id' => $message->message_thread_id,
            'contact_id' => $contact->id,
            'status' => 'resolved',
        ], 'Triage attached.');
    }

    public function createAndAttach(Request $request, CommsTriage $commsTriage, ProviderRegistry $registry): JsonResponse
    {
        Gate::authorize(Permission::InboxAssign->value, $commsTriage);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
        ]);

        /** @var Employee $actor */
        $actor = $request->user();

        try {
            $message = TriageResolver::createAndAttach($commsTriage, $registry, $validated);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        $commsTriage->refresh();

        RecordsActivity::log(
            LogChannel::Comms,
            'triage.resolved',
            $commsTriage,
            [
                'how' => 'create_and_attach',
                'triage_id' => $commsTriage->id,
                'contact_id' => $commsTriage->resolved_contact_id,
                'message_id' => $message->id,
            ],
            causer: $actor,
        );

        return $this->success([
            'triage_id' => $commsTriage->id,
            'message_id' => $message->id,
            'message_thread_id' => $message->message_thread_id,
            'contact_id' => $commsTriage->resolved_contact_id,
            'status' => 'resolved',
        ], 'Contact created and triage attached.');
    }

    public function discard(Request $request, CommsTriage $commsTriage): JsonResponse
    {
        Gate::authorize(Permission::InboxAssign->value, $commsTriage);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        /** @var Employee $actor */
        $actor = $request->user();

        try {
            TriageResolver::discard($commsTriage);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;

        RecordsActivity::log(
            LogChannel::Comms,
            'triage.discarded',
            $commsTriage,
            [
                'triage_id' => $commsTriage->id,
                'reason' => $reason !== '' ? $reason : null,
            ],
            causer: $actor,
        );

        return $this->success([
            'triage_id' => $commsTriage->id,
            'status' => 'discarded',
        ], 'Triage discarded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSummary(CommsTriage $row): array
    {
        /** @var array<string, mixed> $preview */
        $preview = is_array($row->preview) ? $row->preview : [];

        return [
            'id' => $row->id,
            'channel' => $row->channel instanceof \BackedEnum
                ? $row->channel->value
                : (string) $row->channel,
            'sender_value' => $row->sender_value,
            'preview' => [
                'from' => $preview['from'] ?? null,
                'to' => $preview['to'] ?? null,
                'subject' => $preview['subject'] ?? null,
                'body_text' => $preview['body_text'] ?? null,
                'channel' => $preview['channel'] ?? null,
            ],
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{format: string, content: string|null}
     */
    private function resolveBody(CommsTriage $triage, ProviderRegistry $registry): array
    {
        /** @var array<string, mixed> $preview */
        $preview = is_array($triage->preview) ? $triage->preview : [];
        $fallbackText = isset($preview['body_text']) && is_string($preview['body_text'])
            ? $preview['body_text']
            : null;

        try {
            $account = $triage->communicationAccount;
            if ($account === null) {
                return ['format' => 'text', 'content' => $fallbackText];
            }

            /** @var array<string, mixed> $credentials */
            $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
            $credentials = is_array($credentials) ? $credentials : [];

            $adapter = $registry->make($account->channel, $account->provider, $credentials);
            if (! $adapter instanceof ReceivesInbound) {
                return ['format' => 'text', 'content' => $fallbackText];
            }

            /** @var array<string, mixed> $payload */
            $payload = is_array($triage->payload) ? $triage->payload : [];
            $inbound = $adapter->parseInbound($payload);
            if ($inbound === null) {
                return ['format' => 'text', 'content' => $fallbackText];
            }

            $html = HtmlSanitizer::sanitize($inbound->bodyHtml);
            if ($html !== null && $html !== '') {
                return ['format' => 'html', 'content' => $html];
            }

            return [
                'format' => 'text',
                'content' => $inbound->bodyText !== null && $inbound->bodyText !== ''
                    ? $inbound->bodyText
                    : $fallbackText,
            ];
        } catch (RuntimeException) {
            return ['format' => 'text', 'content' => $fallbackText];
        }
    }
}
