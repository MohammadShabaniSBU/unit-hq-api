<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Automation\TokenResolver;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\LegacyEmailBlocksHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class RendererTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    public function test_legacy_passthrough_identical(): void
    {
        $blocks = [[
            'type' => 'text',
            'props' => [
                'content' => 'Hello {{contact.first_name}}, pay {{pay_link}}',
                'align' => 'left',
                'fontSize' => 16,
                'color' => '#000000',
            ],
        ]];

        $frozen = LegacyEmailBlocksHtml::fromBlocks($blocks);

        $variant = TemplateVariant::query()->make([
            'locale' => 'en',
            'subject' => 'Hello',
            'legacy_html' => $frozen,
        ]);

        $contact = Contact::factory()->create(['first_name' => 'Ada']);
        $context = new RunContext(subjectBag: SubjectTokenBag::forContact($contact));

        $rendered = EmailTemplateRenderer::render($variant, $context);
        $expectedHtml = TokenResolver::resolve($frozen, $context);

        $this->assertSame($expectedHtml, $rendered['html']);
        $this->assertStringContainsString('Hello Ada', $rendered['html']);
    }

    public function test_token_warnings_not_blanks(): void
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
        $this->givePrimaryEmail($contact, 'ada@example.com');

        $family = TemplateFamily::query()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'Warn me',
            'purpose' => TemplatePurpose::General,
        ]);
        $family->variants()->create([
            'locale' => 'en',
            'subject' => 'Hi',
            'legacy_html' => LegacyEmailBlocksHtml::fromBlocks([[
                'type' => 'text',
                'props' => [
                    'content' => 'Dear {{contact.missing_field}}, hello {{contact.first_name}}',
                    'align' => 'left',
                    'fontSize' => 16,
                    'color' => '#000000',
                ],
            ]]),
        ]);

        $preview = EmailTemplateRenderer::render(
            $family->variants()->firstOrFail(),
            new RunContext(subjectBag: SubjectTokenBag::forContact($contact)),
            previewMarkers: true,
        );
        $this->assertStringContainsString(
            TokenResolver::PREVIEW_MISSING_PREFIX.'contact.missing_field'.TokenResolver::PREVIEW_MISSING_SUFFIX,
            $preview['html'],
        );
        $this->assertContains('contact.missing_field', $preview['warnings']);

        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Warnings',
            'channel' => Channel::Email,
        ]);

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-warn-1'], 201),
        ]);

        $response = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'unused',
            'template_family_id' => $family->id,
        ]);
        $response->assertCreated();

        $message = Message::query()->findOrFail($response->json('data.message.id'));
        $this->assertIsArray($message->detail);
        $this->assertContains('contact.missing_field', $message->detail['token_warnings'] ?? []);
        $this->assertStringContainsString('hello Ada', (string) $message->body_html);
        $this->assertStringNotContainsString('⟦missing:', (string) $message->body_html);
    }
}
