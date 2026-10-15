<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateAssistTurnStatus;
use App\Models\TemplateAssistTurn;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateAssistTurnTest extends TestCase
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
    public function status_reporting_forward_only(): void
    {
        $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $sessionId = $this->openAssistSession($family);

        $applied = $this->postTurn($sessionId, $version->id, 'msg-applied');
        $this->postJson("/api/template-assist/turns/{$applied}/status", [
            'status' => 'applied',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'assist.status_not_allowed');

        $this->complete($applied);
        $this->postJson("/api/template-assist/turns/{$applied}/status", [
            'status' => 'applied',
        ])->assertOk()->assertJsonPath('data.status', 'applied');
        $this->postJson("/api/template-assist/turns/{$applied}/status", [
            'status' => 'undone',
        ])->assertOk()->assertJsonPath('data.status', 'undone');
        $this->postJson("/api/template-assist/turns/{$applied}/status", [
            'status' => 'applied',
        ])->assertOk()->assertJsonPath('data.status', 'applied');
        $this->postJson("/api/template-assist/turns/{$applied}/status", [
            'status' => 'discarded',
        ])->assertStatus(409);
        $this->postJson("/api/template-assist/turns/{$applied}/status", [
            'status' => 'running',
        ])->assertStatus(422);

        $conflict = $this->postTurn($sessionId, $version->id, 'msg-conflict');
        $this->complete($conflict);
        $this->postJson("/api/template-assist/turns/{$conflict}/status", [
            'status' => 'conflict',
        ])->assertOk()->assertJsonPath('data.status', 'conflict');
        $this->postJson("/api/template-assist/turns/{$conflict}/status", [
            'status' => 'applied',
        ])->assertStatus(409);

        $discarded = $this->postTurn($sessionId, $version->id, 'msg-discarded');
        $this->complete($discarded);
        $this->postJson("/api/template-assist/turns/{$discarded}/status", [
            'status' => 'discarded',
        ])->assertOk()->assertJsonPath('data.status', 'discarded');
    }

    private function postTurn(int $sessionId, int $versionId, string $clientMessageId): int
    {
        $response = $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => $clientMessageId,
            'message' => 'Soften the tone',
            'template_version_id' => $versionId,
            'document' => $this->assistDocument(),
        ]);
        $response->assertAccepted();

        return (int) $response->json('data.turn_id');
    }

    private function complete(int $turnId): void
    {
        $turn = TemplateAssistTurn::query()->findOrFail($turnId);
        $turn->status = TemplateAssistTurnStatus::Completed;
        $turn->save();
    }
}
