<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateAssistTurnStatus;
use App\Enums\TemplateVersionStatus;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Auth\Permission;
use App\Support\Communications\Exceptions\TemplateAssistTurnFrozen;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateAssistSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private TemplateFamily $family;

    private TemplateVersion $version;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $this->family = TemplateFamily::factory()->create();
        $this->version = TemplateVersion::query()->create([
            'template_family_id' => $this->family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
    }

    #[Test]
    public function turn_content_frozen_after_running(): void
    {
        $turn = $this->makeTurn($this->makeSession(), 'freeze-1');

        $turn->ops = [['op' => 'add']];
        $turn->save();

        $this->assertSame([['op' => 'add']], $turn->refresh()->ops);

        $turn->status = TemplateAssistTurnStatus::Completed;
        $turn->save();

        try {
            $turn->ops = [['op' => 'blocked']];
            $turn->save();
            $this->fail('Ops update on a completed turn must fail.');
        } catch (TemplateAssistTurnFrozen $exception) {
            $this->assertSame('Template assist turn column ops is frozen.', $exception->getMessage());
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::beginTransaction();
            try {
                DB::update(
                    'update template_assist_turns set ops = ?::jsonb where id = ?',
                    ['[{"op":"raw"}]', $turn->id],
                );
                DB::rollBack();
                $this->fail('Raw ops update on a completed turn must fail.');
            } catch (QueryException $exception) {
                DB::rollBack();
                $this->assertStringContainsString('template_assist_turn_content_frozen', $exception->getMessage());
            }
        }

        $turn->refresh();
        $turn->status = TemplateAssistTurnStatus::Applied;
        $turn->error_key = 'template_assist.conflict';
        $turn->save();
        $turn->refresh();

        $this->assertSame(TemplateAssistTurnStatus::Applied, $turn->status);
        $this->assertSame('template_assist.conflict', $turn->error_key);
    }

    #[Test]
    public function status_forward_only(): void
    {
        $legal = [
            [TemplateAssistTurnStatus::Running, TemplateAssistTurnStatus::Completed],
            [TemplateAssistTurnStatus::Running, TemplateAssistTurnStatus::Failed],
            [TemplateAssistTurnStatus::Completed, TemplateAssistTurnStatus::Applied],
            [TemplateAssistTurnStatus::Completed, TemplateAssistTurnStatus::Conflict],
            [TemplateAssistTurnStatus::Completed, TemplateAssistTurnStatus::Discarded],
            [TemplateAssistTurnStatus::Applied, TemplateAssistTurnStatus::Undone],
            [TemplateAssistTurnStatus::Undone, TemplateAssistTurnStatus::Applied],
        ];

        foreach ($legal as [$from, $to]) {
            $turn = $this->turnAt($from);
            $turn->status = $to;
            $turn->save();
            $this->assertSame($to, $turn->refresh()->status);
        }

        $illegal = [
            [TemplateAssistTurnStatus::Applied, TemplateAssistTurnStatus::Running],
            [TemplateAssistTurnStatus::Running, TemplateAssistTurnStatus::Applied],
            [TemplateAssistTurnStatus::Failed, TemplateAssistTurnStatus::Applied],
        ];

        foreach ($illegal as [$from, $to]) {
            $turn = $this->turnAt($from);
            $turn->status = $to;

            try {
                $turn->save();
                $this->fail("{$from->value} → {$to->value} must throw.");
            } catch (TemplateAssistTurnFrozen $exception) {
                $this->assertSame(
                    "Template assist turn cannot move from {$from->value} to {$to->value}.",
                    $exception->getMessage(),
                );
            }
        }
    }

    #[Test]
    public function unique_client_message_per_session(): void
    {
        $first = $this->makeSession();
        $second = $this->makeSession();

        $this->makeTurn($first, 'same-id');
        $reused = $this->makeTurn($second, 'same-id');

        $this->assertNotNull($reused->id);

        try {
            $this->makeTurn($first, 'same-id');
            $this->fail('Duplicate client_message_id in one session must fail.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function deleting_family_cascades_sessions_and_turns(): void
    {
        $versionFamily = TemplateFamily::factory()->create();
        $version = TemplateVersion::query()->create([
            'template_family_id' => $versionFamily->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);

        $family = TemplateFamily::factory()->create();
        $session = $this->makeSession($family);
        $turn = $this->makeTurn($session, 'cascade-1', $version);

        $family->delete();

        $this->assertDatabaseMissing('template_assist_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('template_assist_turns', ['id' => $turn->id]);
        $this->assertDatabaseHas('template_versions', ['id' => $version->id]);
    }

    #[Test]
    public function template_assist_config_defaults(): void
    {
        $this->assertSame(100, config('ai.template_assist.daily_turn_limit'));
        $this->assertSame(12, config('ai.template_assist.max_tool_calls'));
        $this->assertSame(4000, config('ai.template_assist.max_prompt_chars'));
        $this->assertSame('ta-1', config('ai.template_assist.prompt_version'));
    }

    #[Test]
    public function operations_manager_receives_template_assist_use(): void
    {
        $lists = RbacSystemRoleSeeder::explicitPermissionLists();

        $this->assertContains(Permission::TemplateAssistUse, $lists['operations_manager']);
        $this->assertNotContains(Permission::TemplateAssistUse, $lists['site_manager']);
    }

    private function makeSession(?TemplateFamily $family = null): TemplateAssistSession
    {
        $family ??= $this->family;
        $this->sequence++;

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid(),
            'participant_type' => 'employee',
            'participant_id' => $this->employee->id,
            'title' => 'Assist '.$this->sequence,
            'site_scope_snapshot' => null,
        ]);

        return TemplateAssistSession::query()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'conversation_id' => $conversation->id,
            'employee_id' => $this->employee->id,
        ]);
    }

    private function makeTurn(
        TemplateAssistSession $session,
        string $clientMessageId,
        ?TemplateVersion $version = null,
    ): TemplateAssistTurn {
        $version ??= $this->version;
        $document = ['version' => 2, 'sections' => []];

        return TemplateAssistTurn::query()->create([
            'session_id' => $session->id,
            'call_id' => (string) Str::uuid(),
            'client_message_id' => $clientMessageId,
            'template_version_id' => $version->id,
            'base_document' => $document,
            'base_hash' => hash('sha256', '{"version":2}'),
            'working_document' => $document,
            'ops' => [],
            'op_summary' => [],
            'prompt_version' => 'ta-1',
        ]);
    }

    private function turnAt(TemplateAssistTurnStatus $status): TemplateAssistTurn
    {
        $turn = $this->makeTurn($this->makeSession(), 'edge-'.$this->sequence);

        foreach ($this->pathTo($status) as $step) {
            $turn->status = $step;
            $turn->save();
        }

        return $turn->refresh();
    }

    /**
     * @return list<TemplateAssistTurnStatus>
     */
    private function pathTo(TemplateAssistTurnStatus $status): array
    {
        return match ($status) {
            TemplateAssistTurnStatus::Running => [],
            TemplateAssistTurnStatus::Completed => [TemplateAssistTurnStatus::Completed],
            TemplateAssistTurnStatus::Failed => [TemplateAssistTurnStatus::Failed],
            TemplateAssistTurnStatus::Applied => [TemplateAssistTurnStatus::Completed, TemplateAssistTurnStatus::Applied],
            TemplateAssistTurnStatus::Conflict => [TemplateAssistTurnStatus::Completed, TemplateAssistTurnStatus::Conflict],
            TemplateAssistTurnStatus::Discarded => [TemplateAssistTurnStatus::Completed, TemplateAssistTurnStatus::Discarded],
            TemplateAssistTurnStatus::Undone => [
                TemplateAssistTurnStatus::Completed,
                TemplateAssistTurnStatus::Applied,
                TemplateAssistTurnStatus::Undone,
            ],
        };
    }
}
