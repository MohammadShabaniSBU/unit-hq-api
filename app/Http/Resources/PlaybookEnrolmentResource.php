<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use Illuminate\Http\Request;

/**
 * @mixin AutomationRun
 */
class PlaybookEnrolmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AutomationRun $run */
        $run = $this->resource;
        $automation = $run->automation;
        $progress = PlaybookEnrolmentSummary::progress($run, $automation);

        /** @var array{delinquencies: \Illuminate\Support\Collection, deals: \Illuminate\Support\Collection} $subjects */
        $subjects = $this->additional['enrolment_subjects'] ?? [
            'delinquencies' => collect(),
            'deals' => collect(),
        ];

        $status = $run->status instanceof AutomationRunStatus
            ? $run->status->value
            : (string) $run->status;

        $cancelCause = $run->cancel_cause instanceof AutomationCancelCause
            ? $run->cancel_cause->value
            : $run->cancel_cause;

        $enrolledAt = $run->started_at ?? $run->created_at;
        $completedAt = $run->completed_at;
        $durationSeconds = null;
        if ($enrolledAt !== null && $completedAt !== null) {
            $durationSeconds = max(0, $completedAt->diffInSeconds($enrolledAt));
        }

        return [
            'id' => $run->id,
            'automation_id' => $run->automation_id,
            'playbook_id' => $automation?->playbook_id,
            'status' => $status,
            'cancel_cause' => $cancelCause,
            'enrolled_at' => $this->datetime($enrolledAt),
            'waiting_until' => $this->datetime($run->waiting_until),
            'next_step_at' => $status === AutomationRunStatus::Waiting->value
                ? $this->datetime($run->waiting_until)
                : null,
            'completed_at' => $this->datetime($completedAt),
            'duration_seconds' => $durationSeconds,
            'steps_completed' => $progress['steps_completed'],
            'step_total' => $progress['step_total'],
            'subject' => PlaybookEnrolmentSummary::subjectPayload($run, $subjects),
            'created_at' => $this->datetime($run->created_at),
            'updated_at' => $this->datetime($run->updated_at),
        ];
    }
}
