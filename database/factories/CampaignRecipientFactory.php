<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignRecipientStatus;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignRecipient>
 */
class CampaignRecipientFactory extends Factory
{
    protected $model = CampaignRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_send_id' => CampaignSend::factory(),
            'contact_id' => Contact::factory(),
            'address' => null,
            'site_id' => null,
            'status' => CampaignRecipientStatus::Pending,
            'skip_reason' => null,
            'message_id' => null,
            'claimed_at' => null,
            'processed_at' => null,
            'error' => null,
            'attempts' => 0,
        ];
    }
}
