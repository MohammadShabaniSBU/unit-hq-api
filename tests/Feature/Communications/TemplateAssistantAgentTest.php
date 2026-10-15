<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Ai\Agents\TemplateAssistantAgent;
use App\Ai\Tools\CreateContact;
use App\Ai\Tools\FetchObjects;
use App\Ai\Tools\ResolveCalendar;
use App\Ai\Tools\TemplateAssist\AddSection;
use App\Ai\Tools\TemplateAssist\GetDocument;
use App\Ai\Tools\TemplateAssist\InsertBlocks;
use App\Ai\Tools\TemplateAssist\ListTokens;
use App\Ai\Tools\TemplateAssist\MoveBlock;
use App\Ai\Tools\TemplateAssist\MoveSection;
use App\Ai\Tools\TemplateAssist\RemoveNode;
use App\Ai\Tools\TemplateAssist\SetSectionLayout;
use App\Ai\Tools\TemplateAssist\SetSubject;
use App\Ai\Tools\TemplateAssist\UpdateBlock;
use App\Ai\Tools\TemplateAssist\UpdateSection;
use App\Enums\TemplateAssistTurnStatus;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateAssistantAgentTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private TemplateAssistTurn $turn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $family = TemplateFamily::factory()->create([
            'purpose' => TemplatePurpose::General,
            'name' => 'Rate notice',
        ]);
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $document = [
            'version' => 2,
            'settings' => ['background_color' => null],
            'sections' => [[
                'id' => 'sec_aaaaaaaa',
                'layout' => '100',
                'params' => [
                    'background_color' => null,
                    'padding' => 'md',
                    'stack_on_mobile' => true,
                ],
                'columns' => [[
                    'id' => 'col_bbbbbbbb',
                    'blocks' => [[
                        'id' => 'blk_cccccccc',
                        'type' => 'paragraph',
                        'params' => ['html' => '<p>Hello</p>'],
                    ]],
                ]],
            ]],
        ];
        $version->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Hello',
            'blocks' => $document,
        ]);
        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid(),
            'participant_type' => 'employee',
            'participant_id' => $this->employee->id,
            'title' => 'Assist',
            'site_scope_snapshot' => null,
        ]);
        $session = TemplateAssistSession::query()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'conversation_id' => $conversation->id,
            'employee_id' => $this->employee->id,
        ]);
        $this->turn = TemplateAssistTurn::query()->create([
            'session_id' => $session->id,
            'call_id' => (string) Str::uuid(),
            'client_message_id' => (string) Str::uuid(),
            'template_version_id' => $version->id,
            'selection' => ['kind' => 'block', 'id' => 'blk_cccccccc'],
            'base_document' => $document,
            'base_hash' => hash('sha256', (string) json_encode($document)),
            'working_document' => $document,
            'ops' => [],
            'op_summary' => [],
            'status' => TemplateAssistTurnStatus::Running,
            'prompt_version' => (string) config('ai.template_assist.prompt_version'),
        ]);
    }

    #[Test]
    public function tool_surface_is_closed(): void
    {
        $tools = iterator_to_array((new TemplateAssistantAgent($this->employee, $this->turn->id))->tools(), false);
        $classes = array_map(static fn (object $tool): string => $tool::class, $tools);

        $this->assertSame([
            GetDocument::class,
            ListTokens::class,
            AddSection::class,
            SetSectionLayout::class,
            UpdateSection::class,
            InsertBlocks::class,
            UpdateBlock::class,
            MoveBlock::class,
            MoveSection::class,
            RemoveNode::class,
            SetSubject::class,
        ], $classes);

        foreach ($tools as $tool) {
            $this->assertStringStartsWith('App\\Ai\\Tools\\TemplateAssist\\', $tool::class);
            $this->assertNotInstanceOf(FetchObjects::class, $tool);
            $this->assertNotInstanceOf(CreateContact::class, $tool);
            $this->assertNotInstanceOf(ResolveCalendar::class, $tool);
        }

        $document = json_decode((new GetDocument($this->turn->id))->handle(new Request([])), true);
        $this->assertTrue($document['ok']);
        $this->assertSame('blk_cccccccc', $document['document']['sections'][0]['columns'][0]['blocks'][0]['id']);

        $tokens = json_decode((new ListTokens($this->turn->id))->handle(new Request([])), true);
        $this->assertTrue($tokens['ok']);
        $this->assertContains('contact.first_name', array_column($tokens['tokens'], 'path'));

        $unknown = json_decode((new UpdateBlock($this->turn->id))->handle(new Request([
            'block_id' => 'blk_cccccccc',
            'params' => ['html' => '<p>Changed</p>'],
            'extra' => true,
        ])), true);
        $this->assertFalse($unknown['ok']);
        $this->assertSame('assist.unknown_field', $unknown['error_key']);
        $this->assertSame('<p>Hello</p>', $this->turn->refresh()->working_document['sections'][0]['columns'][0]['blocks'][0]['params']['html']);
    }

    #[Test]
    public function prompt_includes_context_block(): void
    {
        $instructions = (new TemplateAssistantAgent($this->employee, $this->turn->id))->instructions();
        $prompt = (string) file_get_contents(resource_path('prompts/template-assistant.md'));

        $this->assertStringContainsString(trim($prompt), $instructions);
        $this->assertStringContainsString('purpose: general', $instructions);
        $this->assertStringContainsString('contact.first_name', $instructions);
        $this->assertStringContainsString('sections[0].columns[0].blocks[0]', $instructions);
    }
}
