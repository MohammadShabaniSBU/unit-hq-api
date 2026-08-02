<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\AutomationNode;
use App\Models\PlaybookStep;
use App\Models\TemplateFamily;
use Illuminate\Support\Facades\DB;

final class TemplateFamilyUsage
{
    public static function count(TemplateFamily $family): int
    {
        $id = $family->id;

        $playbookCount = PlaybookStep::query()
            ->where('action', 'send_email')
            ->where(function ($q) use ($id): void {
                $q->where('params->template_family_id', $id)
                    ->orWhere('params->email_template_id', $id);
            })
            ->count();

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $automationCount = AutomationNode::query()
                ->where('type', 'send_email')
                ->where(function ($q) use ($id): void {
                    $q->whereRaw("(config->>'template_family_id')::int = ?", [$id])
                        ->orWhereRaw("(config->>'templateId')::int = ?", [$id])
                        ->orWhereRaw("(config->>'template_id')::int = ?", [$id]);
                })
                ->count();
        } else {
            $automationCount = AutomationNode::query()
                ->where('type', 'send_email')
                ->where(function ($q) use ($id): void {
                    $q->where('config->template_family_id', $id)
                        ->orWhere('config->templateId', $id)
                        ->orWhere('config->template_id', $id);
                })
                ->count();
        }

        return $playbookCount + $automationCount;
    }
}
