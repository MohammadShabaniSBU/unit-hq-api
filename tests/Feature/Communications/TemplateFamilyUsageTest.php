<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Enums\ContractDocumentStatus;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Support\Communications\TemplateFamilyUsage;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateFamilyUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_includes_sms_nodes_and_contract_documents(): void
    {
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Counted',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Hi',
            'legacy_html' => '<p>hi</p>',
        ]]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Usage',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::SendSms,
            'params' => ['template_family_id' => $family->id],
            'sort' => 0,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 1,
            'action' => PlaybookStepAction::SendEmail,
            'params' => ['email_template_id' => $family->id],
            'sort' => 1,
        ]);

        $automation = Automation::query()->create([
            'name' => 'Usage',
            'status' => AutomationStatus::Draft,
            'version' => 1,
        ]);
        AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'email',
            'kind' => 'action',
            'type' => AutomationNodeType::SendEmail,
            'label' => 'Email',
            'position_x' => 0,
            'position_y' => 0,
            'config' => [
                'bodyType' => 'template',
                'template_family_id' => $family->id,
            ],
        ]);
        AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'sms',
            'kind' => 'action',
            'type' => AutomationNodeType::SendSms,
            'label' => 'SMS',
            'position_x' => 200,
            'position_y' => 0,
            'config' => [
                'bodyType' => 'template',
                'templateId' => $family->id,
            ],
        ]);

        ContractDocument::query()->create([
            'contract_id' => Contract::factory()->create()->id,
            'template_family_id' => $family->id,
            'template_version_id' => $variant->template_version_id,
            'template_variant_id' => $variant->id,
            'rendered_at' => now(),
            'pdf_path' => 'contracts/usage.pdf',
            'sha256' => hash('sha256', 'usage'),
            'status' => ContractDocumentStatus::Draft,
        ]);

        $this->assertSame(5, TemplateFamilyUsage::count($family));
    }
}
