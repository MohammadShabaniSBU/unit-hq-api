<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Employee;
use App\Support\Communications\EmailBlockDocument;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemplateVersionDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_from_v1_published_is_v2(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $v1 = [
            'version' => 1,
            'blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'params' => ['html' => '<p>Hello</p>'],
            ]],
        ];

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Upcast',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Hi',
            'blocks' => $v1,
            'legacy_html' => null,
        ]]);

        $published = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();
        $before = DB::table('template_variants')->where('id', $published->id)->value('blocks');

        $draft = $this->postJson("/api/template-families/{$family->id}/versions");
        $draft->assertCreated();

        $after = DB::table('template_variants')->where('id', $published->id)->value('blocks');
        $this->assertSame($before, $after);
        $this->assertSame(1, $published->fresh()->blocks['version']);

        $stored = $draft->json('data.variants.0.blocks');
        $this->assertSame(EmailBlockDocument::VERSION_2, $stored['version']);
        $this->assertSame('100', $stored['sections'][0]['layout']);
        $this->assertSame('blk_'.substr(sha1('blk:p1'), 0, 8), $stored['sections'][0]['columns'][0]['blocks'][0]['id']);
        $this->assertSame('<p>Hello</p>', $stored['sections'][0]['columns'][0]['blocks'][0]['params']['html']);
        EmailBlockDocument::validate($stored);
    }

    public function test_document_channel_draft_keeps_v1(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $v1 = [
            'version' => 1,
            'blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'params' => ['html' => '<p>Hi</p>'],
            ]],
        ];

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Document,
            'name' => 'Lease',
            'purpose' => TemplatePurpose::Contract,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Lease',
            'blocks' => $v1,
        ]]);

        $draft = $this->postJson("/api/template-families/{$family->id}/versions");
        $draft->assertCreated();
        $this->assertSame(1, $draft->json('data.variants.0.blocks.version'));
        $this->assertSame('p1', $draft->json('data.variants.0.blocks.blocks.0.id'));
    }
}
