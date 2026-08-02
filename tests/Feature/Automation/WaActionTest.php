<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\AutomationStatus;
use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Interaction;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\WhatsappTemplate;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use App\Support\Playbooks\PlaybookCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\AutomationHarness;
use Tests\TestCase;

class WaActionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private CommunicationAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Employee::factory()->create();

        $this->site = Site::factory()->create();
        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Whatsapp,
            'provider' => Provider::Sinch,
            'is_active' => true,
            'credentials' => [
                'project_id' => 'proj-test',
                'key_id' => 'key-id',
                'key_secret' => 'key-secret',
                'app_id' => 'app-test',
                'region' => 'us',
            ],
            'status' => CredentialStatus::Connected,
        ]);
        SiteSenderIdentity::query()->create([
            'site_id' => $this->site->id,
            'channel' => Channel::Whatsapp,
            'from_number' => '+15550009999',
        ]);

        $seq = 0;
        Http::fake([
            'us.conversation.api.sinch.com/*' => function () use (&$seq) {
                $seq++;

                return Http::response([
                    'message_id' => '01WA-ACT-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                ], 200);
            },
        ]);
    }

    public function test_three_skips_and_category_gates(): void
    {
        WhatsappTemplate::query()->create([
            'name' => 'payment_reminder',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hi {{1}}, please pay.',
            'variables' => [[
                'index' => 1,
                'label' => 'name',
                'token_default' => 'contact.first_name',
                'sample' => 'Ada',
            ]],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);

        WhatsappTemplate::query()->create([
            'name' => 'promo_blast',
            'language' => 'en',
            'category' => 'marketing',
            'body' => 'Deal for {{1}}',
            'variables' => [[
                'index' => 1,
                'label' => 'name',
                'token_default' => 'contact.first_name',
                'sample' => 'Ada',
            ]],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);

        // Save-time category gate: debt may not reference marketing.
        $debtMarketing = Playbook::query()->create([
            'kind' => PlaybookKind::DebtProcess,
            'name' => 'Debt marketing blocked',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $debtMarketing->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::SendWhatsappTemplate,
            'params' => [
                'whatsapp_template_name' => 'promo_blast',
                'variable_tokens' => ['1' => 'contact.first_name'],
            ],
            'sort' => 0,
        ]);
        try {
            PlaybookCompiler::compile($debtMarketing->fresh(['steps']));
            $this->fail('Expected ValidationException for marketing template on debt playbook');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('utility', implode(' ', $e->errors()['steps'] ?? []));
        }

        // Happy path via harness fixture (coverage law + send).
        $ok = Contact::factory()->create(['first_name' => 'Ada', 'last_name' => 'Before']);
        ContactChannel::query()->create([
            'contact_id' => $ok->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551110001',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        AutomationHarness::load('wa_template_debt_step')
            ->trigger('object_created', $ok)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('wa', AutomationRunStepStatus::Succeeded)
            ->assertStepStatus('after', AutomationRunStepStatus::Succeeded);

        $this->assertSame('WaContinued', $ok->fresh()->last_name);
        $this->assertTrue(
            Interaction::query()->where('contact_id', $ok->id)->where('channel', 'whatsapp')->exists(),
        );

        // Skip: no_channel
        $noWa = Contact::factory()->create(['first_name' => 'No', 'last_name' => 'Before']);
        $noChannelHarness = AutomationHarness::load('wa_template_debt_step')
            ->trigger('object_created', $noWa)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('wa', AutomationRunStepStatus::Succeeded)
            ->assertStepStatus('after', AutomationRunStepStatus::Succeeded);
        $noChannelStep = $noChannelHarness->run()->steps->first(
            fn ($step) => $step->node_id === $noChannelHarness->automation()->nodes->firstWhere('node_key', 'wa')?->id
        );
        $this->assertSame('no_channel', $noChannelStep?->output['skipped_reason'] ?? null);
        $this->assertSame('WaContinued', $noWa->fresh()->last_name);

        // Skip: template_not_approved (draft only)
        WhatsappTemplate::query()->where('name', 'payment_reminder')->update([
            'status' => WhatsappTemplate::STATUS_DRAFT,
        ]);
        $unapproved = Contact::factory()->create(['first_name' => 'Draft', 'last_name' => 'Before']);
        ContactChannel::query()->create([
            'contact_id' => $unapproved->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551110002',
            'is_primary' => true,
            'opted_in' => true,
        ]);
        $unapprovedHarness = AutomationHarness::load('wa_template_debt_step')
            ->trigger('object_created', $unapproved)
            ->assertRunStatus(AutomationRunStatus::Succeeded);
        $unapprovedStep = $unapprovedHarness->run()->steps->first(
            fn ($step) => $step->node_id === $unapprovedHarness->automation()->nodes->firstWhere('node_key', 'wa')?->id
        );
        $this->assertSame('template_not_approved', $unapprovedStep?->output['skipped_reason'] ?? null);

        // Restore approved for suppress path.
        WhatsappTemplate::query()->where('name', 'payment_reminder')->update([
            'status' => WhatsappTemplate::STATUS_APPROVED,
        ]);

        // Skip: suppressed
        $suppressed = Contact::factory()->create(['first_name' => 'Mute', 'last_name' => 'Before']);
        ContactChannel::query()->create([
            'contact_id' => $suppressed->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551110003',
            'is_primary' => true,
            'opted_in' => true,
        ]);
        SuppressionWriter::write(
            Channel::Whatsapp,
            '+15551110003',
            SuppressionScope::All,
            SuppressionReason::Manual,
        );
        $suppressedHarness = AutomationHarness::load('wa_template_debt_step')
            ->trigger('object_created', $suppressed)
            ->assertRunStatus(AutomationRunStatus::Succeeded);
        $suppressedStep = $suppressedHarness->run()->steps->first(
            fn ($step) => $step->node_id === $suppressedHarness->automation()->nodes->firstWhere('node_key', 'wa')?->id
        );
        $this->assertSame('suppressed', $suppressedStep?->output['skipped_reason'] ?? null);

        // Send-time category gate: lead may use marketing; debt playbook path rejects marketing category change.
        $lead = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Lead marketing ok',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $lead->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::SendWhatsappTemplate,
            'params' => [
                'whatsapp_template_name' => 'promo_blast',
                'variable_tokens' => ['1' => 'contact.first_name'],
            ],
            'sort' => 0,
        ]);
        $automation = PlaybookCompiler::compile($lead->fresh(['steps']));
        $this->assertSame(AutomationStatus::Inactive, $automation->status);
        AutomationWatchCache::flushAll();
    }
}
