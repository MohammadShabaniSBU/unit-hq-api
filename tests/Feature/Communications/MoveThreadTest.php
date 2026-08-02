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

class MoveThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_flow_and_audit(): void
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

        Message::query()->create([
            'message_thread_id' => $to->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Sent,
            'body_text' => 'Earlier context',
            'body_html' => null,
            'from_address' => 'desk@example.com',
            'to_address' => 'renter@example.com',
            'source' => MessageSource::Manual,
            'auto_generated' => false,
        ]);

        $targets = $this->getJson("/api/inbox/threads/{$from->id}/move-targets")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $targets);
        $this->assertSame($to->id, $targets[0]['id']);
        $this->assertSame('Right thread', $targets[0]['subject']);
        $this->assertNotNull($targets[0]['preview_excerpt']);

        $this->postJson("/api/messages/{$message->id}/move-thread", [
            'message_thread_id' => $to->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.message_thread_id', $to->id);

        $message->refresh();
        $this->assertSame($to->id, $message->message_thread_id);
        $this->assertTrue($message->threading_evidence['rethreaded'] ?? false);

        $detail = $this->getJson("/api/inbox/threads/{$to->id}")
            ->assertOk();

        $movedPayload = collect($detail->json('data.messages'))
            ->firstWhere('id', $message->id);

        $this->assertNotNull($movedPayload);
        $this->assertTrue($movedPayload['rethreaded']);
        $this->assertSame($from->id, $movedPayload['rethreaded_from_thread_id']);

        $this->assertTrue(
            SystemEvent::query()->where('event', 'message.rethreaded')->exists(),
        );
        $this->assertTrue(
            Activity::query()->where('event', 'message.rethreaded')->exists()
            || Activity::query()->where('description', 'message.rethreaded')->exists(),
        );

        // New-thread path
        $other = Message::query()->create([
            'message_thread_id' => $to->id,
            'direction' => MessageDirection::Inbound,
            'status' => MessageStatus::Received,
            'body_text' => 'Also misfiled',
            'body_html' => null,
            'from_address' => 'renter@example.com',
            'to_address' => 'desk@example.com',
            'source' => MessageSource::System,
            'auto_generated' => false,
            'threading_evidence' => ['strategy' => 'subject', 'subject' => 'Brand new topic'],
        ]);

        $newMove = $this->postJson("/api/messages/{$other->id}/move-thread", [
            'new_thread' => true,
        ])->assertOk();

        $newThreadId = (int) $newMove->json('data.message_thread_id');
        $this->assertNotSame($to->id, $newThreadId);
        $this->assertNotSame($from->id, $newThreadId);

        $newThread = MessageThread::query()->findOrFail($newThreadId);
        $this->assertSame($contact->id, $newThread->contact_id);
        $this->assertSame(Channel::Email, $newThread->channel);

        $other->refresh();
        $this->assertTrue($other->threading_evidence['rethreaded'] ?? false);
    }
}
