<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Campaign;
use App\Models\SubscriptionList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CampaignType::Campaign,
            'name' => fake()->words(3, true),
            'status' => CampaignStatus::Draft,
            'subscription_list_id' => SubscriptionList::factory(),
            'segment_id' => null,
            'site_ids' => [],
            'audience_snapshot' => null,
            'estimated_recipients' => null,
            'newsletter_issue_number' => null,
            'ignore_send_window' => false,
            'approval_required' => false,
            'submitted_by' => null,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_reason' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'duplicated_from_id' => null,
            'created_by' => null,
        ];
    }

    public function announcement(): static
    {
        return $this->state(fn (): array => [
            'type' => CampaignType::Announcement,
            'subscription_list_id' => null,
            'site_ids' => [1],
        ]);
    }
}
