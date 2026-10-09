<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\AutomationNodeType;
use App\Enums\PlaybookStepAction;
use App\Models\AutomationNode;
use App\Models\ContractDocument;
use App\Models\PlaybookStep;
use App\Models\TemplateFamily;
use Illuminate\Support\Facades\DB;

final class TemplateFamilyUsage
{
    public static function count(TemplateFamily $family): int
    {
        $id = $family->id;

        $playbookCount = PlaybookStep::query()
            ->whereIn('action', [
                PlaybookStepAction::SendEmail->value,
                PlaybookStepAction::SendSms->value,
            ])
            ->where(function ($q) use ($id): void {
                $q->where('params->template_family_id', $id)
                    ->orWhere('params->email_template_id', $id);
            })
            ->count();

        $types = [
            AutomationNodeType::SendEmail->value,
            AutomationNodeType::SendSms->value,
        ];

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $automationCount = AutomationNode::query()
                ->whereIn('type', $types)
                ->where(function ($q) use ($id): void {
                    $q->whereRaw("(config->>'template_family_id')::int = ?", [$id])
                        ->orWhereRaw("(config->>'templateId')::int = ?", [$id])
                        ->orWhereRaw("(config->>'template_id')::int = ?", [$id])
                        ->orWhereRaw("(config->>'email_template_id')::int = ?", [$id]);
                })
                ->count();
        } else {
            $automationCount = AutomationNode::query()
                ->whereIn('type', $types)
                ->where(function ($q) use ($id): void {
                    $q->where('config->template_family_id', $id)
                        ->orWhere('config->templateId', $id)
                        ->orWhere('config->template_id', $id)
                        ->orWhere('config->email_template_id', $id);
                })
                ->count();
        }

        $documentCount = ContractDocument::query()
            ->where('template_family_id', $id)
            ->count();

        return $playbookCount + $automationCount + $documentCount;
    }
}
