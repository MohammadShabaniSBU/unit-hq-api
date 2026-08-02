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
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailTemplateRenderer;
use App\Support\Communications\LegacyEmailBlocksHtml;
use App\Support\Communications\TemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class TemplateTokenTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    public function test_render_parity(): void
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
            'name' => 'Inbox hello',
            'purpose' => TemplatePurpose::General,
        ]);
        $family->variants()->create([
            'locale' => 'en',
            'subject' => 'Inbox hello',
            'legacy_html' => LegacyEmailBlocksHtml::fromBlocks([[
                'type' => 'text',
                'props' => [
                    'content' => 'Hello {{contact.first_name}}, balance link {{pay_link}}',
                    'align' => 'left',
                    'fontSize' => 16,
                    'color' => '#000000',
                ],
            ]]),
        ]);
        $family->load('variants');

        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Tokens',
            'channel' => Channel::Email,
        ]);

        $variant = TemplateResolver::variant($family, $contact, $site);
        $expected = EmailTemplateRenderer::render(
            $variant,
            new RunContext(subjectBag: SubjectTokenBag::forContact($contact)),
        );

        $seq = 0;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => function () use (&$seq) {
                $seq++;

                return Http::response(['messageId' => 'brevo-tpl-'.$seq], 201);
            },
        ]);

        $response = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'unused when template set',
            'template_family_id' => $family->id,
        ]);

        $response->assertCreated();

        $message = Message::query()->findOrFail($response->json('data.message.id'));
        $this->assertSame($expected['text'], $message->body_text);
        $this->assertStringContainsString('Hello Ada', (string) $message->body_html);
        $this->assertStringContainsString('[pay-link]', (string) $message->body_html);

        $free = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'Hi {{contact.first_name}}',
        ]);
        $free->assertCreated();
        $freeMessage = Message::query()->findOrFail($free->json('data.message.id'));
        $this->assertSame('Hi Ada', $freeMessage->body_text);
    }
}
