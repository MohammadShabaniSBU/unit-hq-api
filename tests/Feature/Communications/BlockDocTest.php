<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Employee;
use App\Support\Communications\EmailBlockDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlockDocTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_validation_closed_set(): void
    {
        $this->expectException(ValidationException::class);
        EmailBlockDocument::validate([
            'version' => 1,
            'blocks' => [[
                'id' => 'x',
                'type' => 'carousel',
                'params' => [],
            ]],
        ]);
    }

    public function test_unknown_version_rejected(): void
    {
        $this->expectException(ValidationException::class);
        EmailBlockDocument::validate([
            'version' => 99,
            'blocks' => [],
        ]);
    }

    public function test_save_rejects_unknown_type_via_api(): void
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
        $variantId = (int) $create->json('data.variants.0.id');

        $response = $this->putJson("/api/template-families/{$familyId}/variants/{$variantId}", [
            'blocks' => [
                'version' => 1,
                'blocks' => [[
                    'id' => 'bad',
                    'type' => 'unknown_block',
                    'params' => [],
                ]],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors'));
    }

    public function test_valid_document_persists_and_clears_legacy_html(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $create = $this->postJson('/api/template-families', [
            'channel' => 'email',
            'name' => 'Legacy',
            'locale' => 'en',
            'legacy_html' => '<p>old</p>',
        ]);
        $create->assertCreated();
        $familyId = (int) $create->json('data.id');
        $variantId = (int) $create->json('data.variants.0.id');

        $doc = [
            'version' => 1,
            'blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'params' => ['html' => '<p>Hello</p>'],
            ]],
        ];

        $update = $this->putJson("/api/template-families/{$familyId}/variants/{$variantId}", [
            'blocks' => $doc,
        ]);
        $update->assertOk();
        $this->assertNull($update->json('data.variants.0.legacy_html'));
        $this->assertSame(1, $update->json('data.variants.0.blocks.version'));
        $this->assertSame('paragraph', $update->json('data.variants.0.blocks.blocks.0.type'));
    }
}
