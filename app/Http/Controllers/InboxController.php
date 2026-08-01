<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Models\CommsTriage;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use App\Support\Communications\HtmlSanitizer;
use App\Support\Communications\InboxThreadQuery;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Inbox read surface + small writes (mark-read, assign). Sending is S11-01.
 *
 * Assign is any authenticated Employee until S17 RBAC (10-open-decisions.md).
 */
class InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['sometimes', 'nullable', Rule::in(['email', 'sms', 'call'])],
            'filter' => ['sometimes', Rule::in(['mine', 'unassigned', 'all'])],
            'unread' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cursor' => ['sometimes', 'nullable', 'string'],
            'updated_after' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Employee $viewer */
        $viewer = $request->user();

        $channel = isset($validated['channel']) && $validated['channel'] !== null
            ? Channel::from($validated['channel'])
            : null;

        $filter = $validated['filter'] ?? 'all';
        $unreadOnly = $request->boolean('unread');
        $q = isset($validated['q']) ? trim((string) $validated['q']) : null;
        $updatedAfter = isset($validated['updated_after'])
            ? Carbon::parse($validated['updated_after'])
            : null;
        $perPage = (int) ($validated['per_page'] ?? InboxThreadQuery::DEFAULT_PER_PAGE);

        $page = InboxThreadQuery::filtered(
            $channel,
            $filter,
            $unreadOnly,
            $q !== '' ? $q : null,
            $updatedAfter,
            $viewer,
        )->cursorPaginate($perPage, ['*'], 'cursor', $validated['cursor'] ?? null);

        $threads = collect($page->items());
        $suppressedMap = InboxThreadQuery::suppressedMap($threads);

        $data = $threads
            ->map(fn (MessageThread $thread) => InboxThreadQuery::summarize($thread, $suppressedMap))
            ->values()
            ->all();

        return $this->cursorPaginated(
            $data,
            optional($page->nextCursor())->encode(),
            'Inbox threads retrieved successfully.',
        );
    }

    public function show(Request $request, MessageThread $messageThread): JsonResponse
    {
        $validated = $request->validate([
            'before' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? InboxThreadQuery::DEFAULT_PER_PAGE);
        $before = isset($validated['before']) ? (int) $validated['before'] : null;

        $messageThread->load(['contact.channels', 'assignee']);

        // Attach preview attributes from the latest message for ThreadSummary shape.
        $latest = Message::query()
            ->where('message_thread_id', $messageThread->id)
            ->orderByDesc('id')
            ->first();

        if ($latest !== null) {
            $messageThread->setAttribute('preview_direction', $latest->direction?->value ?? (string) $latest->direction);
            $messageThread->setAttribute('preview_status', $latest->status?->value ?? (string) $latest->status);
            $messageThread->setAttribute('preview_body_text', $latest->body_text);
            $messageThread->setAttribute('preview_body_html', $latest->body_html);
            $messageThread->setAttribute(
                'preview_at',
                ($latest->sent_at ?? $latest->created_at)?->toIso8601String(),
            );
        }

        $suppressedMap = InboxThreadQuery::suppressedMap(collect([$messageThread]));
        $summary = InboxThreadQuery::summarize($messageThread, $suppressedMap);

        $messagesQuery = Message::query()
            ->where('message_thread_id', $messageThread->id)
            ->with('attachments')
            ->orderByDesc('id')
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
            ->limit($perPage + 1);

        $messages = $messagesQuery->get();
        $hasMore = $messages->count() > $perPage;
        if ($hasMore) {
            $messages = $messages->take($perPage);
        }

        $nextBefore = $hasMore ? (string) $messages->last()?->id : null;

        $summary['messages'] = $messages
            ->map(fn (Message $message) => $this->mapMessage($message))
            ->values()
            ->all();
        $summary['meta'] = [
            'next_before' => $nextBefore,
        ];

        return $this->success($summary, 'Inbox thread retrieved successfully.');
    }

    public function badge(): JsonResponse
    {
        return $this->success([
            'unread_threads' => MessageThread::query()->where('unread_count', '>', 0)->count(),
            'triage_count' => CommsTriage::query()->where('status', 'pending')->count(),
        ], 'Inbox badge retrieved successfully.');
    }

    public function read(MessageThread $messageThread): JsonResponse
    {
        // Zero — never arithmetic decrement (benign race with inbound increments).
        MessageThread::query()
            ->whereKey($messageThread->id)
            ->update(['unread_count' => 0]);

        $messageThread->refresh();

        return $this->success([
            'id' => $messageThread->id,
            'unread_count' => (int) $messageThread->unread_count,
        ], 'Thread marked read.');
    }

    public function assign(Request $request, MessageThread $messageThread): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['present', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        /** @var Employee $actor */
        $actor = $request->user();

        $employeeId = $validated['employee_id'];

        $messageThread->assigned_employee_id = $employeeId;
        $messageThread->save();
        $messageThread->load('assignee');

        RecordsActivity::log(
            LogChannel::Comms,
            'thread.assigned',
            $messageThread,
            [
                'employee_id' => $employeeId,
                'assigned_employee_id' => $employeeId,
            ],
            causer: $actor,
        );

        return $this->success([
            'id' => $messageThread->id,
            'assigned_employee' => $messageThread->assignee !== null ? [
                'id' => $messageThread->assignee->id,
                'name' => $messageThread->assignee->name,
            ] : null,
        ], 'Thread assignment updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMessage(Message $message): array
    {
        $html = $message->body_html !== null
            ? HtmlSanitizer::sanitize($message->body_html)
            : null;

        $body = $html !== null && $html !== ''
            ? ['format' => 'html', 'content' => $html]
            : ['format' => 'text', 'content' => $message->body_text];

        return [
            'id' => $message->id,
            'direction' => $message->direction?->value ?? (string) $message->direction,
            'status' => $message->status?->value ?? (string) $message->status,
            'body' => $body,
            'attachments' => $message->attachments
                ->map(fn (MessageAttachment $attachment) => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'size' => $attachment->size_bytes,
                    'mime' => $attachment->mime_type,
                ])
                ->values()
                ->all(),
            'source' => $message->source?->value ?? (string) $message->source,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'delivery_events' => $message->delivery_events,
            'from_address' => $message->from_address,
            'to_address' => $message->to_address,
        ];
    }
}
