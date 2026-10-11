<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\ContactChannelType;
use App\Models\Campaign;
use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\SubscriptionList;
use App\Support\Communications\Channel;
use App\Support\Marketing\CampaignReport;
use App\Support\Marketing\ConsentChannel;
use App\Support\Marketing\ConsentState;
use Database\Seeders\ConsentTextSeeder;
use Database\Seeders\Demo\MarketingDemo;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketingDemoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeded_contacts_are_consented_and_campaign_reports_are_not_empty(): void
    {
        $this->seed(SubscriptionListSeeder::class);
        $this->seed(ConsentTextSeeder::class);

        $contacts = [];
        foreach (['+34610000001', '+34610000002', '+34610000003'] as $phone) {
            $contact = Contact::factory()->create();
            ContactChannel::query()->create([
                'contact_id' => $contact->id,
                'type' => ContactChannelType::Sms,
                'value' => $phone,
                'is_primary' => true,
                'opted_in' => true,
            ]);
            $contacts[] = $contact;
        }

        Http::fake();
        MarketingDemo::apply();
        Http::assertNothingSent();

        $newsletter = SubscriptionList::query()->where('key', 'newsletter')->firstOrFail();
        $promotions = SubscriptionList::query()->where('key', 'promotions')->firstOrFail();
        $advertising = SubscriptionList::query()->where('key', 'advertising')->firstOrFail();

        foreach ($contacts as $contact) {
            $this->assertTrue(ConsentState::isGranted($contact->id, ConsentChannel::Ads, $advertising->id));
            $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $newsletter->id));
            $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $promotions->id));
            $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Sms, $promotions->id));
        }

        $offer = Campaign::query()->where('name', 'Oferta de primavera')->firstOrFail();
        $this->assertSame(CampaignStatus::Sent, $offer->status);
        $offerReport = (new CampaignReport)->payload($offer);
        $this->assertGreaterThan(0, $offerReport['total']['sent']);
        $this->assertGreaterThan(0, $offerReport['total']['opened']);

        $issue = Campaign::query()
            ->where('type', CampaignType::Newsletter)
            ->where('status', CampaignStatus::Sent)
            ->orderBy('newsletter_issue_number')
            ->firstOrFail();
        $this->assertNotNull($issue->newsletter_issue_number);
        $issueReport = (new CampaignReport)->payload($issue);
        $this->assertGreaterThan(0, $issueReport['total']['sent']);
        $this->assertGreaterThan(0, $issueReport['total']['opened']);
        $this->assertSame(2, $issueReport['total']['unsubscribed']);

        $this->assertTrue(Campaign::query()
            ->where('type', CampaignType::Newsletter)
            ->where('status', CampaignStatus::Scheduled)
            ->exists());

        MarketingDemo::apply();
        $this->assertSame(1, Campaign::query()->where('name', 'Oferta de primavera')->count());
        $this->assertSame(1, ConsentEvent::query()
            ->where('contact_id', $contacts[2]->id)
            ->where('channel', ConsentChannel::Email->value)
            ->where('subscription_list_id', $newsletter->id)
            ->count());
    }
}
