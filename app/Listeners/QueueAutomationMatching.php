<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ModelLifecycleEvent;
use App\Jobs\MatchAutomationTriggers;

class QueueAutomationMatching
{
    public function handle(ModelLifecycleEvent $event): void
    {
        $job = MatchAutomationTriggers::fromEvent($event);
        MatchAutomationTriggers::dispatch(
            $job->lifecycle,
            $job->subjectType,
            $job->subjectId,
            $job->dirty,
            $job->attributes,
            $job->causerType,
            $job->causerId,
        );
    }
}
