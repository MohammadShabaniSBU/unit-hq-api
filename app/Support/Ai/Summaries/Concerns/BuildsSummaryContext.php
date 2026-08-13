<?php

declare(strict_types=1);

namespace App\Support\Ai\Summaries\Concerns;

use App\Models\Employee;
use App\Models\Interaction;
use App\Models\Note;
use App\Models\Task;
use App\Support\Auth\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait BuildsSummaryContext
{
    protected Employee $viewer;

    /** @var array{interactions: int, notes: int, body_chars: int} */
    protected array $caps;

    public function digest(): string
    {
        $payload = $this->build();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded);
    }

    public function isEmpty(): bool
    {
        $counts = $this->counts();

        foreach ($counts as $value) {
            if ((int) $value > 0) {
                return false;
            }
        }

        return true;
    }

    protected function truncate(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $limit = $this->caps['body_chars'];

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'…';
    }

    /**
     * @return list<array{id: int, channel: string, direction: string, occurred_at: string|null, text: string|null}>
     */
    protected function mapInteractions(Collection $interactions): array
    {
        return $interactions
            ->take($this->caps['interactions'])
            ->map(function (Interaction $interaction): array {
                $text = $interaction->summary ?: $interaction->content;

                return [
                    'id' => $interaction->id,
                    'channel' => (string) $interaction->channel,
                    'direction' => (string) $interaction->direction,
                    'occurred_at' => $interaction->occurred_at?->toIso8601String(),
                    'text' => $this->truncate(is_string($text) ? $text : null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, content: string|null, created_at: string|null}>
     */
    protected function mapNotes(Collection $notes): array
    {
        return $notes
            ->take($this->caps['notes'])
            ->map(fn (Note $note): array => [
                'id' => $note->id,
                'content' => $this->truncate($note->content),
                'created_at' => $note->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, title: string, status: string, priority: string, due_at: string|null, type: string|null}>
     */
    protected function mapOpenTasks(Collection $tasks): array
    {
        return $tasks
            ->filter(fn (Task $task): bool => ! in_array($task->status, ['done', 'cancelled'], true))
            ->values()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => (string) $task->title,
                'status' => (string) $task->status,
                'priority' => (string) $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'type' => $task->type?->value ?? (is_string($task->type) ? $task->type : null),
            ])
            ->all();
    }

    /**
     * @return array{amount: string, currency: string}
     */
    protected function money(string $amount, string $currency): array
    {
        return [
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    protected function viewerCanSee(Model $model, Permission $permission): bool
    {
        return $model->newQuery()
            ->visibleTo($this->viewer, $permission)
            ->whereKey($model->getKey())
            ->exists();
    }
}
