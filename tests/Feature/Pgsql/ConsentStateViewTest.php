<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentChannel;
use App\Support\Marketing\ConsentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConsentStateViewTest extends TestCase
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
    public function latest_withdrawn_replaces_granted_and_evidence_is_absent(): void
    {
        $contact = Contact::factory()->create();
        $newsletter = SubscriptionList::factory()->create(['key' => 'newsletter']);
        $promotions = SubscriptionList::factory()->create(['key' => 'promotions']);
        $text = ConsentText::factory()->create();

        ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'subscription_list_id' => $newsletter->id,
            'channel' => Channel::Email,
            'action' => ConsentAction::Granted,
            'source' => ConsentSource::Form,
            'consent_text_id' => $text->id,
            'evidence' => ['ip' => '203.0.113.9'],
            'occurred_at' => now()->subMinute(),
        ]);
        ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'subscription_list_id' => $newsletter->id,
            'channel' => Channel::Email,
            'action' => ConsentAction::Withdrawn,
            'source' => ConsentSource::OneClick,
            'consent_text_id' => null,
            'evidence' => ['ip' => '203.0.113.10'],
            'occurred_at' => now(),
        ]);
        ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'subscription_list_id' => $promotions->id,
            'channel' => Channel::Email,
            'action' => ConsentAction::Granted,
            'source' => ConsentSource::Form,
            'consent_text_id' => $text->id,
            'evidence' => ['ip' => '203.0.113.11'],
            'occurred_at' => now()->subMinute(),
        ]);

        $rows = collect(DB::select(
            'select * from analytics.v_consent_state where contact_id = ? order by list_key',
            [$contact->id],
        ))->keyBy('list_key');

        $this->assertCount(2, $rows);
        $this->assertSame('withdrawn', $rows['newsletter']->action);
        $this->assertSame('one_click', $rows['newsletter']->source);
        $this->assertSame('email', $rows['newsletter']->channel);
        $this->assertSame('granted', $rows['promotions']->action);
        $this->assertFalse(property_exists($rows['newsletter'], 'evidence'));
    }

    #[Test]
    public function ads_channel_appears_without_a_view_change(): void
    {
        $contact = Contact::factory()->create();
        $list = SubscriptionList::query()->where('key', 'advertising')->firstOrFail();
        $text = ConsentText::query()->where('key', 'advertising')->where('locale', 'en')->firstOrFail();

        ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'subscription_list_id' => $list->id,
            'channel' => ConsentChannel::Ads,
            'action' => ConsentAction::Granted,
            'source' => ConsentSource::Form,
            'consent_text_id' => $text->id,
            'evidence' => ['form_id' => 1],
            'occurred_at' => now(),
        ]);

        $row = DB::selectOne(
            'select channel, action, list_key from analytics.v_consent_state where contact_id = ? and list_key = ?',
            [$contact->id, 'advertising'],
        );

        $this->assertNotNull($row);
        $this->assertSame('ads', $row->channel);
        $this->assertSame('granted', $row->action);
        $this->assertSame('advertising', $row->list_key);
    }
}
