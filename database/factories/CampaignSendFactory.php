<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignSendStatus;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\TemplateFamily;
use App\Support\Communications\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignSend>
 */
class CampaignSendFactory extends Factory
{
    protected $model = CampaignSend::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'channel' => Channel::Email,
            'template_family_id' => TemplateFamily::factory(),
            'template_version_id' => null,
            'subject_override' => null,
            'scheduled_at' => null,
            'status' => CampaignSendStatus::Draft,
            'materialised_at' => null,
            'completed_at' => null,
        ];
    }
}
