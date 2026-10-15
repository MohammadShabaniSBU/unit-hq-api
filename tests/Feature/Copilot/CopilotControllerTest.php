<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateFamily;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopilotControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function excludes_assistant_conversations(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $copilot = $this->conversation($employee, 'Real copilot');
        $assist = $this->conversation($employee, 'Rate notice · en');

        $family = TemplateFamily::factory()->create();
        TemplateAssistSession::query()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'conversation_id' => $assist->id,
            'employee_id' => $employee->id,
        ]);

        $this->getJson('/api/copilot/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $copilot->id])
            ->assertJsonMissing(['id' => $assist->id]);

        $this->getJson("/api/copilot/conversations/{$assist->id}")->assertNotFound();

        $this->postJson("/api/copilot/conversations/{$assist->id}/messages", [
            'message' => 'Find my contacts',
            'client_message_id' => (string) Str::uuid(),
        ])->assertNotFound();
    }

    private function conversation(Employee $employee, string $title): CopilotConversation
    {
        return CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => $title,
            'site_scope_snapshot' => null,
        ]);
    }
}
