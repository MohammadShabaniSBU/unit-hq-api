<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\AutomationNodeType;
use App\Enums\PlaybookStepAction;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\ContractDocument;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TemplateFamilyUsage
{
    public static function count(TemplateFamily $family): int
    {
        $id = $family->id;

        return self::playbookSteps($id)->count()
            + self::automationNodes($id)->count()
            + ContractDocument::query()->where('template_family_id', $id)->count();
    }

    /**
     * Counts and names for the assistant. Contract documents stay in {@see count()} only.
     *
     * @return array{
     *     automations: array{count: int, names: list<string>},
     *     playbooks: array{count: int, names: list<string>},
     *     campaigns: array{count: int, names: list<string>}
     * }
     */
    public static function references(TemplateFamily $family): array
    {
        $id = $family->id;

        return [
            'automations' => [
                'count' => self::automationNodes($id)->count(),
                'names' => self::names(Automation::query()->whereIn(
                    'id',
                    self::automationNodes($id)->select('automation_id'),
                )),
            ],
            'playbooks' => [
                'count' => self::playbookSteps($id)->count(),
                'names' => self::names(Playbook::query()->whereIn(
                    'id',
                    self::playbookSteps($id)->select('playbook_id'),
                )),
            ],
            'campaigns' => [
                'count' => Campaign::query()->whereIn('id', self::pinnedCampaignIds($id))->count(),
                'names' => self::names(Campaign::query()->whereIn('id', self::pinnedCampaignIds($id))),
            ],
        ];
    }

    /**
     * @return Builder<PlaybookStep>
     */
    private static function playbookSteps(int $familyId): Builder
    {
        return PlaybookStep::query()
            ->whereIn('action', [
                PlaybookStepAction::SendEmail->value,
                PlaybookStepAction::SendSms->value,
            ])
            ->where(function (Builder $query) use ($familyId): void {
                $query->where('params->template_family_id', $familyId)
                    ->orWhere('params->email_template_id', $familyId);
            });
    }

    /**
     * @return Builder<AutomationNode>
     */
    private static function automationNodes(int $familyId): Builder
    {
        $types = [
            AutomationNodeType::SendEmail->value,
            AutomationNodeType::SendSms->value,
        ];

        $query = AutomationNode::query()->whereIn('type', $types);

        if (DB::connection()->getDriverName() === 'pgsql') {
            return $query->where(function (Builder $inner) use ($familyId): void {
                $inner->whereRaw("(config->>'template_family_id')::int = ?", [$familyId])
                    ->orWhereRaw("(config->>'templateId')::int = ?", [$familyId])
                    ->orWhereRaw("(config->>'template_id')::int = ?", [$familyId])
                    ->orWhereRaw("(config->>'email_template_id')::int = ?", [$familyId]);
            });
        }

        return $query->where(function (Builder $inner) use ($familyId): void {
            $inner->where('config->template_family_id', $familyId)
                ->orWhere('config->templateId', $familyId)
                ->orWhere('config->template_id', $familyId)
                ->orWhere('config->email_template_id', $familyId);
        });
    }

    /**
     * Campaigns whose sends pin a version of this family.
     *
     * @return Builder<CampaignSend>
     */
    private static function pinnedCampaignIds(int $familyId): Builder
    {
        $versionIds = TemplateVersion::query()
            ->where('template_family_id', $familyId)
            ->select('id');

        return CampaignSend::query()
            ->whereIn('template_version_id', $versionIds)
            ->select('campaign_id');
    }

    /**
     * @param  Builder<Automation>|Builder<Playbook>|Builder<Campaign>  $query
     * @return list<string>
     */
    private static function names(Builder $query): array
    {
        return $query->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->unique()
            ->sort(SORT_STRING)
            ->values()
            ->all();
    }
}
