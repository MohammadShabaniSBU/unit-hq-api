<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\ContactChannelType;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Aggregate inbox thread list: filters + latest-message preview in one query shape.
 */
final class InboxThreadQuery
{
    public const EXCERPT_LENGTH = 120;

    public const DEFAULT_PER_PAGE = 25;

    /**
     * @return Builder<MessageThread>
     */
    public static function filtered(
        ?Channel $channel,
        string $filter,
        bool $unreadOnly,
        ?string $q,
        ?Carbon $updatedAfter,
        ?Employee $viewer,
    ): Builder {
        $query = MessageThread::query()
            ->select('message_threads.*')
            ->selectSub(
                Message::query()
                    ->select('direction')
                    ->whereColumn('message_thread_id', 'message_threads.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'preview_direction',
            )
            ->selectSub(
                Message::query()
                    ->select('status')
                    ->whereColumn('message_thread_id', 'message_threads.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'preview_status',
            )
            ->selectSub(
                Message::query()
                    ->select('body_text')
                    ->whereColumn('message_thread_id', 'message_threads.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'preview_body_text',
            )
            ->selectSub(
                Message::query()
                    ->select('body_html')
                    ->whereColumn('message_thread_id', 'message_threads.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'preview_body_html',
            )
            ->selectSub(
                Message::query()
                    ->selectRaw('COALESCE(sent_at, created_at)')
                    ->whereColumn('message_thread_id', 'message_threads.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'preview_at',
            )
            ->with([
                'contact.channels',
                'assignee',
            ])
            ->when($channel !== null, fn (Builder $q) => $q->where('channel', $channel))
            ->when($filter === 'mine' && $viewer !== null, fn (Builder $q) => $q->where('assigned_employee_id', $viewer->id))
            ->when($filter === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_employee_id'))
            ->when($unreadOnly, fn (Builder $q) => $q->where('unread_count', '>', 0))
            ->when($updatedAfter !== null, fn (Builder $q) => $q->where('updated_at', '>=', $updatedAfter))
            ->when($q !== null && $q !== '', function (Builder $builder) use ($q): void {
                $term = '%'.$q.'%';
                $builder->where(function (Builder $inner) use ($term): void {
                    $inner->where('subject', 'like', $term)
                        ->orWhere('channel_key', 'like', $term)
                        ->orWhereHas('contact', function (Builder $contact) use ($term): void {
                            $contact->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhereHas('channels', fn (Builder $ch) => $ch->where('value', 'like', $term));
                        });
                });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        return $query;
    }

    /**
     * @param  Collection<int, MessageThread>  $threads
     * @return array<string, bool> keyed by "{channel}|{normalizedAddress}"
     */
    public static function suppressedMap(Collection $threads): array
    {
        $pairs = [];

        foreach ($threads as $thread) {
            $address = self::composerAddress($thread);
            if ($address === null || $address === '') {
                continue;
            }

            $channel = $thread->channel instanceof Channel
                ? $thread->channel
                : Channel::from((string) $thread->channel);

            $pairs[] = [$channel, $address];
        }

        return SuppressionWriter::transactionalBlockedMap($pairs);
    }

    public static function composerAddress(MessageThread $thread): ?string
    {
        $channel = $thread->channel instanceof Channel
            ? $thread->channel
            : Channel::from((string) $thread->channel);

        if ($channel === Channel::Sms || $channel === Channel::Call || $channel === Channel::Whatsapp) {
            return $thread->channel_key;
        }

        $contact = $thread->contact;
        if ($contact === null) {
            return null;
        }

        $primary = $contact->channels
            ->first(fn ($ch) => $ch->type === ContactChannelType::Email && $ch->is_primary);

        if ($primary !== null) {
            return (string) $primary->value;
        }

        $anyEmail = $contact->channels
            ->first(fn ($ch) => $ch->type === ContactChannelType::Email);

        return $anyEmail !== null ? (string) $anyEmail->value : $contact->email;
    }

    public static function excerpt(?string $bodyText, ?string $bodyHtml): ?string
    {
        $raw = $bodyText;
        if ($raw === null || trim($raw) === '') {
            $raw = $bodyHtml !== null ? strip_tags($bodyHtml) : null;
        }

        if ($raw === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', trim($raw)) ?? trim($raw);
        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, self::EXCERPT_LENGTH, '');
    }

    public static function avatarInitials(string $firstName, string $lastName): string
    {
        $first = mb_substr(trim($firstName), 0, 1);
        $last = mb_substr(trim($lastName), 0, 1);
        $initials = mb_strtoupper($first.$last);

        return $initials !== '' ? $initials : '?';
    }

    /**
     * @return array<string, mixed>
     */
    public static function summarize(MessageThread $thread, array $suppressedMap = []): array
    {
        $channel = $thread->channel instanceof Channel
            ? $thread->channel
            : Channel::from((string) $thread->channel);

        $contact = $thread->contact;
        $name = $contact !== null
            ? trim($contact->first_name.' '.$contact->last_name)
            : '';

        $address = self::composerAddress($thread);
        $suppressed = false;
        if ($address !== null && $address !== '') {
            $normalized = ContactChannelMatcher::normalize($channel, $address);
            $suppressed = $suppressedMap[$channel->value.'|'.$normalized] ?? false;
        }

        $previewDirection = $thread->getAttribute('preview_direction');
        $previewStatus = $thread->getAttribute('preview_status');
        $previewAt = $thread->getAttribute('preview_at');

        $assignee = $thread->assignee;

        return [
            'id' => $thread->id,
            'channel' => $channel->value,
            'contact' => [
                'id' => $contact?->id,
                'name' => $name,
                'avatar_initials' => self::avatarInitials(
                    (string) ($contact?->first_name ?? ''),
                    (string) ($contact?->last_name ?? ''),
                ),
            ],
            'subject' => $thread->subject,
            'channel_key' => $thread->channel_key,
            'preview' => $previewDirection !== null ? [
                'direction' => $previewDirection,
                'body_excerpt' => self::excerpt(
                    $thread->getAttribute('preview_body_text'),
                    $thread->getAttribute('preview_body_html'),
                ),
                'status' => $previewStatus,
                'at' => $previewAt !== null
                    ? Carbon::parse((string) $previewAt)->toIso8601String()
                    : null,
            ] : null,
            'unread_count' => (int) $thread->unread_count,
            'assigned_employee' => $assignee !== null ? [
                'id' => $assignee->id,
                'name' => $assignee->name,
            ] : null,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'suppressed' => $suppressed,
        ];
    }
}
