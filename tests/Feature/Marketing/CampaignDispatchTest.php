<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\PlaybookKind;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Jobs\SendCampaignBatch;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\CommunicationAccount;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Playbook;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Models\TemplateFamily;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageSource;
use App\Support\Marketing\CampaignBatchSender;
use App\Support\Marketing\CampaignLifecycle;
use App\Support\Marketing\CampaignMaterialiser;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentWriter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class CampaignDispatchTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Europe/Madrid'));
        $sequence = 0;
        Http::fake(function (Request $request) use (&$sequence) {
            $sequence++;
            if (str_contains($request->url(), 'brevo')) {
                return Http::response(['messageId' => 'brevo-test-'.$sequence], 201);
            }

            return Http::response(['sid' => 'SM-test-'.$sequence, 'id' => 'sinch-'.$sequence], 201);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function rerunning_a_batch_sends_each_recipient_once(): void
    {
        [$send, $account, $first, $second] = $this->pendingPair();
        $sender = app(CampaignBatchSender::class);

        $sender->send($send->id, [$first->id], $account->id, 600);
        $sender->send($send->id, [$first->id, $second->id], $account->id, 600);
        $sender->send($send->id, [$first->id, $second->id], $account->id, 600);

        Http::assertSentCount(2);
        $this->assertSame(1, $this->messagesFor((int) $first->contact_id));
        $this->assertSame(1, $this->messagesFor((int) $second->contact_id));
        $this->assertSame(CampaignRecipientStatus::Sent, $first->refresh()->status);
        $this->assertSame(CampaignRecipientStatus::Sent, $second->refresh()->status);
    }

    #[Test]
    public function a_second_claim_of_an_in_flight_row_updates_nothing(): void
    {
        [$send, $account, $recipient] = $this->pendingPair();
        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => CampaignRecipientStatus::Claimed->value,
            'claimed_at' => now(),
            'attempts' => 1,
        ]);

        $updated = DB::table('campaign_recipients')
            ->where('id', $recipient->id)
            ->where('status', CampaignRecipientStatus::Pending->value)
            ->update(['status' => CampaignRecipientStatus::Claimed->value]);

        app(CampaignBatchSender::class)->send($send->id, [$recipient->id], $account->id, 600);

        $this->assertSame(0, $updated);
        Http::assertNothingSent();
        $recipient->refresh();
        $this->assertSame(CampaignRecipientStatus::Claimed, $recipient->status);
        $this->assertSame(1, $recipient->attempts);
    }

    #[Test]
    public function a_stale_claim_is_released_and_sent_once(): void
    {
        [$send, , $recipient] = $this->pendingPair();
        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => CampaignRecipientStatus::Claimed->value,
            'claimed_at' => now()->subMinutes(16),
            'attempts' => 1,
        ]);

        Artisan::call('campaigns:complete-sends');

        Http::assertSentCount(1);
        $this->assertSame(CampaignRecipientStatus::Sent, $recipient->refresh()->status);
        $this->assertSame(1, $this->messagesFor((int) $recipient->contact_id));
    }

    #[Test]
    public function withdrawing_consent_after_scheduling_skips_no_consent(): void
    {
        [$send, $account, $recipient, , $contact, $list] = $this->pendingPair();
        ConsentWriter::withdraw($contact, Channel::Email, $list, ConsentSource::UnsubscribeLink);

        app(CampaignBatchSender::class)->send($send->id, [$recipient->id], $account->id, 600);

        Http::assertNothingSent();
        $recipient->refresh();
        $this->assertSame(CampaignRecipientStatus::Skipped, $recipient->status);
        $this->assertSame(CampaignSkipReason::NoConsent, $recipient->skip_reason);
    }

    #[Test]
    public function a_third_marketing_send_inside_the_cap_window_is_skipped(): void
    {
        [$send, $account, $recipient, , $contact] = $this->pendingPair(capCount: 2, capDays: 7);
        CampaignRecipient::factory()->count(2)->create([
            'contact_id' => $contact->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now()->subDay(),
            'address' => 'ada@example.com',
        ]);

        app(CampaignBatchSender::class)->send($send->id, [$recipient->id], $account->id, 600);

        Http::assertNothingSent();
        $recipient->refresh();
        $this->assertSame(CampaignRecipientStatus::Skipped, $recipient->status);
        $this->assertSame(CampaignSkipReason::FrequencyCapped, $recipient->skip_reason);
    }

    #[Test]
    public function a_recipient_outside_the_send_window_is_deferred_until_it_opens(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 23:00:00', 'Europe/Madrid'));
        Setting::setGeneral(Setting::general()->with(sendWindowStart: '09:00', sendWindowEnd: '21:00'));
        Queue::fake();

        [$send, $account, $recipient] = $this->pendingPair();

        app(CampaignBatchSender::class)->send($send->id, [$recipient->id], $account->id, 600);

        $recipient->refresh();
        $this->assertSame(CampaignRecipientStatus::Pending, $recipient->status);
        $this->assertNull($recipient->skip_reason);
        $this->assertSame(0, $recipient->attempts);
        Http::assertNothingSent();

        $expected = CarbonImmutable::parse('2026-10-11 09:00:00', 'Europe/Madrid')->utc();
        Queue::assertPushed(SendCampaignBatch::class, function (SendCampaignBatch $job) use ($expected, $recipient): bool {
            return $job->recipientIds === [$recipient->id]
                && $job->delay instanceof \DateTimeInterface
                && CarbonImmutable::instance($job->delay)->utc()->equalTo($expected);
        });
    }

    #[Test]
    public function pause_stops_the_batch_and_resume_sends_without_a_duplicate(): void
    {
        [$campaign, $send, $account, $first, $second] = $this->pendingPair(withCampaign: true);
        $actor = Employee::factory()->withoutRoleGrant()->create();
        $lifecycle = app(CampaignLifecycle::class);

        $lifecycle->pause($campaign, $actor);
        app(CampaignBatchSender::class)->send($send->id, [$first->id, $second->id], $account->id, 600);

        Http::assertNothingSent();
        $this->assertSame(CampaignRecipientStatus::Pending, $first->refresh()->status);
        $this->assertSame(CampaignRecipientStatus::Pending, $second->refresh()->status);
        $this->assertNull($first->skip_reason);

        $lifecycle->resume($campaign->fresh(), $actor);

        Http::assertSentCount(2);
        $this->assertSame(1, $this->messagesFor((int) $first->contact_id));
        $this->assertSame(1, $this->messagesFor((int) $second->contact_id));
        $this->assertSame(CampaignStatus::Sent, $campaign->refresh()->status);
    }

    #[Test]
    public function cancel_marks_remaining_recipients_skipped(): void
    {
        [$campaign, $send, $account, $pending, $inFlight] = $this->pendingPair(withCampaign: true);
        $actor = Employee::factory()->withoutRoleGrant()->create();

        DB::table('campaign_recipients')->where('id', $inFlight->id)->update([
            'status' => CampaignRecipientStatus::Pending->value,
        ]);

        app(CampaignLifecycle::class)->cancel($campaign, $actor);

        $this->assertSame(CampaignSkipReason::Cancelled, $pending->refresh()->skip_reason);
        $this->assertSame(CampaignRecipientStatus::Skipped, $pending->status);

        DB::table('campaign_recipients')->where('id', $inFlight->id)->update([
            'status' => CampaignRecipientStatus::Pending->value,
            'skip_reason' => null,
            'processed_at' => null,
        ]);

        app(CampaignBatchSender::class)->send($send->id, [$inFlight->id], $account->id, 600);

        Http::assertNothingSent();
        $inFlight->refresh();
        $this->assertSame(CampaignRecipientStatus::Skipped, $inFlight->status);
        $this->assertSame(CampaignSkipReason::Cancelled, $inFlight->skip_reason);
    }

    #[Test]
    public function a_due_send_stores_campaign_provenance_on_the_message(): void
    {
        $world = $this->world();
        $contact = $this->consentedContact($world['site'], $world['list'], 'ada@example.com');
        $campaign = Campaign::factory()->create([
            'status' => CampaignStatus::Scheduled,
            'subscription_list_id' => $world['list']->id,
            'audience_snapshot' => $this->snapshot($world['list']->id, 0, 7),
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $world['family']->id,
            'template_version_id' => $world['versionId'],
            'status' => CampaignSendStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
            'subject_override' => 'Spring',
        ]);

        Artisan::call('campaigns:dispatch-due');

        $recipient = CampaignRecipient::query()->where('campaign_send_id', $send->id)->where('contact_id', $contact->id)->firstOrFail();
        $message = Message::query()
            ->whereIn('message_thread_id', MessageThread::query()->where('contact_id', $contact->id)->select('id'))
            ->firstOrFail();

        $this->assertSame(CampaignStatus::Sent, $campaign->refresh()->status);
        $this->assertSame(MessageSource::Campaign, $message->source);
        $this->assertSame($campaign->id, $message->source_ref['campaign_id']);
        $this->assertSame($send->id, $message->source_ref['campaign_send_id']);
        $this->assertSame($recipient->id, $message->source_ref['campaign_recipient_id']);
        $this->assertSame($world['list']->id, $message->source_ref['subscription_list_id']);
        $this->assertSame($world['versionId'], $message->detail['template']['version_id']);
        $this->assertArrayNotHasKey('test', $message->detail ?? []);
    }

    #[Test]
    public function materialising_twice_inserts_each_contact_once(): void
    {
        Queue::fake();
        $world = $this->world();
        $kept = $this->consentedContact($world['site'], $world['list'], 'ada@example.com');
        $bare = Contact::factory()->create(['email' => null]);
        ConsentWriter::grant($bare, Channel::Email, $world['list'], ConsentSource::Form, $world['text']->id);

        $campaign = Campaign::factory()->create([
            'status' => CampaignStatus::Sending,
            'subscription_list_id' => $world['list']->id,
            'audience_snapshot' => $this->snapshot($world['list']->id, 0, 7),
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $world['family']->id,
            'template_version_id' => $world['versionId'],
            'status' => CampaignSendStatus::Materialising,
        ]);

        $materialiser = app(CampaignMaterialiser::class);
        $materialiser->materialise($send);
        $materialiser->materialise($send->fresh());

        $this->assertSame(2, CampaignRecipient::query()->where('campaign_send_id', $send->id)->count());
        $keptRow = CampaignRecipient::query()->where('campaign_send_id', $send->id)->where('contact_id', $kept->id)->firstOrFail();
        $bareRow = CampaignRecipient::query()->where('campaign_send_id', $send->id)->where('contact_id', $bare->id)->firstOrFail();
        $this->assertSame(CampaignRecipientStatus::Pending, $keptRow->status);
        $this->assertSame('ada@example.com', $keptRow->address);
        $this->assertSame($world['site']->id, $keptRow->site_id);
        $this->assertSame(CampaignRecipientStatus::Skipped, $bareRow->status);
        $this->assertSame(CampaignSkipReason::NoAddress, $bareRow->skip_reason);
    }

    #[Test]
    public function an_open_delinquency_is_skipped_and_a_cured_case_sends(): void
    {
        [$send, $account, $openRow, $clearRow] = $this->pendingPair(exclusions: ['delinquent' => true]);
        $openContract = Contract::factory()->create(['contact_id' => $openRow->contact_id]);
        Delinquency::factory()->create(['contract_id' => $openContract->id]);
        $clearContract = Contract::factory()->create(['contact_id' => $clearRow->contact_id]);
        Delinquency::factory()->cured()->create(['contract_id' => $clearContract->id]);

        app(CampaignBatchSender::class)->send($send->id, [$openRow->id, $clearRow->id], $account->id, 600);

        $this->assertSame(CampaignRecipientStatus::Skipped, $openRow->refresh()->status);
        $this->assertSame(CampaignSkipReason::ExcludedDelinquent, $openRow->skip_reason);
        $this->assertSame(CampaignRecipientStatus::Sent, $clearRow->refresh()->status);
        $this->assertNull($clearRow->skip_reason);
    }

    #[Test]
    public function an_open_excluded_playbook_is_skipped(): void
    {
        [$send, $account, $enrolled, $clear] = $this->pendingPair(exclusions: [
            'playbook_kinds' => [PlaybookKind::DebtProcess->value],
        ]);
        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::DebtProcess,
            'name' => 'Debt',
            'is_active' => true,
            'enrolment_filters' => [],
        ]);
        $automation = Automation::query()->create([
            'name' => 'Debt',
            'status' => AutomationStatus::Active,
            'playbook_id' => $playbook->id,
        ]);
        AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'status' => AutomationRunStatus::Running,
            'subject_type' => 'contact',
            'subject_id' => $enrolled->contact_id,
        ]);

        app(CampaignBatchSender::class)->send($send->id, [$enrolled->id, $clear->id], $account->id, 600);

        $this->assertSame(CampaignRecipientStatus::Skipped, $enrolled->refresh()->status);
        $this->assertSame(CampaignSkipReason::ExcludedPlaybook, $enrolled->skip_reason);
        $this->assertSame(CampaignRecipientStatus::Sent, $clear->refresh()->status);
    }

    #[Test]
    public function materialisation_keeps_contacts_inside_the_creators_sites(): void
    {
        Queue::fake();
        $world = $this->world();
        $other = Site::factory()->create(['timezone' => 'Europe/Madrid']);
        $inside = $this->consentedContact($world['site'], $world['list'], 'inside@example.com', $world['text']);
        $outside = $this->consentedContact($other, $world['list'], 'outside@example.com', $world['text']);
        $creator = $this->employeeWithSiteScopedPermission(Permission::ContactView, $world['site']);

        $campaign = Campaign::factory()->create([
            'status' => CampaignStatus::Sending,
            'subscription_list_id' => $world['list']->id,
            'created_by' => $creator->id,
            'audience_snapshot' => $this->snapshot($world['list']->id, 0, 7),
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $world['family']->id,
            'template_version_id' => $world['versionId'],
            'status' => CampaignSendStatus::Materialising,
        ]);

        app(CampaignMaterialiser::class)->materialise($send);

        $ids = CampaignRecipient::query()->where('campaign_send_id', $send->id)->pluck('contact_id');
        $this->assertTrue($ids->contains($inside->id));
        $this->assertFalse($ids->contains($outside->id));
    }

    /**
     * @return array{site: Site, list: SubscriptionList, account: CommunicationAccount, family: TemplateFamily, versionId: int, text: ConsentText}
     */
    private function world(): array
    {
        $site = Site::factory()->create(['timezone' => 'Europe/Madrid']);
        $account = $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Marketing,
        ], [[
            'locale' => 'en',
            'subject' => 'Hello',
            'body_text' => 'Hello there',
            'legacy_html' => '<p>Hello there</p>',
        ]]);

        return [
            'site' => $site,
            'list' => $list,
            'account' => $account,
            'family' => $family,
            'versionId' => (int) $family->currentVersion?->id,
            'text' => ConsentText::factory()->create(),
        ];
    }

    private function consentedContact(Site $site, SubscriptionList $list, string $email, ?ConsentText $text = null): Contact
    {
        $contact = Contact::factory()->create(['email' => $email]);
        $this->givePrimaryEmail($contact, $email);
        $contact->sites()->attach($site->id);
        $text ??= ConsentText::factory()->create();
        ConsentWriter::grant($contact, Channel::Email, $list, ConsentSource::Form, $text->id);

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $exclusions
     * @return array<int, mixed>
     */
    private function pendingPair(int $capCount = 0, int $capDays = 7, bool $withCampaign = false, array $exclusions = []): array
    {
        $world = $this->world();
        $firstContact = $this->consentedContact($world['site'], $world['list'], 'ada@example.com', $world['text']);
        $secondContact = $this->consentedContact($world['site'], $world['list'], 'bea@example.com', $world['text']);
        $campaign = Campaign::factory()->create([
            'status' => CampaignStatus::Sending,
            'subscription_list_id' => $world['list']->id,
            'audience_snapshot' => $this->snapshot($world['list']->id, $capCount, $capDays, $exclusions),
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $world['family']->id,
            'template_version_id' => $world['versionId'],
            'status' => CampaignSendStatus::Sending,
            'subject_override' => 'Spring',
        ]);
        $first = CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $firstContact->id,
            'address' => 'ada@example.com',
            'site_id' => $world['site']->id,
            'status' => CampaignRecipientStatus::Pending,
        ]);
        $second = CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $secondContact->id,
            'address' => 'bea@example.com',
            'site_id' => $world['site']->id,
            'status' => CampaignRecipientStatus::Pending,
        ]);

        if ($withCampaign) {
            return [$campaign, $send, $world['account'], $first, $second];
        }

        return [$send, $world['account'], $first, $second, $firstContact, $world['list']];
    }

    private function messagesFor(int $contactId): int
    {
        return Message::query()
            ->whereIn('message_thread_id', MessageThread::query()->where('contact_id', $contactId)->select('id'))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $exclusions
     * @return array<string, mixed>
     */
    private function snapshot(int $listId, int $capCount, int $capDays, array $exclusions = []): array
    {
        $kinds = $exclusions['playbook_kinds'] ?? [];

        return [
            'list_id' => $listId,
            'segment_filter' => null,
            'site_ids' => [],
            'exclusions' => [
                'delinquent' => (bool) ($exclusions['delinquent'] ?? false),
                'playbook_kinds' => is_array($kinds) ? array_values($kinds) : [],
            ],
            'frequency_cap' => [
                'count' => $capCount,
                'days' => $capDays,
            ],
        ];
    }
}
