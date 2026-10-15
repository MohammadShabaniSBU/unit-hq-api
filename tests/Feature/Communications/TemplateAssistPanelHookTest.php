<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Ai\Agents\TemplateAssistantAgent;
use App\Ai\Tools\TemplateAssist\GetDocument;
use App\Enums\TemplateAssistTurnStatus;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Events\TemplateAssistWorking;
use App\Listeners\BroadcastTemplateAssistWorking;
use App\Models\Contact;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Templates\Assist\DocumentWorkspace;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Events\InvokingTool;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateAssistPanelHookTest extends TestCase
{
    use InteractsWithTemplateAssist;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
        Queue::fake();
    }

    #[Test]
    public function preview_blocks_do_not_write_the_variant(): void
    {
        $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $variant = $version->variants()->firstOrFail();
        $saved = $this->paragraphDocument('<p>Saved copy</p>', 'blk_00000001');
        $variant->blocks = $saved;
        $variant->legacy_html = null;
        $variant->save();

        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        $preview = $this->post(
            "/api/template-families/{$family->id}/variants/{$variant->id}/preview",
            [
                'contact_id' => $contact->id,
                'blocks' => $this->paragraphDocument('<p>Assistant copy</p>', 'blk_00000002'),
            ],
        );

        $preview->assertOk();
        $this->assertStringContainsString('Assistant copy', (string) $preview->getContent());
        $this->assertStringNotContainsString('Saved copy', (string) $preview->getContent());

        $fresh = $variant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(
            '<p>Saved copy</p>',
            $fresh->blocks['sections'][0]['columns'][0]['blocks'][0]['params']['html'],
        );
        $this->assertNull($fresh->legacy_html);
    }

    #[Test]
    public function working_broadcast_omits_tool_name(): void
    {
        $employee = $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $sessionId = $this->openAssistSession($family);
        $turnId = $this->postTurn($sessionId, $version->id);

        Event::fake([TemplateAssistWorking::class]);

        (new BroadcastTemplateAssistWorking)->handle(new InvokingTool(
            invocationId: 'inv-1',
            toolInvocationId: 'call-1',
            agent: new TemplateAssistantAgent($employee, $turnId),
            tool: new GetDocument($turnId),
            arguments: [],
        ));

        Event::assertDispatched(TemplateAssistWorking::class, function (TemplateAssistWorking $event) use ($sessionId, $turnId): bool {
            return $event->sessionId === $sessionId
                && $event->broadcastWith() === ['turn_id' => $turnId];
        });
    }

    #[Test]
    public function budget_refusal_stores_error_key_and_can_still_complete(): void
    {
        $turn = $this->runningTurn();
        $workspace = DocumentWorkspace::forTurn($turn->id);

        for ($index = 0; $index < 12; $index++) {
            $result = $workspace->insertBlocks('col_bbbbbbbb', $index, [[
                'type' => 'paragraph',
                'params' => ['html' => '<p>Line '.($index + 1).'</p>'],
            ]]);
            $this->assertTrue($result['ok'], $result['hint'] ?? '');
        }

        $exhausted = $workspace->insertBlocks('col_bbbbbbbb', 12, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>Too many</p>'],
        ]]);
        $this->assertFalse($exhausted['ok']);

        $turn->refresh();
        $this->assertSame(TemplateAssistTurnStatus::Running, $turn->status);
        $this->assertSame('assist.tool_budget_exhausted', $turn->error_key);

        $turn->status = TemplateAssistTurnStatus::Completed;
        $turn->save();

        $turn->refresh();
        $this->assertSame(TemplateAssistTurnStatus::Completed, $turn->status);
        $this->assertSame('assist.tool_budget_exhausted', $turn->error_key);
    }

    private function postTurn(int $sessionId, int $versionId): int
    {
        $response = $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-hook',
            'message' => 'Soften the tone',
            'template_version_id' => $versionId,
            'document' => $this->assistDocument(),
        ]);
        $response->assertAccepted();

        return (int) $response->json('data.turn_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraphDocument(string $html, string $blockId): array
    {
        return [
            'version' => 2,
            'settings' => ['background_color' => null],
            'sections' => [[
                'id' => 'sec_00000001',
                'layout' => '100',
                'params' => [
                    'background_color' => null,
                    'padding' => 'md',
                    'stack_on_mobile' => true,
                ],
                'columns' => [[
                    'id' => 'col_00000001',
                    'blocks' => [[
                        'id' => $blockId,
                        'type' => 'paragraph',
                        'params' => ['html' => $html],
                    ]],
                ]],
            ]],
        ];
    }

    private function runningTurn(): TemplateAssistTurn
    {
        $employee = Employee::factory()->manager()->create();
        $family = TemplateFamily::factory()->create([
            'purpose' => TemplatePurpose::General,
            'name' => 'Budget template',
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
                    'blocks' => [],
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
            'participant_id' => $employee->id,
            'title' => 'Assist',
            'site_scope_snapshot' => null,
        ]);
        $session = TemplateAssistSession::query()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'conversation_id' => $conversation->id,
            'employee_id' => $employee->id,
        ]);

        return TemplateAssistTurn::query()->create([
            'session_id' => $session->id,
            'call_id' => (string) Str::uuid(),
            'client_message_id' => (string) Str::uuid(),
            'template_version_id' => $version->id,
            'base_document' => $document,
            'base_hash' => hash('sha256', (string) json_encode($document)),
            'working_document' => $document,
            'ops' => [],
            'op_summary' => [],
            'status' => TemplateAssistTurnStatus::Running,
            'prompt_version' => (string) config('ai.template_assist.prompt_version'),
        ]);
    }
}
