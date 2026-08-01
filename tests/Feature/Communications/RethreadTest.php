<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SystemEvent;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RethreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_move_endpoint_audited(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create();

        $from = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => 'Wrong thread',
            'channel_key' => null,
            'last_message_at' => now(),
            'unread_count' => 1,
        ]);

        $to = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => 'Right thread',
            'channel_key' => null,
            'last_message_at' => now()->subDay(),
            'unread_count' => 0,
        ]);

        $message = Message::query()->create([
            'message_thread_id' => $from->id,
            'direction' => MessageDirection::Inbound,
            'status' => MessageStatus::Received,
            'body_text' => 'Misfiled',
            'body_html' => null,
            'from_address' => 'renter@example.com',
            'to_address' => 'desk@example.com',
            'source' => MessageSource::System,
            'auto_generated' => false,
            'threading_evidence' => ['strategy' => 'subject'],
        ]);

        $this->postJson("/api/messages/{$message->id}/move-thread", [
            'message_thread_id' => $to->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.message_thread_id', $to->id);

        $message->refresh();
        $this->assertSame($to->id, $message->message_thread_id);
        $this->assertTrue($message->threading_evidence['rethreaded'] ?? false);
        $this->assertSame($from->id, $message->threading_evidence['from_thread_id'] ?? null);

        $this->assertTrue(
            SystemEvent::query()->where('event', 'message.rethreaded')->exists(),
        );

        $this->assertTrue(
            Activity::query()->where('event', 'message.rethreaded')->exists()
            || Activity::query()->where('description', 'message.rethreaded')->exists(),
        );

        $from->refresh();
        $to->refresh();
        $this->assertSame(0, $from->unread_count);
        $this->assertSame(1, $to->unread_count);
    }
}
