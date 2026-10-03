<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Enums\AutomationStatus;
use App\Enums\TemplateChannel;
use App\Models\AttributeDefinition;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\TemplateFamily;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Filtering\AttributeFieldResolver;
use Database\Seeders\CelebrationAutomationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CelebrationAutomationsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_builder_templates_and_draft_automations_once(): void
    {
        $this->seed(CelebrationAutomationsSeeder::class);
        $this->seed(CelebrationAutomationsSeeder::class);

        $anniversary = TemplateFamily::query()
            ->where('name', 'Happy anniversary')
            ->where('channel', TemplateChannel::Email)
            ->first();
        $this->assertNotNull($anniversary);
        $this->assertSame(2, $anniversary->variants()->count());

        $variant = $anniversary->variants()->where('locale', 'en')->first();
        $this->assertNotNull($variant);
        $this->assertNull($variant->legacy_html);
        EmailBlockDocument::validate($variant->blocks);
        $this->assertSame('heading', $variant->blocks['blocks'][0]['type']);
        $this->assertSame('paragraph', $variant->blocks['blocks'][1]['type']);

        $birthday = AttributeDefinition::query()
            ->where('entity_type', AttributeEntityType::Contact)
            ->where('key', 'date_of_birth')
            ->first();
        $this->assertNotNull($birthday);
        $this->assertSame(AttributeType::Date, $birthday->type);
        $this->assertSame(1, AttributeDefinition::query()->where('key', 'date_of_birth')->count());

        $automation = Automation::query()->where('name', 'Happy anniversary')->first();
        $this->assertNotNull($automation);
        $this->assertSame(AutomationStatus::Draft, $automation->status);
        $this->assertSame(1, Automation::query()->where('name', 'Happy anniversary')->count());

        $wait = AutomationNode::query()
            ->where('automation_id', $automation->id)
            ->where('node_key', 'wait')
            ->first();
        $this->assertNotNull($wait);
        $this->assertSame(365, $wait->config['amount']);
        $this->assertSame('days', $wait->config['unit']);

        $email = AutomationNode::query()
            ->where('automation_id', $automation->id)
            ->where('node_key', 'email')
            ->first();
        $this->assertNotNull($email);
        $this->assertSame('template', $email->config['bodyType']);
        $this->assertSame((string) $anniversary->id, $email->config['templateId']);

        $birthdayAutomation = Automation::query()->where('name', 'Happy birthday')->first();
        $this->assertNotNull($birthdayAutomation);
        $trigger = AutomationNode::query()
            ->where('automation_id', $birthdayAutomation->id)
            ->where('node_key', 'trigger')
            ->first();
        $this->assertNotNull($trigger);
        $this->assertSame(
            AttributeFieldResolver::fieldKey((int) $birthday->id),
            $trigger->config['property'],
        );
    }
}
