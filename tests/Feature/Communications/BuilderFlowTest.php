<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Site;
use App\Models\TemplateVariant;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\TemplateBuilderContext;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class BuilderFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    public function test_preview_equals_send(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Builder flow',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Hi {{contact.first_name}}',
            'blocks' => [
                'version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'params' => ['html' => '<p>Hello {{contact.first_name}}</p>'],
                ]],
            ],
            'legacy_html' => null,
        ]]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        $preview = $this->post(
            "/api/template-families/{$family->id}/variants/{$variant->id}/preview",
            ['contact_id' => $contact->id],
        );
        $preview->assertOk();
        $previewHtml = $preview->getContent();

        $context = TemplateBuilderContext::for($contact);
        $rendered = EmailTemplateRenderer::render($variant->fresh(), $context, previewMarkers: true);
        $this->assertSame($rendered['html'], $previewHtml);

        // Send path uses the same renderer without preview markers; body still matches
        // when all tokens resolve (no missing markers differ).
        $sendRendered = EmailTemplateRenderer::render(
            $variant->fresh(),
            new RunContext(subjectBag: SubjectTokenBag::forContact($contact)),
            previewMarkers: false,
        );
        $this->assertSame(
            EmailTemplateRenderer::render($variant->fresh(), $context, previewMarkers: false)['html'],
            $sendRendered['html'],
        );

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-test-1'], 201),
        ]);

        $testSend = $this->postJson(
            "/api/template-families/{$family->id}/variants/{$variant->id}/test-send",
            [
                'to' => 'ops@example.com',
                'contact_id' => $contact->id,
                'site_id' => $site->id,
            ],
        );
        $testSend->assertOk();
        $this->assertNotNull($testSend->json('data.message_id'));
    }

    public function test_legacy_import_raw_html_preserves_rendering(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create(['first_name' => 'Ada']);
        $legacy = '<div style="padding:8px">Hello {{contact.first_name}}</div>';

        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Legacy',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Hi',
            'legacy_html' => $legacy,
            'blocks' => null,
        ]]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        $before = EmailTemplateRenderer::render(
            $variant,
            TemplateBuilderContext::for($contact),
        );

        $update = $this->putJson("/api/template-families/{$family->id}/variants/{$variant->id}", [
            'blocks' => [
                'version' => 1,
                'blocks' => [[
                    'id' => 'legacy',
                    'type' => 'raw_html',
                    'params' => ['html' => $legacy],
                ]],
            ],
        ]);
        $update->assertOk();
        $this->assertSame($legacy, $update->json('data.current_version.variants.0.legacy_html'));
        $this->assertNull($update->json('data.draft_version.variants.0.legacy_html'));

        $draftVariant = TemplateVariant::query()->findOrFail((int) $update->json('data.draft_version.variants.0.id'));
        $after = EmailTemplateRenderer::render(
            $draftVariant,
            TemplateBuilderContext::for($contact),
        );

        $this->assertStringContainsString('Hello Ada', $before['html']);
        $this->assertStringContainsString('Hello Ada', $after['html']);
        $this->assertStringContainsString('Hello Ada', $after['text']);
    }
}
