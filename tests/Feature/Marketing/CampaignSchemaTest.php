<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\TemplateVersionStatus;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Segment;
use App\Models\SubscriptionList;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Communications\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class CampaignSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function checks_reject_an_announcement_with_a_list(): void
    {
        $list = SubscriptionList::factory()->create();

        $this->assertRejected(function () use ($list): void {
            Campaign::query()->create([
                'type' => CampaignType::Announcement,
                'name' => 'Gate',
                'subscription_list_id' => $list->id,
                'site_ids' => [1],
            ]);
        }, 'campaigns_announcement_shape');
    }

    #[Test]
    public function checks_reject_an_announcement_without_sites(): void
    {
        $this->assertRejected(function (): void {
            Campaign::query()->create([
                'type' => CampaignType::Announcement,
                'name' => 'Gate',
                'subscription_list_id' => null,
                'site_ids' => [],
            ]);
        }, 'campaigns_announcement_shape');
    }

    #[Test]
    public function checks_reject_a_campaign_without_a_list(): void
    {
        $this->assertRejected(function (): void {
            Campaign::query()->create([
                'type' => CampaignType::Campaign,
                'name' => 'Promo',
                'subscription_list_id' => null,
                'site_ids' => [],
            ]);
        }, 'campaigns_list_required');
    }

    #[Test]
    public function checks_reject_a_newsletter_without_a_list(): void
    {
        $this->assertRejected(function (): void {
            Campaign::query()->create([
                'type' => CampaignType::Newsletter,
                'name' => 'Issue',
                'subscription_list_id' => null,
                'site_ids' => [],
            ]);
        }, 'campaigns_list_required');
    }

    #[Test]
    public function checks_reject_ignore_send_window_on_a_campaign(): void
    {
        $list = SubscriptionList::factory()->create();

        $this->assertRejected(function () use ($list): void {
            Campaign::query()->create([
                'type' => CampaignType::Campaign,
                'name' => 'Promo',
                'subscription_list_id' => $list->id,
                'site_ids' => [],
                'ignore_send_window' => true,
            ]);
        }, 'campaigns_window_only_announcement');
    }

    #[Test]
    public function checks_accept_a_campaign_and_an_announcement(): void
    {
        $campaign = Campaign::factory()->create();
        $announcement = Campaign::factory()->announcement()->create();

        $this->assertSame(CampaignType::Campaign, $campaign->type);
        $this->assertNotNull($campaign->subscription_list_id);
        $this->assertSame([], $campaign->site_ids);
        $this->assertFalse($campaign->ignore_send_window);

        $this->assertSame(CampaignType::Announcement, $announcement->type);
        $this->assertNull($announcement->subscription_list_id);
        $this->assertSame([1], $announcement->site_ids);
    }

    #[Test]
    public function draft_and_pending_approval_can_change_audience_fields(): void
    {
        $campaign = Campaign::factory()->create();
        $otherList = SubscriptionList::factory()->create();
        $segment = Segment::factory()->create();

        $campaign->update([
            'subscription_list_id' => $otherList->id,
            'site_ids' => [3],
        ]);

        $campaign->update(['status' => CampaignStatus::PendingApproval]);
        $campaign->update([
            'type' => CampaignType::Newsletter,
            'segment_id' => $segment->id,
            'audience_snapshot' => ['list_id' => $otherList->id],
        ]);

        $campaign->refresh();

        $this->assertSame(CampaignStatus::PendingApproval, $campaign->status);
        $this->assertSame(CampaignType::Newsletter, $campaign->type);
        $this->assertSame($otherList->id, $campaign->subscription_list_id);
        $this->assertSame($segment->id, $campaign->segment_id);
        $this->assertSame([3], $campaign->site_ids);
        $this->assertSame(['list_id' => $otherList->id], $campaign->audience_snapshot);
    }

    #[Test]
    public function scheduled_audience_cannot_change(): void
    {
        $campaign = $this->scheduledCampaign();
        $otherList = SubscriptionList::factory()->create();
        $segment = Segment::factory()->create();

        $locked = [
            'type' => CampaignType::Newsletter,
            'subscription_list_id' => $otherList->id,
            'segment_id' => $segment->id,
            'site_ids' => [9],
            'audience_snapshot' => ['list_id' => $otherList->id],
            'ignore_send_window' => true,
        ];

        foreach ($locked as $field => $value) {
            $this->assertRejected(function () use ($campaign, $field, $value): void {
                $campaign->update([$field => $value]);
            }, 'campaign_audience_immutable');
            $campaign->refresh();
        }

        $this->assertSame(CampaignType::Campaign, $campaign->type);
        $this->assertNull($campaign->segment_id);
        $this->assertSame([], $campaign->site_ids);
        $this->assertNull($campaign->audience_snapshot);
        $this->assertFalse($campaign->ignore_send_window);
    }

    #[Test]
    public function scheduled_name_and_status_stay_writable(): void
    {
        $campaign = $this->scheduledCampaign();

        $campaign->update([
            'name' => 'Renamed',
            'status' => CampaignStatus::Sending,
        ]);

        $campaign->refresh();

        $this->assertSame('Renamed', $campaign->name);
        $this->assertSame(CampaignStatus::Sending, $campaign->status);
    }

    #[Test]
    public function leaving_draft_may_freeze_the_audience_in_the_same_write(): void
    {
        $campaign = Campaign::factory()->create();

        $campaign->update([
            'status' => CampaignStatus::Scheduled,
            'site_ids' => [4],
            'audience_snapshot' => ['list_id' => $campaign->subscription_list_id],
        ]);

        $campaign->refresh();

        $this->assertSame(CampaignStatus::Scheduled, $campaign->status);
        $this->assertSame([4], $campaign->site_ids);
        $this->assertSame(['list_id' => $campaign->subscription_list_id], $campaign->audience_snapshot);

        $this->assertRejected(function () use ($campaign): void {
            $campaign->update(['site_ids' => [8]]);
        }, 'campaign_audience_immutable');
    }

    #[Test]
    public function unpinned_send_can_change_content(): void
    {
        $send = CampaignSend::factory()->create();
        $otherFamily = TemplateFamily::factory()->create();

        $send->update([
            'template_family_id' => $otherFamily->id,
            'channel' => Channel::Sms,
            'subject_override' => 'Hello',
        ]);

        $send->refresh();

        $this->assertSame($otherFamily->id, $send->template_family_id);
        $this->assertSame(Channel::Sms, $send->channel);
        $this->assertSame('Hello', $send->subject_override);
        $this->assertNull($send->template_version_id);
    }

    #[Test]
    public function pinned_send_content_cannot_change(): void
    {
        $send = CampaignSend::factory()->create();
        $familyId = $send->template_family_id;
        $version = $this->versionFor($familyId);
        $otherFamily = TemplateFamily::factory()->create();
        $otherVersion = $this->versionFor($otherFamily->id);

        $send->update([
            'template_version_id' => $version->id,
            'subject_override' => 'Pinned subject',
        ]);

        $send->refresh();
        $this->assertSame($version->id, $send->template_version_id);
        $this->assertSame('Pinned subject', $send->subject_override);

        $locked = [
            'template_version_id' => $otherVersion->id,
            'template_family_id' => $otherFamily->id,
            'channel' => Channel::Sms,
            'subject_override' => 'Changed',
        ];

        foreach ($locked as $field => $value) {
            $this->assertRejected(function () use ($send, $field, $value): void {
                $send->update([$field => $value]);
            }, 'campaign_send_pinned');
            $send->refresh();
        }

        $this->assertSame($version->id, $send->template_version_id);
        $this->assertSame($familyId, $send->template_family_id);
        $this->assertSame(Channel::Email, $send->channel);
        $this->assertSame('Pinned subject', $send->subject_override);
    }

    #[Test]
    public function pending_approval_can_repin_the_same_family(): void
    {
        $send = CampaignSend::factory()->create();
        $send->campaign->update(['status' => CampaignStatus::PendingApproval]);
        $first = $this->publishedVersion($send->template_family_id, 1);
        $second = $this->publishedVersion($send->template_family_id, 2);

        $send->update(['template_version_id' => $first->id]);
        $send->update(['template_version_id' => $second->id]);

        $this->assertSame($second->id, $send->refresh()->template_version_id);

        $send->campaign->update(['status' => CampaignStatus::Scheduled]);

        $this->assertRejected(function () use ($send, $first): void {
            $send->update(['template_version_id' => $first->id]);
        }, 'campaign_send_pinned');
    }

    public function pinned_send_status_stays_writable(): void
    {
        $send = CampaignSend::factory()->create();
        $version = $this->versionFor($send->template_family_id);
        $send->update(['template_version_id' => $version->id]);

        $send->update(['status' => CampaignSendStatus::Scheduled]);

        $this->assertSame(CampaignSendStatus::Scheduled, $send->refresh()->status);
        $this->assertSame($version->id, $send->template_version_id);
    }

    private function scheduledCampaign(): Campaign
    {
        $campaign = Campaign::factory()->create();
        $campaign->update(['status' => CampaignStatus::Scheduled]);

        return $campaign->refresh();
    }

    private function versionFor(int $familyId): TemplateVersion
    {
        return TemplateVersion::query()->create([
            'template_family_id' => $familyId,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
    }

    private function publishedVersion(int $familyId, int $number): TemplateVersion
    {
        $version = TemplateVersion::query()->create([
            'template_family_id' => $familyId,
            'version_number' => $number,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $version->update([
            'status' => TemplateVersionStatus::Published,
            'published_at' => now(),
        ]);

        return $version->refresh();
    }

    private function assertRejected(callable $write, string $marker): void
    {
        try {
            DB::transaction($write);
            $this->fail('Write must be rejected.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($marker, $exception->getMessage());
        }
    }
}
