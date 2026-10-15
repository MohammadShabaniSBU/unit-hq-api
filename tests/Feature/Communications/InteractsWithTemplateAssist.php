<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\Employee;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use Laravel\Sanctum\Sanctum;

trait InteractsWithTemplateAssist
{
    protected function assistManager(): Employee
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        return $employee;
    }

    /**
     * @return array{0: TemplateFamily, 1: TemplateVersion}
     */
    protected function assistDraft(string $name = 'Rate notice'): array
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
            'subject' => 'Hi',
            'legacy_html' => '<p>Hello</p>',
        ]);

        return [$family, $version];
    }

    /**
     * @return array{version: int, sections: list<mixed>}
     */
    protected function assistDocument(): array
    {
        return [
            'version' => 2,
            'sections' => [],
        ];
    }

    protected function openAssistSession(TemplateFamily $family, string $locale = 'en'): int
    {
        $response = $this->postJson("/api/template-families/{$family->id}/assist/sessions", [
            'locale' => $locale,
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }
}
