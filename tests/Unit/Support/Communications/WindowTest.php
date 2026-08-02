<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Models\Contact;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use App\Support\Communications\WhatsAppWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_computed_boundary_exact(): void
    {
        $contact = Contact::factory()->create();
        $inboundAt = Carbon::parse('2026-08-01 12:00:00', 'UTC');

        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Whatsapp,
            'channel_key' => '+15551234567',
            'last_message_at' => $inboundAt,
            'last_inbound_at' => $inboundAt,
            'unread_count' => 0,
        ]);

        $this->assertNull(
            $thread->getAttributes()['session_open'] ?? null,
            'Window must never be stored as a column',
        );

        Carbon::setTestNow(Carbon::parse('2026-08-02 11:59:59', 'UTC'));
        $this->assertTrue(WhatsAppWindow::isOpen($thread));
        $payload = WhatsAppWindow::payload($thread);
        $this->assertNotNull($payload);
        $this->assertTrue($payload['open']);
        $this->assertSame(
            $inboundAt->copy()->addDay()->toIso8601String(),
            $payload['closes_at'],
        );

        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:01', 'UTC'));
        $this->assertFalse(WhatsAppWindow::isOpen($thread));
        $this->assertFalse(WhatsAppWindow::payload($thread)['open']);

        $emailThread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => 'Hello',
            'last_message_at' => $inboundAt,
            'last_inbound_at' => $inboundAt,
            'unread_count' => 0,
        ]);
        $this->assertNull(WhatsAppWindow::payload($emailThread));

        Carbon::setTestNow();
    }
}
