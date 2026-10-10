<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentSource;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CampaignAnalyticsViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Analytics views are Postgres-only.');
        }
    }

    #[Test]
    public function campaign_views_expose_pins_and_derived_delivery_flags_without_pii(): void
    {
        $list = SubscriptionList::factory()->create(['key' => 'promotions']);
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Marketing,
        ], [[
            'locale' => 'en',
            'subject' => 'Hello',
            'body_text' => 'Hello',
            'legacy_html' => '<p>Hello</p>',
        ]]);
        $versionId = (int) $family->currentVersion?->id;
        $approvedAt = now()->subHour();
        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Campaign,
            'name' => 'Spring promo',
            'status' => CampaignStatus::Sent,
            'subscription_list_id' => $list->id,
            'site_ids' => [4, 9],
            'approved_at' => $approvedAt,
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $family->id,
            'template_version_id' => $versionId,
            'status' => CampaignSendStatus::Completed,
            'scheduled_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
        $contact = Contact::factory()->create();
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'channel_key' => 'ada@example.com',
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);
        $message = Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Delivered,
            'body_text' => 'Secret body',
            'from_address' => 'news@example.com',
            'to_address' => 'ada@example.com',
            'source' => MessageSource::Campaign,
            'delivery_events' => [
                ['status' => 'delivered'],
                ['status' => 'opened'],
            ],
            'sent_at' => now(),
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $contact->id,
            'address' => 'ada@example.com',
            'status' => CampaignRecipientStatus::Sent,
            'message_id' => $message->id,
            'processed_at' => now(),
            'error' => 'provider said something private',
        ]);

        $campaignRow = DB::selectOne('select * from analytics.v_campaigns where id = ?', [$campaign->id]);
        $this->assertNotNull($campaignRow);
        $this->assertSame('campaign', $campaignRow->type);
        $this->assertSame('Spring promo', $campaignRow->name);
        $this->assertSame('promotions', $campaignRow->list_key);
        $this->assertSame($family->id, (int) $campaignRow->email_template_family_id);
        $this->assertSame($versionId, (int) $campaignRow->email_template_version_id);
        $this->assertNull($campaignRow->sms_template_family_id);
        $this->assertNotNull($campaignRow->window_start);
        $this->assertNotNull($campaignRow->window_end);
        $this->assertObjectNotHasProperty('address', $campaignRow);

        $recipient = DB::selectOne(
            'select * from analytics.v_campaign_recipients where campaign_send_id = ?',
            [$send->id],
        );
        $this->assertNotNull($recipient);
        $this->assertSame($campaign->id, (int) $recipient->campaign_id);
        $this->assertSame($contact->id, (int) $recipient->contact_id);
        $this->assertSame('sent', $recipient->status);
        $this->assertSame($versionId, (int) $recipient->template_version_id);
        $this->assertTrue(filter_var($recipient->delivered, FILTER_VALIDATE_BOOLEAN));
        $this->assertTrue(filter_var($recipient->opened, FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var($recipient->bounced, FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var($recipient->complained, FILTER_VALIDATE_BOOLEAN));
        $this->assertObjectNotHasProperty('address', $recipient);
        $this->assertObjectNotHasProperty('error', $recipient);
    }

    #[Test]
    public function consent_events_view_keeps_history_and_drops_evidence(): void
    {
        $contact = Contact::factory()->create();
        $list = SubscriptionList::factory()->create(['key' => 'newsletter']);
        $text = ConsentText::factory()->create();
        ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'subscription_list_id' => $list->id,
            'channel' => Channel::Email,
            'action' => ConsentAction::Granted,
            'source' => ConsentSource::Form,
            'consent_text_id' => $text->id,
            'evidence' => ['ip' => '203.0.113.9'],
            'occurred_at' => now()->subDay(),
        ]);
        ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'subscription_list_id' => $list->id,
            'channel' => Channel::Email,
            'action' => ConsentAction::Withdrawn,
            'source' => ConsentSource::OneClick,
            'evidence' => ['ip' => '203.0.113.10'],
            'occurred_at' => now(),
        ]);

        $rows = DB::select(
            'select * from analytics.v_consent_events where contact_id = ? order by occurred_at',
            [$contact->id],
        );

        $this->assertCount(2, $rows);
        $this->assertSame('granted', $rows[0]->action);
        $this->assertSame('withdrawn', $rows[1]->action);
        $this->assertSame('newsletter', $rows[1]->list_key);
        $this->assertObjectNotHasProperty('evidence', $rows[1]);
    }
}
