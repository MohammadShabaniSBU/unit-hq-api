<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\ContactChannelType;
use App\Enums\ContractStatus;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Jobs\SendCampaignBatch;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Message;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitOccupancy;
use App\Settings\MarketingSettings;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use App\Support\Communications\Channel;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use App\Support\Marketing\CampaignBatchSender;
use App\Support\Marketing\CampaignLifecycle;
use App\Support\Marketing\CampaignMaterialiser;
use App\Support\Marketing\CampaignSendGate;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentWriter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class CampaignAnnouncementTest extends TestCase
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
    public function a_marketing_suppression_does_not_block_and_a_hard_bounce_does(): void
    {
        $site = $this->site();
        $account = $this->seedEmailAccount($site);
        $kept = $this->tenant($site, 'kept@example.com');
        $bounced = $this->tenant($site, 'bounced@example.com');
        SuppressionWriter::write(Channel::Email, 'kept@example.com', SuppressionScope::Marketing, SuppressionReason::Unsubscribed);
        SuppressionWriter::write(Channel::Email, 'bounced@example.com', SuppressionScope::All, SuppressionReason::HardBounce);

        [$send, $keptRow, $bouncedRow] = $this->pendingSend($site, [
            $kept->id => 'kept@example.com',
            $bounced->id => 'bounced@example.com',
        ]);

        app(CampaignBatchSender::class)->send($send->id, [$keptRow->id, $bouncedRow->id], $account->id, 600);

        $this->assertSame(CampaignRecipientStatus::Sent, $keptRow->refresh()->status);
        $this->assertNull($keptRow->skip_reason);
        $this->assertSame(CampaignRecipientStatus::Skipped, $bouncedRow->refresh()->status);
        $this->assertSame(CampaignSkipReason::Suppressed, $bouncedRow->skip_reason);

        $message = Message::query()->where('to_address', 'kept@example.com')->firstOrFail();
        $body = (string) $message->body_text;
        $this->assertStringContainsString('Madrid Centro', $body);
        $this->assertStringContainsString('+34910000000', $body);
        $this->assertStringNotContainsString('Unsubscribe', $body);
        $this->assertStringNotContainsString('data-marketing-footer', (string) $message->body_html);
    }

    #[Test]
    public function a_marketing_template_is_rejected_for_an_announcement(): void
    {
        $employee = $this->employeeWithPermission(Permission::AnnouncementSend);
        $site = $this->site();
        $this->seedEmailAccount($site);
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Marketing,
        ], [[
            'locale' => 'en',
            'subject' => 'Promo',
            'body_text' => 'Twenty percent off',
            'legacy_html' => '<p>Twenty percent off</p>',
        ]]);

        Sanctum::actingAs($employee);
        $campaignId = $this->createAnnouncement($site->id, $family->id);

        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertUnprocessable()
            ->assertJsonPath('errors.template_family_id.0', __('errors.campaigns.purpose_mismatch'));
    }

    #[Test]
    public function a_site_scoped_sender_cannot_target_another_site(): void
    {
        $allowed = Site::factory()->create();
        $blocked = Site::factory()->create();
        $sender = $this->employeeWithSiteScopedPermission(Permission::AnnouncementSend, $allowed);

        Sanctum::actingAs($sender);

        $this->postJson('/api/campaigns', [
            'type' => 'announcement',
            'name' => 'Other site',
            'site_ids' => [$blocked->id],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.site_ids.0', __('errors.campaigns.site_outside_grants'));

        $this->postJson('/api/campaigns', [
            'type' => 'announcement',
            'name' => 'Own site',
            'site_ids' => [$allowed->id],
        ])->assertCreated()
            ->assertJsonPath('data.site_ids.0', $allowed->id);
    }

    #[Test]
    public function ignore_send_window_sends_at_2230_and_otherwise_defers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:30:00', 'Europe/Madrid'));
        Setting::setGeneral(Setting::general()->with(sendWindowStart: '09:00', sendWindowEnd: '21:00'));
        Queue::fake();

        $site = $this->site();
        $account = $this->seedEmailAccount($site);
        $contact = $this->tenant($site, 'night@example.com');

        [, $deferred] = $this->pendingSend($site, [
            $contact->id => 'night@example.com',
        ], ignoreWindow: false);
        app(CampaignBatchSender::class)->send(
            (int) $deferred->campaign_send_id,
            [$deferred->id],
            $account->id,
            600,
        );

        $deferred->refresh();
        $this->assertSame(CampaignRecipientStatus::Pending, $deferred->status);
        $this->assertNull($deferred->skip_reason);
        Http::assertNothingSent();

        $expected = CarbonImmutable::parse('2026-10-11 09:00:00', 'Europe/Madrid')->utc();
        Queue::assertPushed(SendCampaignBatch::class, function (SendCampaignBatch $job) use ($expected, $deferred): bool {
            return $job->recipientIds === [$deferred->id]
                && $job->delay instanceof \DateTimeInterface
                && CarbonImmutable::instance($job->delay)->utc()->equalTo($expected);
        });

        [$send, $urgent] = $this->pendingSend($site, [
            $contact->id => 'night@example.com',
        ], ignoreWindow: true);
        app(CampaignBatchSender::class)->send($send->id, [$urgent->id], $account->id, 600);

        $this->assertSame(CampaignRecipientStatus::Sent, $urgent->refresh()->status);
        Http::assertSentCount(1);
    }

    #[Test]
    public function publishing_an_announcement_template_requires_announcement_send(): void
    {
        $marketer = $this->employeeWithPermission(Permission::TemplateManage);
        Sanctum::actingAs($marketer);

        $this->postJson('/api/template-families', [
            'channel' => 'email',
            'name' => 'Relabelled promo',
            'purpose' => 'announcement',
            'locale' => 'en',
            'subject' => 'Sale',
            'legacy_html' => '<p>Sale</p>',
        ])->assertForbidden();

        $general = $this->postJson('/api/template-families', [
            'channel' => 'email',
            'name' => 'Welcome',
            'purpose' => 'general',
            'locale' => 'en',
            'subject' => 'Welcome',
            'legacy_html' => '<p>Hi</p>',
        ])->assertCreated();

        $this->putJson('/api/template-families/'.$general->json('data.id'), [
            'purpose' => 'announcement',
        ])->assertForbidden();

        $family = $this->announcementDraft();
        $this->putJson("/api/template-families/{$family->id}", [
            'name' => 'Still an announcement',
        ])->assertForbidden();

        $draft = $family->versions()->where('status', TemplateVersionStatus::Draft)->firstOrFail();
        $this->postJson("/api/template-families/{$family->id}/versions/{$draft->id}/publish")
            ->assertForbidden();

        $sender = $this->employeeWithPermissions([
            Permission::TemplateManage,
            Permission::AnnouncementSend,
        ]);
        Sanctum::actingAs($sender);

        $this->postJson("/api/template-families/{$family->id}/versions/{$draft->id}/publish")
            ->assertOk();
        $this->assertNotNull($family->fresh()?->currentVersion);
    }

    #[Test]
    public function approval_is_off_unless_the_setting_is_on(): void
    {
        $employee = $this->employeeWithPermission(Permission::AnnouncementSend);
        $site = $this->site();
        $this->seedEmailAccount($site);
        $family = $this->announcementFamily();

        Sanctum::actingAs($employee);
        $open = $this->createAnnouncement($site->id, $family->id, ignoreWindow: true);
        $this->postJson("/api/campaigns/{$open}/submit")->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.approval_required', false);

        $scheduled = Activity::query()->where('description', 'campaign.scheduled')->firstOrFail();
        $this->assertTrue((bool) $scheduled->properties?->get('ignore_send_window'));

        Setting::setMarketing(MarketingSettings::default()->with(announcementApprovalRequired: true));
        $held = $this->createAnnouncement($site->id, $family->id);
        $this->postJson("/api/campaigns/{$held}/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.approval_required', true);
    }

    #[Test]
    public function an_announcement_send_does_not_consume_a_frequency_cap_slot(): void
    {
        $this->assertFalse(CampaignType::Announcement->allowsPromotion());
        $this->assertTrue(CampaignType::Campaign->allowsPromotion());

        $list = SubscriptionList::factory()->create();
        $text = ConsentText::factory()->create();
        $contact = Contact::factory()->create(['email' => 'ada@example.com']);
        ConsentWriter::grant($contact, Channel::Email, $list, ConsentSource::Form, $text->id);

        $announcement = Campaign::factory()->announcement()->create([
            'status' => CampaignStatus::Sent,
            'site_ids' => [1],
        ]);
        $announcementSend = CampaignSend::factory()->create([
            'campaign_id' => $announcement->id,
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $announcementSend->id,
            'contact_id' => $contact->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now()->subHour(),
            'address' => 'ada@example.com',
        ]);

        $marketing = Campaign::factory()->create([
            'status' => CampaignStatus::Sending,
            'subscription_list_id' => $list->id,
            'audience_snapshot' => $this->snapshot($list->id, 1, 7),
        ]);

        $gate = app(CampaignSendGate::class);
        $this->assertNull($gate->reason($marketing, Channel::Email, $contact->id));

        $marketingSend = CampaignSend::factory()->create([
            'campaign_id' => $marketing->id,
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $marketingSend->id,
            'contact_id' => $contact->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now()->subMinute(),
            'address' => 'ada@example.com',
        ]);

        $this->assertSame(
            CampaignSkipReason::FrequencyCapped,
            $gate->reason($marketing->fresh(), Channel::Email, $contact->id),
        );
    }

    #[Test]
    public function sms_unreachable_counts_only_all_scope_suppressions(): void
    {
        $site = $this->site();
        $stopped = $this->tenant($site, 'stop@example.com', '+34600000001');
        $optedOut = $this->tenant($site, 'optout@example.com', '+34600000002');
        SuppressionWriter::write(Channel::Sms, '+34600000001', SuppressionScope::All, SuppressionReason::StopKeyword);
        SuppressionWriter::write(Channel::Sms, '+34600000002', SuppressionScope::Marketing, SuppressionReason::Unsubscribed);

        $campaign = Campaign::factory()->announcement()->create([
            'site_ids' => [$site->id],
        ]);
        Sanctum::actingAs($this->employeeWithPermission(Permission::CampaignView));

        $this->postJson("/api/campaigns/{$campaign->id}/audience-preview")
            ->assertOk()
            ->assertJsonPath('data.sms_unreachable', 1);

        $this->assertNotSame($stopped->id, $optedOut->id);
    }

    #[Test]
    public function an_announcement_rejects_a_discount(): void
    {
        $site = Site::factory()->create();
        Sanctum::actingAs($this->employeeWithPermission(Permission::AnnouncementSend));

        $this->postJson('/api/campaigns', [
            'type' => 'announcement',
            'name' => 'No promo',
            'site_ids' => [$site->id],
            'discount_id' => 1,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.discount_id.0', __('errors.campaigns.discount_prohibited'));
    }

    #[Test]
    public function completion_logs_announcement_sent_on_each_site(): void
    {
        $first = $this->site('Madrid Centro');
        $second = Site::factory()->create([
            'timezone' => 'Europe/Madrid',
            'name' => 'Barcelona Nord',
            'contact_phone' => '+34930000000',
        ]);
        $family = $this->announcementFamily();
        $versionId = (int) $family->currentVersion?->id;
        $campaign = Campaign::factory()->announcement()->create([
            'status' => CampaignStatus::Sending,
            'site_ids' => [$first->id, $second->id],
            'ignore_send_window' => true,
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $family->id,
            'template_version_id' => $versionId,
            'status' => CampaignSendStatus::Completed,
        ]);
        $madrid = $this->tenant($first, 'madrid@example.com');
        $barcelona = $this->tenant($second, 'barcelona@example.com');
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $madrid->id,
            'address' => 'madrid@example.com',
            'site_id' => $first->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now(),
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $barcelona->id,
            'address' => 'barcelona@example.com',
            'site_id' => $second->id,
            'status' => CampaignRecipientStatus::Sent,
            'processed_at' => now(),
        ]);

        app(CampaignLifecycle::class)->complete($campaign->fresh() ?? $campaign);

        Sanctum::actingAs($this->employeeWithPermission(Permission::ActivityView));

        foreach ([$first, $second] as $site) {
            $rows = collect($this->getJson('/api/activities?subject_type=site&subject_id='.$site->id)
                ->assertOk()
                ->json('data'));
            $row = $rows->firstWhere('description', 'campaign.announcement_sent');
            $this->assertNotNull($row);
            $this->assertSame($campaign->id, $row['properties']['campaign_id']);
            $this->assertSame([$versionId], $row['properties']['template_version_ids']);
            $this->assertSame(1, $row['properties']['recipient_count']);
            $this->assertTrue($row['properties']['ignore_send_window']);
        }
    }

    #[Test]
    public function materialising_an_announcement_keeps_current_tenants_only(): void
    {
        Queue::fake();
        $site = $this->site();
        $active = $this->tenant($site, 'active@example.com');
        $notice = $this->tenant($site, 'notice@example.com');
        Contract::query()->where('contact_id', $notice->id)->update([
            'status' => ContractStatus::NoticeGiven->value,
        ]);
        $former = $this->tenant($site, 'former@example.com');
        UnitOccupancy::query()
            ->whereIn('contract_id', Contract::query()->where('contact_id', $former->id)->select('id'))
            ->update(['ended_on' => '2026-10-01']);
        Contact::factory()->create(['email' => 'stranger@example.com']);

        $family = $this->announcementFamily();
        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Announcement,
            'status' => CampaignStatus::Sending,
            'subscription_list_id' => null,
            'site_ids' => [$site->id],
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $family->id,
            'template_version_id' => $family->currentVersion?->id,
            'status' => CampaignSendStatus::Materialising,
        ]);

        app(CampaignMaterialiser::class)->materialise($send);

        $ids = CampaignRecipient::query()->where('campaign_send_id', $send->id)->pluck('contact_id')->sort()->values()->all();
        $expected = collect([$active->id, $notice->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
        $this->assertFalse(in_array($former->id, $ids, true));
    }

    private function site(string $name = 'Madrid Centro'): Site
    {
        return Site::factory()->create([
            'timezone' => 'Europe/Madrid',
            'name' => $name,
            'contact_phone' => '+34910000000',
        ]);
    }

    private function tenant(Site $site, string $email, ?string $phone = null): Contact
    {
        $contact = Contact::factory()->create(['email' => $email]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => ContractStatus::Active,
        ]);
        $unit = Unit::factory()->create(['site_id' => $site->id]);
        UnitOccupancy::factory()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'ended_on' => null,
        ]);

        if ($phone !== null) {
            ContactChannel::query()->create([
                'contact_id' => $contact->id,
                'type' => ContactChannelType::Sms,
                'value' => $phone,
                'is_primary' => true,
                'opted_in' => true,
            ]);
        }

        return $contact;
    }

    private function announcementFamily(): TemplateFamily
    {
        return TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Announcement,
            'name' => 'Gate notice',
        ], [[
            'locale' => 'en',
            'subject' => 'Gate',
            'body_text' => 'The gate is closed.',
            'legacy_html' => '<p>The gate is closed.</p>',
        ]]);
    }

    private function announcementDraft(): TemplateFamily
    {
        $family = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Announcement,
            'name' => 'Gate notice',
        ]);
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $version->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Gate',
            'body_text' => 'Closed',
            'legacy_html' => '<p>Closed</p>',
        ]);

        return $family;
    }

    private function createAnnouncement(int $siteId, int $familyId, bool $ignoreWindow = false): int
    {
        $campaignId = (int) $this->postJson('/api/campaigns', [
            'type' => 'announcement',
            'name' => 'Gate closed',
            'site_ids' => [$siteId],
            'ignore_send_window' => $ignoreWindow,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/campaigns/{$campaignId}/sends/email", [
            'template_family_id' => $familyId,
            'scheduled_at' => 'now',
        ])->assertOk();

        return $campaignId;
    }

    /**
     * @param  array<int, string>  $addresses  contact id => email
     * @return array{0: CampaignSend, 1: CampaignRecipient, 2?: CampaignRecipient}
     */
    private function pendingSend(Site $site, array $addresses, bool $ignoreWindow = false): array
    {
        $family = $this->announcementFamily();
        $campaign = Campaign::factory()->announcement()->create([
            'status' => CampaignStatus::Sending,
            'site_ids' => [$site->id],
            'ignore_send_window' => $ignoreWindow,
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'channel' => Channel::Email,
            'template_family_id' => $family->id,
            'template_version_id' => $family->currentVersion?->id,
            'status' => CampaignSendStatus::Sending,
        ]);

        $rows = [];
        foreach ($addresses as $contactId => $email) {
            $rows[] = CampaignRecipient::factory()->create([
                'campaign_send_id' => $send->id,
                'contact_id' => $contactId,
                'address' => $email,
                'site_id' => $site->id,
                'status' => CampaignRecipientStatus::Pending,
            ]);
        }

        return [$send, ...$rows];
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function employeeWithPermissions(array $permissions): Employee
    {
        $role = Role::query()->create([
            'key' => 'test-announcement-'.uniqid(),
            'label' => 'Test announcement',
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

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $listId, int $capCount, int $capDays): array
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
                'days' => $capDays,
            ],
        ];
    }
}
