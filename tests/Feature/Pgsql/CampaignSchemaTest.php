<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\Segment;
use App\Models\SubscriptionList;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Postgres-only: campaign shape checks and immutability triggers via SQL.
 */
class CampaignSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Campaign checks and immutability triggers are asserted on Postgres.');
        }
    }

    #[Test]
    public function checks_reject_an_announcement_with_a_list(): void
    {
        $list = SubscriptionList::factory()->create();

        $this->assertRejected(function () use ($list): void {
            DB::table('campaigns')->insert($this->campaignRow([
                'type' => 'announcement',
                'subscription_list_id' => $list->id,
                'site_ids' => json_encode([1]),
            ]));
        }, 'campaigns_announcement_shape');
    }

    #[Test]
    public function checks_reject_an_announcement_without_sites(): void
    {
        $this->assertRejected(function (): void {
            DB::table('campaigns')->insert($this->campaignRow([
                'type' => 'announcement',
                'subscription_list_id' => null,
                'site_ids' => json_encode([]),
            ]));
        }, 'campaigns_announcement_shape');
    }

    #[Test]
    public function checks_reject_a_campaign_without_a_list(): void
    {
        $this->assertRejected(function (): void {
            DB::table('campaigns')->insert($this->campaignRow([
                'type' => 'campaign',
                'subscription_list_id' => null,
            ]));
        }, 'campaigns_list_required');
    }

    #[Test]
    public function checks_reject_a_newsletter_without_a_list(): void
    {
        $this->assertRejected(function (): void {
            DB::table('campaigns')->insert($this->campaignRow([
                'type' => 'newsletter',
                'subscription_list_id' => null,
            ]));
        }, 'campaigns_list_required');
    }

    #[Test]
    public function checks_reject_ignore_send_window_on_a_campaign(): void
    {
        $list = SubscriptionList::factory()->create();

        $this->assertRejected(function () use ($list): void {
            DB::table('campaigns')->insert($this->campaignRow([
                'type' => 'campaign',
                'subscription_list_id' => $list->id,
                'ignore_send_window' => true,
            ]));
        }, 'campaigns_window_only_announcement');
    }

    #[Test]
    public function scheduled_audience_cannot_change_via_sql(): void
    {
        $list = SubscriptionList::factory()->create();
        $otherList = SubscriptionList::factory()->create();
        $segment = Segment::factory()->create();
        $id = $this->insertCampaign($this->campaignRow([
            'type' => 'campaign',
            'subscription_list_id' => $list->id,
            'status' => 'scheduled',
        ]));

        $locked = [
            'type' => 'newsletter',
            'subscription_list_id' => $otherList->id,
            'segment_id' => $segment->id,
            'site_ids' => json_encode([9]),
            'audience_snapshot' => json_encode(['list_id' => $otherList->id]),
            'ignore_send_window' => true,
        ];

        foreach ($locked as $field => $value) {
            $this->assertRejected(function () use ($id, $field, $value): void {
                DB::table('campaigns')->where('id', $id)->update([$field => $value]);
            }, 'campaign_audience_immutable');
        }

        $row = DB::table('campaigns')->find($id);
        $this->assertSame('campaign', $row->type);
        $this->assertSame($list->id, (int) $row->subscription_list_id);
        $this->assertNull($row->segment_id);
        $this->assertSame([], $this->jsonList($row->site_ids));
        $this->assertNull($row->audience_snapshot);
        $this->assertTrue(in_array($row->ignore_send_window, [false, 0, '0', 'f'], true));
    }

    #[Test]
    public function scheduled_name_and_status_stay_writable_via_sql(): void
    {
        $list = SubscriptionList::factory()->create();
        $id = $this->insertCampaign($this->campaignRow([
            'subscription_list_id' => $list->id,
            'status' => 'scheduled',
        ]));

        DB::table('campaigns')->where('id', $id)->update([
            'name' => 'Renamed',
            'status' => 'sending',
        ]);

        $row = DB::table('campaigns')->find($id);
        $this->assertSame('Renamed', $row->name);
        $this->assertSame('sending', $row->status);
    }

    #[Test]
    public function leaving_draft_may_freeze_the_audience_in_the_same_write(): void
    {
        $list = SubscriptionList::factory()->create();
        $id = $this->insertCampaign($this->campaignRow([
            'subscription_list_id' => $list->id,
            'status' => 'draft',
        ]));

        DB::table('campaigns')->where('id', $id)->update([
            'status' => 'scheduled',
            'site_ids' => json_encode([4]),
            'audience_snapshot' => json_encode(['list_id' => $list->id]),
        ]);

        $row = DB::table('campaigns')->find($id);
        $this->assertSame('scheduled', $row->status);
        $this->assertSame([4], array_map(intval(...), $this->jsonList($row->site_ids)));

        $this->assertRejected(function () use ($id): void {
            DB::table('campaigns')->where('id', $id)->update([
                'site_ids' => json_encode([8]),
            ]);
        }, 'campaign_audience_immutable');
    }

    #[Test]
    public function pending_approval_audience_can_change_via_sql(): void
    {
        $list = SubscriptionList::factory()->create();
        $otherList = SubscriptionList::factory()->create();
        $id = $this->insertCampaign($this->campaignRow([
            'subscription_list_id' => $list->id,
            'status' => 'pending_approval',
        ]));

        DB::table('campaigns')->where('id', $id)->update([
            'subscription_list_id' => $otherList->id,
            'site_ids' => json_encode([3]),
        ]);

        $row = DB::table('campaigns')->find($id);
        $this->assertSame($otherList->id, (int) $row->subscription_list_id);
    }

    #[Test]
    public function pinned_version_cannot_change_via_sql(): void
    {
        $list = SubscriptionList::factory()->create();
        $campaignId = $this->insertCampaign($this->campaignRow([
            'subscription_list_id' => $list->id,
        ]));
        $family = TemplateFamily::factory()->create();
        $otherFamily = TemplateFamily::factory()->create();
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => 'draft',
        ]);
        $otherVersion = TemplateVersion::query()->create([
            'template_family_id' => $otherFamily->id,
            'version_number' => 1,
            'status' => 'draft',
        ]);

        $sendId = DB::table('campaign_sends')->insertGetId([
            'campaign_id' => $campaignId,
            'channel' => 'email',
            'template_family_id' => $family->id,
            'template_version_id' => null,
            'subject_override' => null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('campaign_sends')->where('id', $sendId)->update([
            'template_family_id' => $otherFamily->id,
            'channel' => 'sms',
            'subject_override' => 'Hello',
        ]);

        DB::table('campaign_sends')->where('id', $sendId)->update([
            'template_family_id' => $family->id,
            'channel' => 'email',
            'template_version_id' => $version->id,
            'subject_override' => 'Pinned subject',
        ]);

        $locked = [
            'template_version_id' => $otherVersion->id,
            'template_family_id' => $otherFamily->id,
            'channel' => 'sms',
            'subject_override' => 'Changed',
        ];

        foreach ($locked as $field => $value) {
            $this->assertRejected(function () use ($sendId, $field, $value): void {
                DB::table('campaign_sends')->where('id', $sendId)->update([$field => $value]);
            }, 'campaign_send_pinned');
        }

        DB::table('campaign_sends')->where('id', $sendId)->update(['status' => 'scheduled']);

        $row = DB::table('campaign_sends')->find($sendId);
        $this->assertSame($version->id, (int) $row->template_version_id);

        $version->update(['status' => 'published', 'published_at' => now()]);
        $published = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 2,
            'status' => 'draft',
        ]);
        $published->update(['status' => 'published', 'published_at' => now()]);
        $replacement = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 3,
            'status' => 'draft',
        ]);
        $replacement->update(['status' => 'published', 'published_at' => now()]);

        DB::table('campaigns')->where('id', $campaignId)->update(['status' => 'pending_approval']);
        DB::table('campaign_sends')->where('id', $sendId)->update([
            'template_version_id' => $published->id,
        ]);
        DB::table('campaign_sends')->where('id', $sendId)->update([
            'template_version_id' => $replacement->id,
        ]);

        $this->assertSame($replacement->id, (int) DB::table('campaign_sends')->find($sendId)->template_version_id);

        DB::table('campaigns')->where('id', $campaignId)->update(['status' => 'scheduled']);
        $this->assertRejected(function () use ($sendId, $published): void {
            DB::table('campaign_sends')->where('id', $sendId)->update([
                'template_version_id' => $published->id,
            ]);
        }, 'campaign_send_pinned');
        $this->assertSame($family->id, (int) $row->template_family_id);
        $this->assertSame('email', $row->channel);
        $this->assertSame('Pinned subject', $row->subject_override);
        $this->assertSame('scheduled', $row->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function campaignRow(array $overrides): array
    {
        return [
            'type' => 'campaign',
            'name' => 'Promo',
            'status' => 'draft',
            'subscription_list_id' => null,
            'segment_id' => null,
            'site_ids' => json_encode([]),
            'audience_snapshot' => null,
            'ignore_send_window' => false,
            'approval_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertCampaign(array $row): int
    {
        return (int) DB::table('campaigns')->insertGetId($row);
    }

    /**
     * @return list<mixed>
     */
    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
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
