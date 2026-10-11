<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AdCampaign;
use Illuminate\Http\Request;

/**
 * @mixin AdCampaign
 */
class AdCampaignResource extends BaseResource
{
    private ?int $suggestedSiteId = null;

    /** @var list<array{currency: string, cost: string, impressions: int, clicks: int}>|null */
    private ?array $spendTotals = null;

    /**
     * @param  list<array{currency: string, cost: string, impressions: int, clicks: int}>|null  $spend
     */
    public static function forCampaign(AdCampaign $campaign, ?int $suggestedSiteId, ?array $spend): self
    {
        $resource = new self($campaign);
        $resource->suggestedSiteId = $suggestedSiteId;
        $resource->spendTotals = $spend;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $account = $this->account;

        $data = [
            'id' => $this->id,
            'ad_platform_account_id' => $this->ad_platform_account_id,
            'provider' => $account?->provider->value,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'channel_type' => $this->channel_type,
            'site_id' => $this->site_id,
            'unit_class_id' => $this->unit_class_id,
            'utm_campaign_hint' => $this->utm_campaign_hint,
            'mapped_by' => $this->mapped_by,
            'mapped_at' => $this->datetime($this->mapped_at),
            'suggested_site_id' => $this->suggestedSiteId,
        ];

        if ($this->spendTotals !== null) {
            $data['spend'] = $this->spendTotals;
        }

        return $data;
    }
}
