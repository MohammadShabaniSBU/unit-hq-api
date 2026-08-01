<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\EmailBlock;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Communications\Channel;
use App\Support\Communications\EmailTemplateRenderer;
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

        $template = EmailTemplate::query()->create(['name' => 'Inbox hello']);
        EmailBlock::query()->create([
            'email_template_id' => $template->id,
            'type' => 'text',
            'props' => [
                'content' => 'Hello {{contact.first_name}}, balance link {{pay_link}}',
                'align' => 'left',
                'fontSize' => 16,
                'color' => '#000000',
            ],
            'order' => 0,
        ]);

        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Tokens',
            'channel' => Channel::Email,
        ]);

        $expected = EmailTemplateRenderer::render(
            $template->fresh('emailBlocks') ?? $template,
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
            'email_template_id' => $template->id,
        ]);

        $response->assertCreated();

        $message = Message::query()->findOrFail($response->json('data.message.id'));
        $this->assertSame($expected['text'], $message->body_text);
        $this->assertStringContainsString('Hello Ada', (string) $message->body_html);
        $this->assertStringContainsString('[pay-link]', (string) $message->body_html);

        // Freeform token resolution
        $free = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'Hi {{contact.first_name}}',
        ]);
        $free->assertCreated();
        $freeMessage = Message::query()->findOrFail($free->json('data.message.id'));
        $this->assertSame('Hi Ada', $freeMessage->body_text);
    }
}
