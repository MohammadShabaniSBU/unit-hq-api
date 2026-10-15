<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateAssistNoWritesTest extends TestCase
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
    public function endpoints_never_touch_templates(): void
    {
        $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $before = $this->snapshot();

        $this->getJson("/api/template-families/{$family->id}/assist/session?locale=en")
            ->assertOk()
            ->assertJsonPath('data', null);

        $sessionId = $this->openAssistSession($family);
        $turn = $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-nowrite',
            'message' => 'Soften the tone',
            'template_version_id' => $version->id,
            'document' => $this->assistDocument(),
        ]);
        $turn->assertAccepted();
        $turnId = (int) $turn->json('data.turn_id');

        $this->getJson("/api/template-assist/turns/{$turnId}")->assertOk();
        $this->postJson("/api/template-assist/turns/{$turnId}/status", [
            'status' => 'applied',
        ])->assertStatus(409);

        $this->assertSame($before, $this->snapshot());

        $source = file_get_contents(app_path('Http/Controllers/TemplateAssistController.php'))
            .file_get_contents(app_path('Support/Templates/Assist/TemplateAssistDispatcher.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('TemplateVariant', $source);
        $this->assertStringNotContainsString('template_variants', $source);
    }

    /**
     * @return array{versions: list<array<string, mixed>>, variants: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            'versions' => TemplateVersion::query()->orderBy('id')->get()->map(
                fn (TemplateVersion $version): array => $version->getAttributes(),
            )->all(),
            'variants' => TemplateVariant::query()->orderBy('id')->get()->map(
                fn (TemplateVariant $variant): array => $variant->getAttributes(),
            )->all(),
        ];
    }
}
