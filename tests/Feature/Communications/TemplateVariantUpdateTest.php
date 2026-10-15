<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemplateVariantUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_v1_write(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $create = $this->postJson('/api/template-families', [
            'channel' => 'email',
            'name' => 'Blocks',
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $familyId = (int) $create->json('data.id');
        $variantId = (int) $create->json('data.draft_version.variants.0.id');

        $update = $this->putJson("/api/template-families/{$familyId}/variants/{$variantId}", [
            'blocks' => [
                'version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'params' => ['html' => '<p>Hello</p>'],
                ]],
            ],
        ]);

        $update->assertStatus(422);
        $this->assertSame(
            __('errors.templates.blocks_version_write_v1'),
            $update->json('errors')['blocks.version'][0],
        );
    }

    public function test_document_channel_still_accepts_v1_write(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $doc = [
            'version' => 1,
            'blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'params' => ['html' => '<p>Hi</p>'],
            ]],
        ];

        $create = $this->postJson('/api/template-families', [
            'channel' => 'document',
            'name' => 'Lease',
            'purpose' => 'contract',
            'locale' => 'en',
            'blocks' => $doc,
        ]);
        $create->assertCreated();
        $familyId = (int) $create->json('data.id');
        $variantId = (int) $create->json('data.draft_version.variants.0.id');

        $this->putJson("/api/template-families/{$familyId}/variants/{$variantId}", [
            'blocks' => $doc,
        ])->assertOk()
            ->assertJsonPath('data.draft_version.variants.0.blocks.version', 1);
    }
}
