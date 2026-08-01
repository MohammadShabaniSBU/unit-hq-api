<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class ReplyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    public function test_headers_thread_both_sides(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $this->fakeCommunicationProviders();

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create([
            'first_name' => 'Riley',
            'last_name' => 'Renter',
            'email' => 'riley@example.com',
        ]);
        $this->givePrimaryEmail($contact, 'riley@example.com');

        $inboundId = 'inbound-msg-abc123@mail.example';
        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Unit availability',
            'channel' => Channel::Email,
        ], [
            'provider_message_id' => $inboundId,
            'threading_evidence' => [
                'message_id' => '<'.$inboundId.'>',
                'strategy' => 'new',
            ],
            'direction' => MessageDirection::Inbound,
            'status' => MessageStatus::Received,
        ]);

        $captured = null;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => function ($request) use (&$captured) {
                $captured = $request->data();

                return Http::response(['messageId' => 'brevo-reply-1'], 201);
            },
        ]);

        $response = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'Thanks for reaching out.',
            'body_html' => '<p>Thanks for reaching out.</p>',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.thread_id', $thread->id);
        $response->assertJsonPath('data.message.source', 'manual');
        $response->assertJsonPath('data.message.source_ref.employee_id', $employee->id);

        $this->assertIsArray($captured);
        $headers = $captured['headers'] ?? [];
        $this->assertSame('<'.$inboundId.'>', $headers['In-Reply-To'] ?? null);
        $this->assertSame('<'.$inboundId.'>', $headers['References'] ?? null);
        $this->assertSame('Re: Unit availability', $captured['subject'] ?? null);

        $outbound = Message::query()->findOrFail($response->json('data.message.id'));
        $this->assertSame($thread->id, $outbound->message_thread_id);
        $this->assertSame(MessageSource::Manual, $outbound->source);
        $this->assertSame($employee->id, $outbound->source_ref['employee_id'] ?? null);
        $this->assertSame('explicit', $outbound->threading_evidence['strategy'] ?? null);
    }
}
