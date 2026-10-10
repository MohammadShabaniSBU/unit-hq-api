<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\CommunicationAccount;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Segment;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Models\TemplateFamily;
use App\Settings\MarketingSettings;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use App\Support\Communications\Channel;
use App\Support\Marketing\CampaignBatchSender;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentWriter;
use Carbon\Carbon;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class CampaignNewsletterTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Europe/Madrid'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_promotions_list_is_rejected_for_a_newsletter(): void
    {
        $employee = $this->employeeWithPermissions([Permission::CampaignManage]);
        $list = SubscriptionList::factory()->create(['kind' => 'promotions']);

        Sanctum::actingAs($employee);

        $this->postJson('/api/campaigns', [
            'type' => 'newsletter',
            'name' => 'October',
            'subscription_list_id' => $list->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.subscription_list_id.0', __('errors.campaigns.newsletter_list_kind'));
    }

    #[Test]
    public function issue_numbers_are_sequential_and_cancelled_numbers_stay(): void
    {
        $employee = $this->employeeWithPermissions([
            Permission::CampaignManage,
            Permission::CampaignApprove,
        ]);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = $this->emailFamily();

        Sanctum::actingAs($employee);

        $first = $this->submitIssue($list->id, $family->id, 'One');
        $second = $this->submitIssue($list->id, $family->id, 'Two');

        $this->assertSame(1, $first->newsletter_issue_number);
        $this->assertSame(2, $second->newsletter_issue_number);
        $this->assertTrue((bool) data_get($first->audience_snapshot, 'frequency_cap.newsletter_bypasses_cap'));

        $this->postJson("/api/campaigns/{$second->id}/cancel")->assertOk();
        $this->assertSame(2, $second->refresh()->newsletter_issue_number);
        $this->assertSame(CampaignStatus::Cancelled, $second->status);

        $third = $this->submitIssue($list->id, $family->id, 'Three');
        $this->assertSame(3, $third->newsletter_issue_number);
    }

    #[Test]
    public function reject_then_resubmit_keeps_the_issue_number(): void
    {
        $submitter = $this->employeeWithPermissions([Permission::CampaignManage]);
        $approver = $this->employeeWithPermissions([Permission::CampaignApprove]);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = $this->emailFamily();

        Sanctum::actingAs($submitter);
        $issue = $this->submitIssue($list->id, $family->id, 'October');
        $this->assertSame(1, $issue->newsletter_issue_number);
        $this->assertSame(CampaignStatus::PendingApproval, $issue->status);

        Sanctum::actingAs($approver);
        $this->postJson("/api/campaigns/{$issue->id}/reject", [
            'reason' => 'Hold',
        ])->assertOk();

        Sanctum::actingAs($submitter);
        $this->postJson("/api/campaigns/{$issue->id}/submit")->assertOk();

        $this->assertSame(1, $issue->refresh()->newsletter_issue_number);
    }

    #[Test]
    public function a_newsletter_at_the_cap_sends_when_bypass_is_on_and_counts_toward_later_campaigns(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => 'brevo-nl'], 201),
        ]);

        $world = $this->world();
        $contact = $this->consentedContact($world['site'], $world['list'], 'ada@example.com', $world['text']);
        CampaignRecipient::factory()->create([
            'contact_id' => $contact->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now()->subDay(),
            'address' => 'ada@example.com',
        ]);

        [$send, $account, $recipient] = $this->sendingNewsletter($world, $contact, bypass: true, capCount: 1);

        app(CampaignBatchSender::class)->send($send->id, [$recipient->id], $account->id, 600);

        $this->assertSame(CampaignRecipientStatus::Sent, $recipient->refresh()->status);
        Http::assertSentCount(1);

        [$campaignSend, $campaignAccount, $campaignRecipient] = $this->sendingCampaign($world, $contact, capCount: 2);
        app(CampaignBatchSender::class)->send($campaignSend->id, [$campaignRecipient->id], $campaignAccount->id, 600);

        $campaignRecipient->refresh();
        $this->assertSame(CampaignRecipientStatus::Skipped, $campaignRecipient->status);
        $this->assertSame(CampaignSkipReason::FrequencyCapped, $campaignRecipient->skip_reason);
    }

    #[Test]
    public function a_newsletter_at_the_cap_is_skipped_when_bypass_is_off(): void
    {
        Http::fake();
        $world = $this->world();
        $contact = $this->consentedContact($world['site'], $world['list'], 'ada@example.com', $world['text']);
        CampaignRecipient::factory()->create([
            'contact_id' => $contact->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now()->subDay(),
            'address' => 'ada@example.com',
        ]);

        [$send, $account, $recipient] = $this->sendingNewsletter($world, $contact, bypass: false, capCount: 1);

        app(CampaignBatchSender::class)->send($send->id, [$recipient->id], $account->id, 600);

        Http::assertNothingSent();
        $recipient->refresh();
        $this->assertSame(CampaignRecipientStatus::Skipped, $recipient->status);
        $this->assertSame(CampaignSkipReason::FrequencyCapped, $recipient->skip_reason);
    }

    #[Test]
    public function audience_preview_does_not_count_a_capped_contact_when_bypass_is_on(): void
    {
        $employee = $this->employeeWithPermissions([Permission::CampaignView]);
        $world = $this->world();
        $contact = $this->consentedContact($world['site'], $world['list'], 'ada@example.com', $world['text']);
        CampaignRecipient::factory()->create([
            'contact_id' => $contact->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now()->subDay(),
            'address' => 'ada@example.com',
        ]);

        Setting::setMarketing(MarketingSettings::default()->with(
            frequencyCapCount: 1,
            newsletterBypassesCap: true,
            excludeDelinquent: false,
        ));

        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Newsletter,
            'subscription_list_id' => $world['list']->id,
        ]);
        CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $world['family']->id,
            'template_version_id' => $world['versionId'],
        ]);

        Sanctum::actingAs($employee);

        $this->postJson("/api/campaigns/{$campaign->id}/audience-preview")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.by_skip_reason_estimate.frequency_capped', 0);

        Setting::setMarketing(Setting::marketing()->with(newsletterBypassesCap: false));

        $this->postJson("/api/campaigns/{$campaign->id}/audience-preview")
            ->assertOk()
            ->assertJsonPath('data.by_skip_reason_estimate.frequency_capped', 1);
    }

    #[Test]
    public function a_newsletter_send_renders_the_issue_tokens(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => 'brevo-nl'], 201),
        ]);

        $world = $this->world('Issue {{newsletter.issue_number}} {{newsletter.name}}');
        $world['list']->update([
            'labels' => ['en' => 'English', 'es' => 'Centro'],
        ]);
        $contact = $this->consentedContact($world['site'], $world['list'], 'ada@example.com', $world['text']);
        $contact->forceFill(['locale' => 'es'])->save();

        [$send, $account, $recipient] = $this->sendingNewsletter($world, $contact, bypass: true, capCount: 0, issueNumber: 7);

        app(CampaignBatchSender::class)->send($send->id, [$recipient->id], $account->id, 600);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Issue 7 Centro'));
    }

    #[Test]
    public function issue_list_and_next_issue_follow_the_latest_sent_newsletter(): void
    {
        $employee = $this->employeeWithPermissions([
            Permission::CampaignView,
            Permission::CampaignManage,
            Permission::MarketingView,
        ]);
        $list = SubscriptionList::factory()->create();
        $promotions = SubscriptionList::factory()->create(['kind' => 'promotions']);
        $segment = Segment::factory()->create();
        $family = $this->emailFamily();
        $sent = Campaign::factory()->create([
            'type' => CampaignType::Newsletter,
            'status' => CampaignStatus::Sent,
            'name' => 'September',
            'subscription_list_id' => $list->id,
            'segment_id' => $segment->id,
            'newsletter_issue_number' => 2,
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $sent->id,
            'channel' => Channel::Email,
            'template_family_id' => $family->id,
            'template_version_id' => (int) $family->currentVersion?->id,
            'scheduled_at' => now()->subDay(),
            'status' => CampaignSendStatus::Completed,
            'completed_at' => now()->subHour(),
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now()->subHour(),
        ]);
        Campaign::factory()->create([
            'type' => CampaignType::Newsletter,
            'status' => CampaignStatus::Cancelled,
            'subscription_list_id' => $list->id,
            'newsletter_issue_number' => 9,
        ]);

        Sanctum::actingAs($employee);

        $this->getJson("/api/subscription-lists/{$list->id}/newsletters")
            ->assertOk()
            ->assertJsonPath('data.0.newsletter_issue_number', 9)
            ->assertJsonPath('data.0.status', 'cancelled')
            ->assertJsonPath('data.1.id', $sent->id)
            ->assertJsonPath('data.1.status', 'sent')
            ->assertJsonPath('data.1.counts.sent', 1);

        $this->postJson("/api/subscription-lists/{$promotions->id}/newsletters/next")
            ->assertUnprocessable()
            ->assertJsonPath('errors.subscription_list_id.0', __('errors.campaigns.newsletter_list_kind'));

        $empty = SubscriptionList::factory()->create();
        $this->postJson("/api/subscription-lists/{$empty->id}/newsletters/next")
            ->assertUnprocessable()
            ->assertJsonPath('errors.subscription_list_id.0', __('errors.campaigns.newsletter_none_sent'));

        $copyId = (int) $this->postJson("/api/subscription-lists/{$list->id}/newsletters/next")
            ->assertCreated()
            ->json('data.id');

        $copy = Campaign::query()->with('sends')->findOrFail($copyId);
        $this->assertSame(CampaignStatus::Draft, $copy->status);
        $this->assertSame($sent->id, $copy->duplicated_from_id);
        $this->assertSame($list->id, $copy->subscription_list_id);
        $this->assertSame($segment->id, $copy->segment_id);
        $this->assertNull($copy->newsletter_issue_number);
        $this->assertSame($family->id, (int) $copy->sends->first()?->template_family_id);
        $this->assertNull($copy->sends->first()?->template_version_id);
        $this->assertNull($copy->sends->first()?->scheduled_at);
    }

    private function submitIssue(int $listId, int $familyId, string $name): Campaign
    {
        $campaignId = (int) $this->postJson('/api/campaigns', [
            'type' => 'newsletter',
            'name' => $name,
            'subscription_list_id' => $listId,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/campaigns/{$campaignId}/sends/email", [
            'template_family_id' => $familyId,
            'scheduled_at' => now()->addMinutes(10)->toIso8601String(),
        ])->assertOk();

        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertOk();

        return Campaign::query()->findOrFail($campaignId);
    }

    /**
     * @return array{site: Site, list: SubscriptionList, account: CommunicationAccount, family: TemplateFamily, versionId: int, text: ConsentText}
     */
    private function world(string $body = 'Hello there'): array
    {
        $site = Site::factory()->create(['timezone' => 'Europe/Madrid']);
        $account = $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create([
            'labels' => ['en' => 'English', 'es' => 'Centro'],
        ]);
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Newsletter,
        ], [[
            'locale' => 'en',
            'subject' => 'Hello',
            'body_text' => $body,
            'legacy_html' => '<p>'.$body.'</p>',
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

    private function consentedContact(Site $site, SubscriptionList $list, string $email, ConsentText $text): Contact
    {
        $contact = Contact::factory()->create(['email' => $email]);
        $this->givePrimaryEmail($contact, $email);
        $contact->sites()->attach($site->id);
        ConsentWriter::grant($contact, Channel::Email, $list, ConsentSource::Form, $text->id);

        return $contact;
    }

    /**
     * @param  array{site: Site, list: SubscriptionList, account: CommunicationAccount, family: TemplateFamily, versionId: int, text: ConsentText}  $world
     * @return array{0: CampaignSend, 1: CommunicationAccount, 2: CampaignRecipient}
     */
    private function sendingNewsletter(array $world, Contact $contact, bool $bypass, int $capCount, int $issueNumber = 1): array
    {
        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Newsletter,
            'status' => CampaignStatus::Sending,
            'subscription_list_id' => $world['list']->id,
            'newsletter_issue_number' => $issueNumber,
            'audience_snapshot' => $this->snapshot($world['list']->id, $capCount, $bypass),
        ]);

        return $this->pendingRecipient($campaign, $world, $contact);
    }

    /**
     * @param  array{site: Site, list: SubscriptionList, account: CommunicationAccount, family: TemplateFamily, versionId: int, text: ConsentText}  $world
     * @return array{0: CampaignSend, 1: CommunicationAccount, 2: CampaignRecipient}
     */
    private function sendingCampaign(array $world, Contact $contact, int $capCount): array
    {
        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Campaign,
            'status' => CampaignStatus::Sending,
            'subscription_list_id' => $world['list']->id,
            'audience_snapshot' => $this->snapshot($world['list']->id, $capCount, true),
        ]);

        return $this->pendingRecipient($campaign, $world, $contact);
    }

    /**
     * @param  array{site: Site, list: SubscriptionList, account: CommunicationAccount, family: TemplateFamily, versionId: int, text: ConsentText}  $world
     * @return array{0: CampaignSend, 1: CommunicationAccount, 2: CampaignRecipient}
     */
    private function pendingRecipient(Campaign $campaign, array $world, Contact $contact): array
    {
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $world['family']->id,
            'template_version_id' => $world['versionId'],
            'status' => CampaignSendStatus::Sending,
            'subject_override' => 'Issue',
        ]);
        $recipient = CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $contact->id,
            'address' => $contact->email,
            'site_id' => $world['site']->id,
            'status' => CampaignRecipientStatus::Pending,
        ]);

        return [$send, $world['account'], $recipient];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $listId, int $capCount, bool $bypass): array
    {
        return [
            'list_id' => $listId,
            'segment_filter' => null,
            'site_ids' => [],
            'exclusions' => [
                'delinquent' => false,
                'playbook_kinds' => [],
            ],
            'frequency_cap' => [
                'count' => $capCount,
                'days' => 7,
                'newsletter_bypasses_cap' => $bypass,
            ],
        ];
    }

    private function emailFamily(): TemplateFamily
    {
        return TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Marketing,
        ], [[
            'locale' => 'en',
            'subject' => 'Hello',
            'body_text' => 'Hello there',
            'legacy_html' => '<p>Hello there</p>',
        ]]);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function employeeWithPermissions(array $permissions): Employee
    {
        $role = Role::query()->create([
            'key' => 'test-newsletter-'.uniqid(),
            'label' => 'Test newsletter',
            'scope_level' => RoleScope::Company,
            'is_system' => false,
        ]);

        foreach ($permissions as $permission) {
            RolePermission::query()->create([
                'role_id' => $role->id,
                'permission' => $permission->value,
            ]);
        }

        $employee = Employee::factory()->withoutRoleGrant()->create();
        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => null,
        ]);

        return $employee;
    }
}
