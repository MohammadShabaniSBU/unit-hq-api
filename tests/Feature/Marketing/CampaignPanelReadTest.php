<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Models\TemplateVersion;
use App\Settings\MarketingSettings;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentSource;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CampaignPanelReadTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function show_includes_counts_and_awaiting_me_excludes_the_approver(): void
    {
        $approver = $this->employeeWithPermissions([
            Permission::CampaignView,
            Permission::CampaignApprove,
        ]);
        $other = Employee::factory()->withoutRoleGrant()->create();
        $mine = Campaign::factory()->create([
            'status' => CampaignStatus::PendingApproval,
            'submitted_by' => $approver->id,
            'name' => 'Mine',
        ]);
        $theirs = Campaign::factory()->create([
            'status' => CampaignStatus::PendingApproval,
            'submitted_by' => $other->id,
            'name' => 'Theirs',
        ]);
        Campaign::factory()->create([
            'status' => CampaignStatus::Draft,
            'name' => 'Draft',
        ]);

        Sanctum::actingAs($approver);

        $this->getJson('/api/campaigns/approval-count')->assertOk()
            ->assertJsonPath('data.count', 1);

        $ids = collect($this->getJson('/api/campaigns?awaiting_me=1')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($theirs->id));
        $this->assertFalse($ids->contains($mine->id));

        $this->getJson("/api/campaigns/{$theirs->id}")->assertOk()
            ->assertJsonPath('data.counts.pending', 0)
            ->assertJsonPath('data.open_rate', null);
    }

    #[Test]
    public function report_totals_reconcile_and_a_later_bounce_updates_them(): void
    {
        $viewer = $this->employeeWithPermission(Permission::CampaignView);
        $contact = Contact::factory()->create(['first_name' => 'Diana', 'last_name' => 'Lopez']);
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sent]);
        $send = CampaignSend::factory()->create(['campaign_id' => $campaign->id]);
        $message = $this->message($contact, [
            ['status' => 'delivered'],
            ['status' => 'opened'],
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $contact->id,
            'status' => CampaignRecipientStatus::Sent,
            'message_id' => $message->id,
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'status' => CampaignRecipientStatus::Skipped,
            'skip_reason' => CampaignSkipReason::NoConsent,
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'status' => CampaignRecipientStatus::Failed,
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'status' => CampaignRecipientStatus::Pending,
        ]);

        $list = SubscriptionList::factory()->create();
        ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'subscription_list_id' => $list->id,
            'action' => ConsentAction::Withdrawn,
            'source' => ConsentSource::OneClick,
            'evidence' => ['message_id' => $message->id],
        ]);

        Sanctum::actingAs($viewer);

        $report = $this->getJson("/api/campaigns/{$campaign->id}/report")->assertOk()->json('data');
        $total = $report['total'];
        $this->assertSame(4, $total['targeted']);
        $this->assertSame(
            $total['targeted'],
            $total['sent'] + $total['skipped'] + $total['failed'] + $total['pending'] + $total['claimed'],
        );
        $this->assertSame(1, $total['delivered']);
        $this->assertSame(1, $total['opened']);
        $this->assertSame(1, $total['unsubscribed']);
        $this->assertSame(1, $total['by_skip_reason']['no_consent']);
        $this->assertTrue($report['opens_approximate']);

        $message->update([
            'delivery_events' => [
                ['status' => 'delivered'],
                ['status' => 'opened'],
                ['status' => 'bounced'],
            ],
        ]);

        $this->getJson("/api/campaigns/{$campaign->id}/report")->assertOk()
            ->assertJsonPath('data.total.bounced', 1);
    }

    #[Test]
    public function report_buckets_opens_and_clicks_by_hour_since_the_first_send(): void
    {
        $viewer = $this->employeeWithPermission(Permission::CampaignView);
        $anchor = Carbon::parse('2026-10-10 12:00:00');
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sent]);
        $send = CampaignSend::factory()->create(['campaign_id' => $campaign->id]);
        $early = Contact::factory()->create();
        $later = Contact::factory()->create();
        $earlyMessage = $this->message($early, [
            ['status' => 'opened', 'occurred_at' => $anchor->copy()->addMinutes(10)->toIso8601String()],
            ['status' => 'clicked', 'occurred_at' => $anchor->copy()->addMinutes(20)->toIso8601String()],
            ['status' => 'opened', 'occurred_at' => $anchor->copy()->addHours(80)->toIso8601String()],
        ]);
        $laterMessage = $this->message($later, [
            ['status' => 'opened', 'occurred_at' => $anchor->copy()->addHours(3)->addMinutes(5)->toIso8601String()],
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $early->id,
            'status' => CampaignRecipientStatus::Sent,
            'message_id' => $earlyMessage->id,
            'processed_at' => $anchor->copy()->addHour(),
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $later->id,
            'status' => CampaignRecipientStatus::Sent,
            'message_id' => $laterMessage->id,
            'processed_at' => $anchor,
        ]);

        Sanctum::actingAs($viewer);

        $hours = collect($this->getJson("/api/campaigns/{$campaign->id}/report")->assertOk()
            ->json('data.engagement.hours'))->keyBy('hour');

        $this->assertSame(1, $hours[0]['opened']);
        $this->assertSame(1, $hours[0]['clicked']);
        $this->assertSame(1, $hours[3]['opened']);
        $this->assertSame(0, $hours[3]['clicked']);
        $this->assertSame(0, $hours[20]['opened']);
        $this->assertArrayNotHasKey(21, $hours->all());
        $this->assertArrayNotHasKey(80, $hours->all());
    }

    #[Test]
    public function recipients_export_is_a_csv_of_the_list(): void
    {
        $viewer = $this->employeeWithPermission(Permission::CampaignView);
        $diana = Contact::factory()->create([
            'first_name' => 'Diana',
            'last_name' => 'Lopez',
            'email' => 'diana@example.com',
        ]);
        $campaign = Campaign::factory()->create();
        $send = CampaignSend::factory()->create(['campaign_id' => $campaign->id]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $diana->id,
            'status' => CampaignRecipientStatus::Skipped,
            'skip_reason' => CampaignSkipReason::NoConsent,
        ]);

        Sanctum::actingAs($viewer);

        $csv = $this->get("/api/campaigns/{$campaign->id}/recipients/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('name,email,status,skip_reason,message_id', $csv);
        $this->assertStringContainsString('Diana Lopez', $csv);
        $this->assertStringContainsString('diana@example.com', $csv);
        $this->assertStringContainsString('skipped', $csv);
        $this->assertStringContainsString('no_consent', $csv);
    }

    #[Test]
    public function a_sending_report_stays_cached_for_sixty_seconds(): void
    {
        Cache::flush();
        $viewer = $this->employeeWithPermission(Permission::CampaignView);
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);
        $send = CampaignSend::factory()->create(['campaign_id' => $campaign->id]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'status' => CampaignRecipientStatus::Pending,
        ]);

        Sanctum::actingAs($viewer);
        $this->getJson("/api/campaigns/{$campaign->id}/report")->assertOk()
            ->assertJsonPath('data.total.targeted', 1);

        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'status' => CampaignRecipientStatus::Pending,
        ]);

        $this->getJson("/api/campaigns/{$campaign->id}/report")->assertOk()
            ->assertJsonPath('data.total.targeted', 1);

        $this->travel(61)->seconds();

        $this->getJson("/api/campaigns/{$campaign->id}/report")->assertOk()
            ->assertJsonPath('data.total.targeted', 2);
    }

    #[Test]
    public function recipients_filter_by_status_skip_reason_and_name(): void
    {
        $viewer = $this->employeeWithPermission(Permission::CampaignView);
        $diana = Contact::factory()->create(['first_name' => 'Diana', 'last_name' => 'Lopez', 'email' => 'diana@example.com']);
        $omar = Contact::factory()->create(['first_name' => 'Omar', 'last_name' => 'Nabil']);
        $campaign = Campaign::factory()->create();
        $send = CampaignSend::factory()->create(['campaign_id' => $campaign->id]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $diana->id,
            'status' => CampaignRecipientStatus::Skipped,
            'skip_reason' => CampaignSkipReason::NoConsent,
        ]);
        CampaignRecipient::factory()->create([
            'campaign_send_id' => $send->id,
            'contact_id' => $omar->id,
            'status' => CampaignRecipientStatus::Sent,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/campaigns/{$campaign->id}/recipients?q=Diana")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact_id', $diana->id)
            ->assertJsonPath('data.0.message_id', null);

        $this->getJson("/api/campaigns/{$campaign->id}/recipients?status=skipped&skip_reason=no_consent")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact_name', 'Diana Lopez');
    }

    #[Test]
    public function preview_uses_the_pinned_version_and_hides_other_sites(): void
    {
        $allowed = Site::factory()->create();
        $blocked = Site::factory()->create();
        $viewer = $this->employeeWithSiteScopedPermission(Permission::CampaignView, $allowed);
        $contact = Contact::factory()->create();
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => TemplatePurpose::Marketing,
            'name' => 'Spring promo email',
        ], [[
            'locale' => 'en',
            'subject' => 'Pinned subject',
            'body_text' => 'Pinned body',
            'legacy_html' => '<p>Pinned body</p>',
        ]]);
        $pinned = $family->currentVersion;
        $this->assertInstanceOf(TemplateVersion::class, $pinned);

        $campaign = Campaign::factory()->create([
            'site_ids' => [$allowed->id],
            'subscription_list_id' => SubscriptionList::factory(),
        ]);
        $send = CampaignSend::factory()->create([
            'campaign_id' => $campaign->id,
            'template_family_id' => $family->id,
            'template_version_id' => $pinned->id,
            'channel' => Channel::Email,
        ]);

        $next = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => ((int) $pinned->version_number) + 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $next->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Later subject',
            'body_text' => 'Later body',
            'legacy_html' => '<p>Later body</p>',
        ]);
        $next->update([
            'status' => TemplateVersionStatus::Published,
            'published_at' => now(),
        ]);

        $outside = Campaign::factory()->create(['site_ids' => [$blocked->id]]);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/campaigns/{$campaign->id}/sends/email/preview?contact_id={$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.version_number', $pinned->version_number)
            ->assertJsonPath('data.subject', 'Pinned subject');

        $this->assertStringContainsString('Pinned body', (string) $this->getJson("/api/campaigns/{$campaign->id}/sends/email/preview?contact_id={$contact->id}")->json('data.html'));
        $this->getJson("/api/campaigns/{$outside->id}/sends/email/preview")->assertNotFound();
        $this->assertSame($pinned->id, (int) $send->fresh()->template_version_id);
    }

    #[Test]
    public function audience_preview_names_the_approval_reason(): void
    {
        Setting::setMarketing(MarketingSettings::default()->with(approvalThreshold: 0));
        $marketer = $this->employeeWithPermissions([
            Permission::CampaignView,
            Permission::CampaignManage,
        ]);
        $list = SubscriptionList::factory()->create();
        $campaign = Campaign::factory()->create([
            'subscription_list_id' => $list->id,
            'created_by' => $marketer->id,
        ]);

        Sanctum::actingAs($marketer);

        $reasons = $this->postJson("/api/campaigns/{$campaign->id}/audience-preview")->assertOk()
            ->json('data.approval_reasons');

        $this->assertContains('lacks_approve', $reasons);
        $this->assertTrue((bool) $this->postJson("/api/campaigns/{$campaign->id}/audience-preview")->json('data.approval_required'));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function message(Contact $contact, array $events): Message
    {
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email->value,
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        return Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Delivered,
            'body_text' => 'Hello',
            'from_address' => 'news@example.com',
            'to_address' => (string) $contact->email,
            'source' => MessageSource::Campaign,
            'delivery_events' => $events,
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function employeeWithPermissions(array $permissions): Employee
    {
        $role = Role::query()->create([
            'key' => 'test-campaign-read-'.uniqid(),
            'label' => 'Test campaign read',
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
