<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\Contact;
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
use App\Settings\MarketingSettings;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use App\Support\Communications\MessageSource;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class CampaignLifecycleTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    #[Test]
    public function illegal_transition_returns_the_current_status(): void
    {
        $employee = $this->employeeWithPermission(Permission::CampaignManage);
        $campaign = $this->draftCampaign($employee);

        Sanctum::actingAs($employee);

        $response = $this->postJson("/api/campaigns/{$campaign->id}/pause");

        $response->assertUnprocessable();
        $this->assertStringContainsString('draft', (string) $response->json('errors.status.0'));
    }

    #[Test]
    public function submit_pins_the_version_and_a_later_publish_does_not_move_it(): void
    {
        $marketer = $this->employeeWithPermission(Permission::CampaignManage);
        $approver = $this->employeeWithPermission(Permission::CampaignApprove);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = $this->emailFamily();
        $pinned = (int) $family->currentVersion?->id;

        Sanctum::actingAs($marketer);
        $campaignId = $this->createWithSend($list->id, $family->id);

        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertOk();

        $this->publishNext($family);
        $send = CampaignSend::query()->where('campaign_id', $campaignId)->firstOrFail();
        $this->assertSame($pinned, (int) $send->template_version_id);

        Sanctum::actingAs($approver);
        $this->postJson("/api/campaigns/{$campaignId}/approve")->assertOk();

        $approvedPin = (int) CampaignSend::query()->where('campaign_id', $campaignId)->value('template_version_id');
        $this->assertNotSame($pinned, $approvedPin);

        $this->publishNext($family->fresh());
        $this->assertSame(
            $approvedPin,
            (int) CampaignSend::query()->where('campaign_id', $campaignId)->value('template_version_id'),
        );
        $this->assertNotSame($approvedPin, (int) $family->fresh()->currentVersion?->id);
    }

    #[Test]
    public function submitter_cannot_approve_and_reject_returns_to_draft(): void
    {
        Setting::setMarketing(MarketingSettings::default()->with(approvalThreshold: -1));
        $submitter = $this->employeeWithPermissions([
            Permission::CampaignManage,
            Permission::CampaignApprove,
        ]);
        $other = $this->employeeWithPermission(Permission::CampaignApprove);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = $this->emailFamily();

        Sanctum::actingAs($submitter);
        $campaignId = $this->createWithSend($list->id, $family->id);
        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $this->postJson("/api/campaigns/{$campaignId}/approve")->assertUnprocessable()
            ->assertJsonPath('errors.approved_by.0', __('errors.campaigns.self_approval'));

        Sanctum::actingAs($other);
        $this->postJson("/api/campaigns/{$campaignId}/reject", [
            'reason' => 'Wrong audience',
        ])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.rejected_reason', 'Wrong audience');
    }

    #[Test]
    public function sole_approver_may_approve_their_own_campaign(): void
    {
        Setting::setMarketing(MarketingSettings::default()->with(approvalThreshold: -1));
        $submitter = $this->employeeWithPermissions([
            Permission::CampaignManage,
            Permission::CampaignApprove,
        ]);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = $this->emailFamily();

        Sanctum::actingAs($submitter);
        $campaignId = $this->createWithSend($list->id, $family->id);
        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertOk();
        $this->postJson("/api/campaigns/{$campaignId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.approved_by', $submitter->id);
    }

    #[Test]
    public function site_scoped_viewer_sees_only_campaigns_inside_their_grants(): void
    {
        $allowed = Site::factory()->create();
        $blocked = Site::factory()->create();
        $viewer = $this->employeeWithSiteScopedPermission(Permission::CampaignView, $allowed);
        $inside = Campaign::factory()->create(['site_ids' => [$allowed->id]]);
        $outside = Campaign::factory()->create(['site_ids' => [$blocked->id]]);
        $companyWide = Campaign::factory()->create(['site_ids' => []]);

        Sanctum::actingAs($viewer);

        $ids = collect($this->getJson('/api/campaigns')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($inside->id));
        $this->assertTrue($ids->contains($companyWide->id));
        $this->assertFalse($ids->contains($outside->id));
    }

    #[Test]
    public function site_scoped_marketer_cannot_include_a_site_outside_their_grants(): void
    {
        $allowed = Site::factory()->create();
        $blocked = Site::factory()->create();
        $marketer = $this->employeeWithSiteScopedPermission(Permission::CampaignManage, $allowed);
        $list = SubscriptionList::factory()->create();

        Sanctum::actingAs($marketer);

        $this->postJson('/api/campaigns', [
            'type' => 'campaign',
            'name' => 'Madrid promo',
            'subscription_list_id' => $list->id,
            'site_ids' => [$blocked->id],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.site_ids.0', __('errors.campaigns.site_outside_grants'));
    }

    #[Test]
    public function test_send_does_not_write_recipients_and_marks_the_message(): void
    {
        $this->fakeCommunicationProviders();
        $employee = $this->employeeWithPermission(Permission::CampaignManage);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = $this->emailFamily();
        $contact = Contact::factory()->create();

        Sanctum::actingAs($employee);
        $campaignId = $this->createWithSend($list->id, $family->id);

        $this->postJson("/api/campaigns/{$campaignId}/test-send", [
            'channel' => 'email',
            'to' => [$employee->email],
            'as_contact_id' => $contact->id,
        ])->assertOk();

        $this->assertSame(0, CampaignRecipient::query()->count());
        $message = Message::query()->first();
        $this->assertNotNull($message);
        $this->assertSame(MessageSource::Campaign, $message->source);
        $this->assertTrue((bool) ($message->detail['test'] ?? false));
        Http::assertSentCount(1);
    }

    #[Test]
    public function purpose_and_list_channel_mismatches_are_rejected(): void
    {
        $employee = $this->employeeWithPermission(Permission::CampaignManage);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $this->seedSmsAccount($site);
        $list = SubscriptionList::factory()->create(['channels' => ['email']]);
        $debt = $this->emailFamily(TemplatePurpose::Debt);

        Sanctum::actingAs($employee);
        $campaignId = $this->createWithSend($list->id, $debt->id);
        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertUnprocessable()
            ->assertJsonPath('errors.template_family_id.0', __('errors.campaigns.purpose_mismatch'));

        $this->deleteJson("/api/campaigns/{$campaignId}/sends/email")->assertOk();

        $sms = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Sms,
            'purpose' => TemplatePurpose::Marketing,
        ], [[
            'locale' => 'en',
            'body_text' => 'Short',
        ]]);

        $this->putJson("/api/campaigns/{$campaignId}/sends/sms", [
            'template_family_id' => $sms->id,
            'scheduled_at' => 'now',
        ])->assertOk();

        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertUnprocessable()
            ->assertJsonPath('errors.channel.0', __('errors.campaigns.list_channel'));
    }

    #[Test]
    public function a_long_sms_warns_and_still_submits(): void
    {
        $employee = $this->employeeWithPermission(Permission::CampaignManage);
        $site = Site::factory()->create();
        $this->seedSmsAccount($site);
        $list = SubscriptionList::factory()->create(['channels' => ['sms']]);
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Sms,
            'purpose' => TemplatePurpose::Marketing,
        ], [[
            'locale' => 'en',
            'body_text' => str_repeat('A', 400),
        ]]);

        Sanctum::actingAs($employee);
        $campaignId = $this->createWithSend($list->id, $family->id, 'sms');

        $response = $this->postJson("/api/campaigns/{$campaignId}/submit");
        $response->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertStringContainsString('segments', (string) $response->json('warnings.0'));
    }

    #[Test]
    public function duplicate_drops_the_pinned_version(): void
    {
        $employee = $this->employeeWithPermission(Permission::CampaignManage);
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $list = SubscriptionList::factory()->create();
        $family = $this->emailFamily();

        Sanctum::actingAs($employee);
        $campaignId = $this->createWithSend($list->id, $family->id);
        $this->postJson("/api/campaigns/{$campaignId}/submit")->assertOk();

        $copyId = (int) $this->postJson("/api/campaigns/{$campaignId}/duplicate")
            ->assertCreated()
            ->json('data.id');

        $copy = Campaign::query()->with('sends')->findOrFail($copyId);
        $this->assertSame('draft', $copy->status->value);
        $this->assertSame($campaignId, $copy->duplicated_from_id);
        $this->assertNull($copy->sends->first()?->template_version_id);
        $this->assertNull($copy->sends->first()?->scheduled_at);
    }

    private function draftCampaign(Employee $employee): Campaign
    {
        $list = SubscriptionList::factory()->create();

        return Campaign::factory()->create([
            'subscription_list_id' => $list->id,
            'created_by' => $employee->id,
        ]);
    }

    private function emailFamily(TemplatePurpose $purpose = TemplatePurpose::Marketing): TemplateFamily
    {
        return TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'purpose' => $purpose,
        ], [[
            'locale' => 'en',
            'subject' => 'Hello',
            'body_text' => 'Hello there',
            'legacy_html' => '<p>Hello there</p>',
        ]]);
    }

    private function createWithSend(int $listId, int $familyId, string $channel = 'email'): int
    {
        $campaignId = (int) $this->postJson('/api/campaigns', [
            'type' => 'campaign',
            'name' => 'Spring promo',
            'subscription_list_id' => $listId,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/campaigns/{$campaignId}/sends/{$channel}", [
            'template_family_id' => $familyId,
            'scheduled_at' => now()->addMinutes(10)->toIso8601String(),
            'subject_override' => $channel === 'email' ? 'Spring' : null,
        ])->assertOk();

        return $campaignId;
    }

    private function publishNext(TemplateFamily $family): TemplateVersion
    {
        $next = ((int) $family->versions()->max('version_number')) + 1;
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => $next,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $version->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Version '.$next,
            'body_text' => 'Version '.$next,
            'legacy_html' => '<p>Version '.$next.'</p>',
        ]);
        $version->update([
            'status' => TemplateVersionStatus::Published,
            'published_at' => now(),
        ]);

        return $version->refresh();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function employeeWithPermissions(array $permissions): Employee
    {
        $role = Role::query()->create([
            'key' => 'test-campaign-'.uniqid(),
            'label' => 'Test campaign',
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
