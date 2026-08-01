<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Events\ModelCreated;
use App\Events\ModelDeleted;
use App\Events\ModelLifecycleEvent;
use App\Events\ModelUpdated;
use App\Models\AutomationRun;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Automation\CustomAttributeBag;
use App\Support\Automation\TriggerMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchAutomationTriggers implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $lifecycle, // created|updated|deleted
        public readonly string $subjectType,
        public readonly int|string $subjectId,
        /** @var array<string, array{old: mixed, new: mixed}> */
        public readonly array $dirty,
        /** @var array<string, mixed> */
        public readonly array $attributes,
        public readonly ?string $causerType,
        public readonly int|string|null $causerId,
    ) {}

    public static function fromEvent(ModelLifecycleEvent $event): self
    {
        $lifecycle = match (true) {
            $event instanceof ModelCreated => 'created',
            $event instanceof ModelUpdated => 'updated',
            $event instanceof ModelDeleted => 'deleted',
            default => 'updated',
        };

        return new self(
            $lifecycle,
            $event->subjectType,
            $event->subjectId,
            $event->dirty,
            $event->attributes,
            $event->causerType,
            $event->causerId,
        );
    }

    public function handle(): void
    {
        $triggerType = match ($this->lifecycle) {
            'created' => AutomationNodeType::ObjectCreated,
            'updated' => AutomationNodeType::ObjectUpdated,
            default => null,
        };

        if ($triggerType === null) {
            return;
        }

        $ids = AutomationWatchCache::automationIds($this->subjectType, $triggerType);
        if ($ids === []) {
            return;
        }

        $customAttributes = CustomAttributeBag::forEntity($this->subjectType, $this->subjectId);
        $valueBag = array_merge($this->attributes, $customAttributes);

        $matches = TriggerMatcher::match(
            $triggerType,
            $this->subjectType,
            $ids,
            $this->dirty,
            $valueBag,
        );

        foreach ($matches as $match) {
            $run = AutomationRun::query()->create([
                'automation_id' => $match['automation']->id,
                'trigger_node_id' => $match['trigger']->id,
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
                'causer_type' => $this->causerType,
                'causer_id' => $this->causerId,
                'status' => AutomationRunStatus::Pending,
                'trigger_payload' => [
                    'lifecycle' => $this->lifecycle,
                    'dirty' => $this->dirty,
                    'attributes' => $this->attributes,
                    'custom_attributes' => $customAttributes,
                ],
                'depth' => 0,
                'root_run_id' => null,
            ]);

            ExecuteAutomationRun::dispatch($run->id);
        }
    }
}
