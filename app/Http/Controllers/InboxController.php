<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContactChannelType;
use App\Enums\LogChannel;
use App\Models\CommsTriage;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageThread;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectChain;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Automation\TokenResolver;
use App\Support\Communications\Channel;
use App\Support\Communications\ComposerIdentity;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\HtmlSanitizer;
use App\Support\Communications\InboxThreadContext;
use App\Support\Communications\InboxThreadQuery;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailAttachment;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\SuppressionWriter;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inbox read surface + reply/compose writes (S11-00 / S11-01).
 *
 * Auth: any authenticated Employee until S17 RBAC (10-open-decisions.md).
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

    public function unread(MessageThread $messageThread): JsonResponse
    {
        // Honest hack — thread-level model owns read state; per-message is out of scope.
        MessageThread::query()
            ->whereKey($messageThread->id)
            ->update(['unread_count' => 1]);

        $messageThread->refresh();

        return $this->success([
            'id' => $messageThread->id,
            'unread_count' => (int) $messageThread->unread_count,
        ], 'Thread marked unread.');
    }

    public function moveTargets(MessageThread $messageThread): JsonResponse
    {
        $targets = MessageThread::query()
            ->where('contact_id', $messageThread->contact_id)
            ->where('channel', $messageThread->channel)
            ->whereKeyNot($messageThread->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $latestByThread = Message::query()
            ->whereIn('message_thread_id', $targets->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('message_thread_id')
            ->keyBy('message_thread_id');

        $data = $targets->map(function (MessageThread $thread) use ($latestByThread): array {
            $latest = $latestByThread->get($thread->id);

            return [
                'id' => $thread->id,
                'subject' => $thread->subject,
                'channel_key' => $thread->channel_key,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
                'preview_excerpt' => $latest !== null
                    ? InboxThreadQuery::excerpt($latest->body_text, $latest->body_html)
                    : null,
            ];
        })->values()->all();

        return $this->success($data, 'Move targets retrieved successfully.');
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

    public function context(MessageThread $messageThread): JsonResponse
    {
        $messageThread->load(['contact.channels']);

        return $this->success(
            InboxThreadContext::build($messageThread),
            'Inbox thread context retrieved successfully.',
        );
    }

    public function composeContext(MessageThread $messageThread): JsonResponse
    {
        $messageThread->load(['contact.channels']);

        $channel = $messageThread->channel instanceof Channel
            ? $messageThread->channel
            : Channel::from((string) $messageThread->channel);

        $resolved = ComposerIdentity::resolveForThread($messageThread);
        $suppression = $this->suppressionPayload($messageThread);

        $templates = [];
        $tokens = [];
        if ($channel === Channel::Email) {
            $templates = EmailTemplate::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (EmailTemplate $t) => ['id' => $t->id, 'name' => $t->name])
                ->values()
                ->all();
            $tokens = SubjectTokenBag::vocabulary();
        }

        return $this->success([
            'from_identity' => $resolved['identity'],
            'suppression' => $suppression,
            'templates' => $templates,
            'tokens' => $tokens,
        ], 'Compose context retrieved successfully.');
    }

    public function reply(Request $request, MessageThread $messageThread): JsonResponse
    {
        $channel = $messageThread->channel instanceof Channel
            ? $messageThread->channel
            : Channel::from((string) $messageThread->channel);

        if ($channel === Channel::Call) {
            return $this->error(
                'Call back — coming with phone integration.',
                ['channel' => ['Call replies are not available yet.']],
                422,
            );
        }

        if ($channel === Channel::Email) {
            $validated = $request->validate([
                'body_text' => ['required', 'string'],
                'body_html' => ['sometimes', 'nullable', 'string'],
                'attachment_ids' => ['sometimes', 'array'],
                'attachment_ids.*' => ['integer', 'exists:message_attachments,id'],
                'email_template_id' => ['sometimes', 'nullable', 'integer', 'exists:email_templates,id'],
            ]);
        } elseif ($channel === Channel::Sms) {
            $validated = $request->validate([
                'body_text' => ['required', 'string'],
                'attachment_ids' => ['prohibited'],
                'email_template_id' => ['prohibited'],
                'body_html' => ['prohibited'],
            ]);
        } else {
            return $this->error('Unsupported channel for reply.', [], 422);
        }

        /** @var Employee $actor */
        $actor = $request->user();
        $messageThread->load(['contact.channels']);
        $contact = $messageThread->contact;
        if ($contact === null) {
            return $this->error('Thread has no contact.', [], 422);
        }

        $resolved = ComposerIdentity::resolveForThread($messageThread);
        if ($resolved['site'] === null || $resolved['identity'] === null) {
            return $this->error(
                'Configure a sender identity before replying.',
                ['from_identity' => ['No sender identity resolved for this thread.']],
                422,
            );
        }

        $context = SendContext::manual(SendClass::Transactional, ['employee_id' => $actor->id]);
        $tokenContext = new RunContext(subjectBag: SubjectTokenBag::forContact($contact));

        if ($channel === Channel::Email) {
            return $this->sendEmailReply(
                $messageThread,
                $contact,
                $resolved['site'],
                $validated,
                $context,
                $tokenContext,
            );
        }

        return $this->sendSmsReply(
            $messageThread,
            $contact,
            $resolved['site'],
            $validated,
            $context,
            $tokenContext,
        );
    }

    public function compose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'subject' => ['required_if:channel,email', 'nullable', 'string', 'max:998'],
            'body_text' => ['required', 'string'],
            'body_html' => ['sometimes', 'nullable', 'string'],
            'attachment_ids' => ['sometimes', 'array'],
            'attachment_ids.*' => ['integer', 'exists:message_attachments,id'],
            'email_template_id' => ['sometimes', 'nullable', 'integer', 'exists:email_templates,id'],
        ]);

        $channel = Channel::from($validated['channel']);
        if ($channel === Channel::Sms) {
            if (! empty($validated['attachment_ids']) || isset($validated['email_template_id']) || isset($validated['body_html'])) {
                throw ValidationException::withMessages([
                    'channel' => ['SMS compose does not support attachments, templates, or HTML.'],
                ]);
            }
        }

        /** @var Employee $actor */
        $actor = $request->user();
        $contact = Contact::query()->with('channels')->findOrFail($validated['contact_id']);

        $resolved = ComposerIdentity::resolve($contact, $channel);
        if ($resolved['site'] === null || $resolved['identity'] === null) {
            return $this->error(
                'Configure a sender identity before composing.',
                ['from_identity' => ['No sender identity resolved for this contact.']],
                422,
            );
        }

        $context = SendContext::manual(SendClass::Transactional, ['employee_id' => $actor->id]);
        $tokenContext = new RunContext(subjectBag: SubjectTokenBag::forContact($contact));

        if ($channel === Channel::Email) {
            $to = SubjectChain::primaryChannel($contact, ContactChannelType::Email)?->value
                ?? $contact->email;
            if ($to === null || $to === '') {
                return $this->error('Contact has no email address.', [], 422);
            }

            [$bodyText, $bodyHtml] = $this->resolveEmailBodies($validated, $tokenContext);
            $attachments = $this->loadStagedAttachments($validated['attachment_ids'] ?? []);
            $emailAttachments = $this->toEmailAttachments($attachments);

            $email = new EmailMessage(
                to: [new EmailAddress($to)],
                subject: (string) $validated['subject'],
                html: $bodyHtml ?? '',
                text: $bodyText,
                attachments: $emailAttachments,
            );

            $result = app(EmailSender::class)->send(
                $email,
                $resolved['site'],
                $contact,
                $context,
            );

            if ($result->wasSuppressed()) {
                return $this->suppressedResponse($contact, Channel::Email, $to, $result->suppressedReason);
            }

            $message = Message::query()->with('attachments')->findOrFail($result->messageId);
            $this->linkAttachments($attachments, $message->id);

            return $this->created([
                'thread_id' => $message->message_thread_id,
                'message' => $this->mapMessage($message->fresh('attachments') ?? $message),
            ], 'Message sent.');
        }

        $to = SubjectChain::primaryChannel($contact, ContactChannelType::Phone)?->value;
        if ($to === null || $to === '') {
            return $this->error('Contact has no phone number.', [], 422);
        }

        $body = TokenResolver::resolve($validated['body_text'], $tokenContext);
        $sms = new SmsMessage(to: $to, body: $body);
        $result = app(SmsSender::class)->send(
            $sms,
            $resolved['site'],
            $contact,
            $context,
        );

        if ($result->wasSuppressed()) {
            return $this->suppressedResponse($contact, Channel::Sms, $to, $result->suppressedReason);
        }

        $message = Message::query()->with('attachments')->findOrFail($result->messageId);

        return $this->created([
            'thread_id' => $message->message_thread_id,
            'message' => $this->mapMessage($message),
        ], 'Message sent.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function sendEmailReply(
        MessageThread $thread,
        Contact $contact,
        \App\Models\Site $site,
        array $validated,
        SendContext $context,
        RunContext $tokenContext,
    ): JsonResponse {
        $to = InboxThreadQuery::composerAddress($thread);
        if ($to === null || $to === '') {
            return $this->error('Contact has no email address.', [], 422);
        }

        [$bodyText, $bodyHtml] = $this->resolveEmailBodies($validated, $tokenContext);
        $attachments = $this->loadStagedAttachments($validated['attachment_ids'] ?? []);
        $emailAttachments = $this->toEmailAttachments($attachments);

        $email = new EmailMessage(
            to: [new EmailAddress($to)],
            subject: ComposerIdentity::replySubject($thread),
            html: $bodyHtml ?? '',
            text: $bodyText,
            attachments: $emailAttachments,
            headers: ComposerIdentity::replyHeaders($thread),
        );

        $result = app(EmailSender::class)->send(
            $email,
            $site,
            $contact,
            $context,
            thread: $thread,
        );

        if ($result->wasSuppressed()) {
            return $this->suppressedResponse($contact, Channel::Email, $to, $result->suppressedReason);
        }

        $message = Message::query()->with('attachments')->findOrFail($result->messageId);
        $this->linkAttachments($attachments, $message->id);

        return $this->created([
            'thread_id' => $thread->id,
            'message' => $this->mapMessage($message->fresh('attachments') ?? $message),
        ], 'Reply sent.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function sendSmsReply(
        MessageThread $thread,
        Contact $contact,
        \App\Models\Site $site,
        array $validated,
        SendContext $context,
        RunContext $tokenContext,
    ): JsonResponse {
        $to = $thread->channel_key
            ?: SubjectChain::primaryChannel($contact, ContactChannelType::Phone)?->value;

        if ($to === null || $to === '') {
            return $this->error('Contact has no phone number.', [], 422);
        }

        $body = TokenResolver::resolve($validated['body_text'], $tokenContext);
        $sms = new SmsMessage(to: $to, body: $body);

        $result = app(SmsSender::class)->send(
            $sms,
            $site,
            $contact,
            $context,
            thread: $thread,
        );

        if ($result->wasSuppressed()) {
            return $this->suppressedResponse($contact, Channel::Sms, $to, $result->suppressedReason);
        }

        $message = Message::query()->with('attachments')->findOrFail($result->messageId);

        return $this->created([
            'thread_id' => $thread->id,
            'message' => $this->mapMessage($message),
        ], 'Reply sent.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string|null}
     */
    private function resolveEmailBodies(array $validated, RunContext $tokenContext): array
    {
        if (! empty($validated['email_template_id'])) {
            $template = EmailTemplate::query()->findOrFail($validated['email_template_id']);
            $rendered = EmailTemplateRenderer::render($template, $tokenContext);

            return [$rendered['text'], $rendered['html']];
        }

        $bodyText = TokenResolver::resolve($validated['body_text'], $tokenContext);
        $bodyHtml = isset($validated['body_html']) && is_string($validated['body_html'])
            ? TokenResolver::resolve($validated['body_html'], $tokenContext)
            : null;

        return [$bodyText, $bodyHtml];
    }

    /**
     * @param  list<int>  $ids
     * @return list<MessageAttachment>
     */
    private function loadStagedAttachments(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $attachments = MessageAttachment::query()
            ->whereIn('id', $ids)
            ->whereNull('message_id')
            ->where('oversize', false)
            ->get();

        if ($attachments->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'attachment_ids' => ['One or more attachments are unavailable or already linked.'],
            ]);
        }

        $total = (int) $attachments->sum('size_bytes');
        $maxTotal = (int) config('communications.inbound.max_total_attachment_bytes');
        if ($total > $maxTotal) {
            throw ValidationException::withMessages([
                'attachment_ids' => ['Total attachment size exceeds the limit.'],
            ]);
        }

        return $attachments->all();
    }

    /**
     * @param  list<MessageAttachment>  $attachments
     * @return list<EmailAttachment>
     */
    private function toEmailAttachments(array $attachments): array
    {
        $disk = Storage::disk('local');
        $out = [];

        foreach ($attachments as $attachment) {
            $path = $attachment->disk_path;
            if ($path === null || ! $disk->exists($path)) {
                throw ValidationException::withMessages([
                    'attachment_ids' => ['Attachment file missing from storage.'],
                ]);
            }

            $out[] = new EmailAttachment(
                filename: $attachment->filename,
                content: $disk->get($path),
                contentType: $attachment->mime_type,
            );
        }

        return $out;
    }

    /**
     * @param  list<MessageAttachment>  $attachments
     */
    private function linkAttachments(array $attachments, int $messageId): void
    {
        foreach ($attachments as $attachment) {
            $attachment->forceFill(['message_id' => $messageId])->save();
        }
    }

    /**
     * @return array{scope: string, reason: string, created_at: string|null}|null
     */
    private function suppressionPayload(MessageThread $thread): ?array
    {
        $address = InboxThreadQuery::composerAddress($thread);
        if ($address === null || $address === '') {
            return null;
        }

        $channel = $thread->channel instanceof Channel
            ? $thread->channel
            : Channel::from((string) $thread->channel);

        $active = SuppressionWriter::activeFor($channel, $address);
        if ($active === null) {
            return null;
        }

        return [
            'scope' => $active->scope instanceof \BackedEnum
                ? $active->scope->value
                : (string) $active->scope,
            'reason' => $active->reason instanceof \BackedEnum
                ? $active->reason->value
                : (string) $active->reason,
            'created_at' => $active->created_at?->toIso8601String(),
        ];
    }

    private function suppressedResponse(
        Contact $contact,
        Channel $channel,
        string $address,
        ?string $reason,
    ): JsonResponse {
        $active = SuppressionWriter::activeFor($channel, $address);

        return $this->error(
            'Address is suppressed; message was not sent.',
            [
                'suppression' => [
                    'scope' => $active?->scope instanceof \BackedEnum
                        ? $active->scope->value
                        : ($active?->scope !== null ? (string) $active->scope : 'all'),
                    'reason' => $reason ?? ($active?->reason instanceof \BackedEnum
                        ? $active->reason->value
                        : (string) ($active?->reason ?? 'unknown')),
                    'created_at' => $active?->created_at?->toIso8601String(),
                ],
                'contact_id' => $contact->id,
            ],
            422,
        );
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

        $evidence = is_array($message->threading_evidence) ? $message->threading_evidence : [];
        $rethreaded = (bool) ($evidence['rethreaded'] ?? false);

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
            'source_ref' => $message->source_ref,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'delivery_events' => $message->delivery_events,
            'from_address' => $message->from_address,
            'to_address' => $message->to_address,
            'rethreaded' => $rethreaded,
            'rethreaded_from_thread_id' => $rethreaded
                ? (isset($evidence['from_thread_id']) ? (int) $evidence['from_thread_id'] : null)
                : null,
        ];
    }
}
