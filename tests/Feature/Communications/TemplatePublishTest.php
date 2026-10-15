<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateAssistTurnStatus;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TemplatePublishTest extends TestCase
{
    use InteractsWithTemplateAssist;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function records_ai_assisted(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        [$family, $version] = $this->assistDraft();
        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Rate notice · en',
            'site_scope_snapshot' => null,
        ]);
        $session = TemplateAssistSession::query()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'conversation_id' => $conversation->id,
            'employee_id' => $employee->id,
        ]);

        $this->insertTurn($session, $version->id, TemplateAssistTurnStatus::Applied, 'applied-1');
        $this->insertTurn($session, $version->id, TemplateAssistTurnStatus::Applied, 'applied-2');
        $this->insertTurn($session, $version->id, TemplateAssistTurnStatus::Completed, 'completed-1');

        $this->postJson("/api/template-families/{$family->id}/versions/{$version->id}/publish")
            ->assertOk();

        $row = Activity::query()->where('description', 'template.version.published')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->properties['ai_assisted']);
        $this->assertSame(2, (int) $row->properties['ai_turns']);
    }

    private function insertTurn(
        TemplateAssistSession $session,
        int $versionId,
        TemplateAssistTurnStatus $status,
        string $clientMessageId,
    ): void {
        $document = $this->assistDocument();

        TemplateAssistTurn::query()->create([
            'session_id' => $session->id,
            'call_id' => (string) Str::uuid7(),
            'client_message_id' => $clientMessageId,
            'template_version_id' => $versionId,
            'base_document' => $document,
            'base_hash' => hash('sha256', $clientMessageId),
            'working_document' => $document,
            'ops' => [],
            'op_summary' => [],
            'prompt_version' => 'ta-1',
            'status' => $status,
        ]);
    }
}
