<?php

declare(strict_types=1);

namespace App\Support\Playbooks\Kinds;

use App\Enums\PlaybookStepAction;
use App\Support\Playbooks\PlaybookKind;
use Illuminate\Validation\ValidationException;

final class DebtProcess implements PlaybookKind
{
    public function trigger(): array
    {
        return [
            'type' => 'trigger.object_created',
            'label' => 'Delinquency opened',
            'config' => [
                'objectType' => 'delinquency',
                'filters' => ['logic' => 'and', 'conditions' => []],
            ],
        ];
    }

    public function guard(array $filters): array
    {
        return [
            'logic' => 'and',
            'conditions' => [
                ['field' => 'cured_on', 'operator' => 'is_empty'],
            ],
        ];
    }

    public function allowedActions(): array
    {
        return [
            PlaybookStepAction::SendEmail,
            PlaybookStepAction::SendSms,
            PlaybookStepAction::CreateTask,
            PlaybookStepAction::RecordNotice,
        ];
    }

    public function validateFilters(array $filters): void
    {
        $allowed = ['site_ids', 'policy_ids', 'min_days_overdue'];
        foreach (array_keys($filters) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'enrolment_filters' => "Unknown debt process filter [{$key}].",
                ]);
            }
        }

        if (isset($filters['site_ids']) && ! is_array($filters['site_ids'])) {
            throw ValidationException::withMessages([
                'enrolment_filters.site_ids' => 'site_ids must be an array.',
            ]);
        }

        if (isset($filters['policy_ids']) && ! is_array($filters['policy_ids'])) {
            throw ValidationException::withMessages([
                'enrolment_filters.policy_ids' => 'policy_ids must be an array.',
            ]);
        }

        if (isset($filters['min_days_overdue']) && ! is_numeric($filters['min_days_overdue'])) {
            throw ValidationException::withMessages([
                'enrolment_filters.min_days_overdue' => 'min_days_overdue must be a number.',
            ]);
        }
    }

    public function subjectDescriptor(): string
    {
        return 'delinquency';
    }
}
