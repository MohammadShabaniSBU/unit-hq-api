<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\AutomationRunStatus;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignType;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Support\Communications\Channel;
use App\Support\Communications\SendClass;
use Illuminate\Support\Facades\DB;

/**
 * Send-time gates. Predicates match the audience preview: consent, then
 * exclusions, then the frequency cap. The first match wins.
 */
final class CampaignSendGate
{
    public function __construct(
        private readonly CampaignAudienceQuery $audience,
    ) {}

    public function reason(Campaign $campaign, Channel $channel, int $contactId): ?CampaignSkipReason
    {
        $rules = $this->audience->rulesFor($campaign);
        $marketing = $campaign->type !== CampaignType::Announcement;

        if ($marketing) {
            $listId = $rules['list_id'];
            if ($listId === null || ! ConsentState::isGranted($contactId, $channel, $listId)) {
                return CampaignSkipReason::NoConsent;
            }
        }

        if ($rules['delinquent'] && $this->isDelinquent($contactId)) {
            return CampaignSkipReason::ExcludedDelinquent;
        }

        if ($rules['playbook_kinds'] !== [] && $this->inPlaybook($contactId, $rules['playbook_kinds'])) {
            return CampaignSkipReason::ExcludedPlaybook;
        }

        if (self::blocksOnFrequencyCap($campaign, $rules['newsletter_bypasses_cap'])
            && $this->isCapped($contactId, $rules['cap_count'], $rules['cap_days'])) {
            return CampaignSkipReason::FrequencyCapped;
        }

        return null;
    }

    private function isDelinquent(int $contactId): bool
    {
        return DB::table('delinquencies')
            ->join('contracts', 'contracts.id', '=', 'delinquencies.contract_id')
            ->where('contracts.contact_id', $contactId)
            ->whereNull('delinquencies.cured_on')
            ->exists();
    }

    /**
     * @param  list<string>  $kinds
     */
    private function inPlaybook(int $contactId, array $kinds): bool
    {
        $open = [
            AutomationRunStatus::Pending->value,
            AutomationRunStatus::Running->value,
            AutomationRunStatus::Waiting->value,
        ];

        return DB::table('automation_runs')
            ->join('automations', 'automations.id', '=', 'automation_runs.automation_id')
            ->join('playbooks', 'playbooks.id', '=', 'automations.playbook_id')
            ->whereIn('playbooks.kind', $kinds)
            ->whereIn('automation_runs.status', $open)
            ->where(function ($subject) use ($contactId): void {
                $subject->where(function ($contact) use ($contactId): void {
                    $contact->where('automation_runs.subject_type', 'contact')
                        ->where('automation_runs.subject_id', $contactId);
                })->orWhereExists(function ($deal) use ($contactId): void {
                    $deal->selectRaw('1')
                        ->from('deals')
                        ->where('deals.contact_id', $contactId)
                        ->where('automation_runs.subject_type', 'deal')
                        ->whereColumn('deals.id', 'automation_runs.subject_id');
                })->orWhereExists(function ($contract) use ($contactId): void {
                    $contract->selectRaw('1')
                        ->from('contracts')
                        ->where('contracts.contact_id', $contactId)
                        ->where('automation_runs.subject_type', 'contract')
                        ->whereColumn('contracts.id', 'automation_runs.subject_id');
                });
            })
            ->exists();
    }

    private function isCapped(int $contactId, int $capCount, int $capDays): bool
    {
        if ($capCount <= 0 || $capDays <= 0) {
            return false;
        }

        $sent = CampaignRecipient::query()
            ->where('contact_id', $contactId)
            ->where('status', CampaignRecipientStatus::Sent)
            ->where('processed_at', '>=', now()->subDays($capDays))
            ->whereHas('campaignSend.campaign', function ($campaign): void {
                $campaign->where('type', '!=', CampaignType::Announcement->value);
            })
            ->count();

        return $sent >= $capCount;
    }

    /**
     * Newsletters count toward the cap. They are skipped by it only when the
     * frozen bypass flag is off. Announcements never hit the cap.
     */
    public static function blocksOnFrequencyCap(Campaign $campaign, bool $newsletterBypassesCap): bool
    {
        if ($campaign->type === CampaignType::Announcement) {
            return false;
        }

        if ($campaign->type === CampaignType::Newsletter && $newsletterBypassesCap) {
            return false;
        }

        return true;
    }

    public static function sendClass(Campaign $campaign): SendClass
    {
        return SendClass::forTemplatePurpose($campaign->type->templatePurpose());
    }
}
