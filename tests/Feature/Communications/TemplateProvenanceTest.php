<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\AutomationNodeKind;
use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Jobs\ResumeAutomationRun;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Automation\AutomationExecutor;
use App\Support\Communications\Channel;
use App\Support\Playbooks\PlaybookCompiler;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class TemplateProvenanceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    protected function setUp(): void
    {
        parent::setUp();
        $seq = 0;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => function () use (&$seq) {
                $seq++;

                return Http::response(['messageId' => 'brevo-prov-'.$seq], 201);
            },
            'api.twilio.com/*' => function () use (&$seq) {
                $seq++;

                return Http::response(['sid' => 'SM-prov-'.$seq], 201);
            },
            'us.sms.api.sinch.com/*' => Http::response(['id' => '01FC-sinch-test'], 201),
            'api.aircall.io/*' => Http::response(['ping' => 'pong'], 200),
        ]);
    }

    public function test_running_playbook_keeps_published_content_until_publish(): void
    {
        Queue::fake([ResumeAutomationRun::class]);

        $site = Site::factory()->create();
        $this->seedEmailAccount($site);

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Playbook notice',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'V1 subject',
            'legacy_html' => '<p>PUBLISHED-V1</p>',
        ]]);
        $published = $family->currentVersion()->firstOrFail();

        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Two notices',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);
        foreach ([0, 1] as $index => $offset) {
            PlaybookStep::query()->create([
                'playbook_id' => $playbook->id,
                'offset_days' => $offset,
                'action' => PlaybookStepAction::SendEmail,
                'params' => ['template_family_id' => $family->id],
                'sort' => $index,
            ]);
        }

        $automation = PlaybookCompiler::compile($playbook->fresh(['steps']));

        $draft = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 2,
            'status' => TemplateVersionStatus::Draft,
            'based_on_version_id' => $published->id,
        ]);
        $draft->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'V2 subject',
            'legacy_html' => '<p>PUBLISHED-V2</p>',
        ]);

        $contact = Contact::factory()->create([
            'locale' => 'fr',
            'email' => 'playbook-provenance@example.com',
        ]);
        $this->givePrimaryEmail($contact, 'playbook-provenance@example.com');

        $trigger = $automation->nodes()->where('type', AutomationNodeType::ObjectCreated)->firstOrFail();
        $run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'trigger_node_id' => $trigger->id,
            'status' => AutomationRunStatus::Pending,
            'subject_type' => 'contact',
            'subject_id' => $contact->id,
            'guard' => null,
            'depth' => 0,
        ]);

        (new AutomationExecutor)->execute($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $run->status, (string) $run->error);

        $first = $this->contactMessages($contact)->first();
        $this->assertNotNull($first);
        $this->assertStringContainsString('PUBLISHED-V1', (string) $first->body_text);
        $this->assertStringNotContainsString('PUBLISHED-V2', (string) $first->body_html);
        $this->assertSame($published->id, $first->detail['template']['version_id'] ?? null);
        $this->assertSame(1, $first->detail['template']['version_number'] ?? null);
        $this->assertSame($family->id, $first->detail['template']['family_id'] ?? null);
        $this->assertSame('en', $first->detail['template']['locale'] ?? null);
        $this->assertSame('fr', $first->detail['template']['preferred_locale'] ?? null);

        $draft->update([
            'status' => TemplateVersionStatus::Published,
            'published_at' => now(),
        ]);

        (new ResumeAutomationRun($run->id))->handle(app(AutomationExecutor::class));
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Succeeded, $run->status, (string) $run->error);

        $messages = $this->contactMessages($contact);
        $this->assertCount(2, $messages);
        $second = $messages->last();
        $this->assertStringContainsString('PUBLISHED-V2', (string) $second->body_text);
        $this->assertSame(2, $second->detail['template']['version_number'] ?? null);
        $this->assertSame($draft->id, $second->detail['template']['version_id'] ?? null);
        $this->assertSame('en', $second->detail['template']['locale'] ?? null);
        $this->assertSame('fr', $second->detail['template']['preferred_locale'] ?? null);
    }

    public function test_sms_send_records_template_provenance(): void
    {
        $site = Site::factory()->create();
        $this->seedSmsAccount($site);

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Sms,
            'name' => 'Sms notice',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'body_text' => 'SMS-V1',
        ]]);
        $version = $family->currentVersion()->firstOrFail();

        $contact = Contact::factory()->create(['locale' => 'en']);
        $this->givePrimaryPhone($contact, '+15557650001');

        $run = $this->executeSingleAction($contact, AutomationNodeType::SendSms, [
            'bodyType' => 'template',
            'template_family_id' => $family->id,
        ]);
        $this->assertSame(AutomationRunStatus::Succeeded, $run->status, (string) $run->error);

        $message = $this->contactMessages($contact)->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('SMS-V1', (string) $message->body_text);
        $this->assertSame($version->id, $message->detail['template']['version_id'] ?? null);
        $this->assertSame($version->version_number, $message->detail['template']['version_number'] ?? null);
        $this->assertSame('en', $message->detail['template']['locale'] ?? null);
    }

    public function test_inline_send_writes_no_template_provenance(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);

        $contact = Contact::factory()->create(['email' => 'inline-provenance@example.com']);
        $this->givePrimaryEmail($contact, 'inline-provenance@example.com');

        $run = $this->executeSingleAction($contact, AutomationNodeType::SendEmail, [
            'bodyType' => 'custom',
            'subject' => ['kind' => 'static', 'value' => 'Inline subject'],
            'body' => ['kind' => 'static', 'value' => 'Inline body'],
        ]);
        $this->assertSame(AutomationRunStatus::Succeeded, $run->status, (string) $run->error);

        $message = $this->contactMessages($contact)->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Inline body', (string) $message->body_text);
        $this->assertTrue($message->detail === null || ! array_key_exists('template', $message->detail));
    }

    public function test_unpublished_family_is_refused_at_activation_and_hidden_from_inbox(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $site = Site::factory()->create();
        $this->seedEmailAccount($site);

        $draftOnly = $this->draftOnlyFamily('Hidden draft');
        $published = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Visible published',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Live',
            'legacy_html' => '<p>live</p>',
        ]]);

        $automation = Automation::query()->create([
            'name' => 'Draft template',
            'status' => AutomationStatus::Draft,
            'version' => 1,
        ]);
        AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'email',
            'kind' => AutomationNodeKind::Action,
            'type' => AutomationNodeType::SendEmail,
            'label' => 'Email',
            'position_x' => 0,
            'position_y' => 0,
            'config' => [
                'bodyType' => 'template',
                'template_family_id' => $draftOnly->id,
            ],
        ]);

        $activate = $this->postJson("/api/automations/{$automation->id}/activate");
        $activate->assertStatus(422);
        $this->assertStringContainsString('no published version', (string) json_encode($activate->json('errors')));
        $this->assertSame(AutomationStatus::Draft, $automation->fresh()->status);

        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Unpublished',
            'is_active' => false,
            'enrolment_filters' => [],
            'automation_id' => Automation::query()->create([
                'name' => 'Already compiled',
                'status' => AutomationStatus::Inactive,
                'version' => 1,
            ])->id,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::SendEmail,
            'params' => ['template_family_id' => $draftOnly->id],
            'sort' => 0,
        ]);

        try {
            PlaybookCompiler::compile($playbook->fresh(['steps']));
            $this->fail('Compile should refuse an unpublished template family.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('no published version', (string) json_encode($e->errors()));
        }

        $playbookActivate = $this->postJson("/api/playbooks/{$playbook->id}/activate");
        $playbookActivate->assertStatus(422);
        $this->assertFalse($playbook->fresh()->is_active);

        $contact = Contact::factory()->create(['email' => 'picker@example.com']);
        $this->givePrimaryEmail($contact, 'picker@example.com');
        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Picker',
            'channel' => Channel::Email,
        ]);

        $context = $this->getJson('/api/inbox/threads/'.$thread->id.'/compose-context');
        $context->assertOk();
        $ids = collect($context->json('data.templates'))->pluck('id');
        $this->assertTrue($ids->contains($published->id));
        $this->assertFalse($ids->contains($draftOnly->id));

        $compose = $this->postJson('/api/inbox/compose', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'subject' => 'Hello',
            'template_family_id' => $draftOnly->id,
        ]);
        $compose->assertStatus(422);
        $compose->assertJsonPath('errors.template_family_id.0', __('errors.templates.not_published'));
    }

    public function test_send_paths_do_not_query_variants(): void
    {
        $files = [
            app_path('Support/Automation/NodeHandlers/SendEmailHandler.php'),
            app_path('Support/Automation/NodeHandlers/SendSmsHandler.php'),
            app_path('Http/Controllers/InboxController.php'),
        ];

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('TemplateResolver::', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/TemplateVariant::/', $source, $path);
            $this->assertStringNotContainsString('->variants', $source, $path);
            $this->assertStringNotContainsString("with('variants')", $source, $path);
            $this->assertStringNotContainsString('draft.variants', $source, $path);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function executeSingleAction(Contact $contact, AutomationNodeType $type, array $config): AutomationRun
    {
        $automation = Automation::query()->create([
            'name' => 'Single '.$type->value,
            'status' => AutomationStatus::Active,
            'version' => 1,
        ]);
        $trigger = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'trigger',
            'kind' => AutomationNodeKind::Trigger,
            'type' => AutomationNodeType::ObjectCreated,
            'label' => 'Contact',
            'position_x' => 0,
            'position_y' => 0,
            'config' => ['objectType' => 'contact'],
        ]);
        $action = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'send',
            'kind' => AutomationNodeKind::Action,
            'type' => $type,
            'label' => 'Send',
            'position_x' => 200,
            'position_y' => 0,
            'config' => $config,
        ]);
        AutomationEdge::query()->create([
            'automation_id' => $automation->id,
            'source_node_id' => $trigger->id,
            'target_node_id' => $action->id,
            'source_handle' => 'default',
            'condition' => ['type' => 'always'],
        ]);

        $run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'trigger_node_id' => $trigger->id,
            'status' => AutomationRunStatus::Pending,
            'subject_type' => 'contact',
            'subject_id' => $contact->id,
            'guard' => null,
            'depth' => 0,
        ]);

        (new AutomationExecutor)->execute($run->fresh());

        return $run->fresh() ?? $run;
    }

    /** @return Collection<int, Message> */
    private function contactMessages(Contact $contact)
    {
        return Message::query()
            ->whereHas('thread', fn ($query) => $query->where('contact_id', $contact->id))
            ->orderBy('id')
            ->get();
    }

    private function draftOnlyFamily(string $name): TemplateFamily
    {
        $family = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Email,
            'name' => $name,
            'purpose' => TemplatePurpose::General,
        ]);
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $version->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Draft',
            'legacy_html' => '<p>draft</p>',
        ]);

        return $family;
    }
}
