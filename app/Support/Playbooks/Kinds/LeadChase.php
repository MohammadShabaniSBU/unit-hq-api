<?php

declare(strict_types=1);

namespace App\Support\Playbooks\Kinds;

use App\Enums\DealStatus;
use App\Enums\PlaybookStepAction;
use App\Support\Playbooks\PlaybookKind;
use Illuminate\Validation\ValidationException;

final class LeadChase implements PlaybookKind
{
    public function trigger(array $filters): array
    {
        return [
            'type' => 'trigger.object_created',
            'label' => 'Deal created',
            'config' => [
                'objectType' => 'deal',
                'filters' => ['logic' => 'and', 'conditions' => []],
            ],
        ];
    }

    public function guard(array $filters): array
    {
        $stages = [];
        if (isset($filters['stages']) && is_array($filters['stages'])) {
            foreach ($filters['stages'] as $stage) {
                if (is_string($stage) && $stage !== '') {
                    $stages[] = $stage;
                }
            }
        }

        $terminal = [
            DealStatus::ClosedWon->value,
            DealStatus::ClosedLost->value,
        ];

        $conditions = [
            [
                'field' => 'status',
                'operator' => 'not_in',
                'value' => $terminal,
            ],
        ];

        if ($stages !== []) {
            $conditions[] = [
                'field' => 'status',
                'operator' => 'in',
                'value' => $stages,
            ];
        }

        return [
            'logic' => 'and',
            'conditions' => $conditions,
        ];
    }

    public function allowedActions(): array
    {
        return [
            PlaybookStepAction::SendEmail,
            PlaybookStepAction::SendSms,
            PlaybookStepAction::SendWhatsappTemplate,
            PlaybookStepAction::CreateTask,
        ];
    }

    public function validateFilters(array $filters): void
    {
        $allowed = ['site_ids', 'stages', 'sources'];
        foreach (array_keys($filters) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'enrolment_filters' => "Unknown lead chase filter [{$key}].",
                ]);
            }
        }

        foreach (['site_ids', 'stages', 'sources'] as $listKey) {
            if (isset($filters[$listKey]) && ! is_array($filters[$listKey])) {
                throw ValidationException::withMessages([
                    "enrolment_filters.{$listKey}" => "{$listKey} must be an array.",
                ]);
            }
        }
    }

    public function subjectDescriptor(): string
    {
        return 'deal';
    }
}
