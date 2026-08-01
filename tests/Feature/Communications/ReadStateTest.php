<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\MessageThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class ReadStateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsInboxThreads;

    public function test_idempotent_and_benign_race(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create();
        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Unread thread',
            'unread_count' => 4,
            'last_message_at' => now(),
        ]);

        $activitiesBefore = Activity::query()->count();

        $this->postJson("/api/inbox/threads/{$thread->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->postJson("/api/inbox/threads/{$thread->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $thread->refresh();
        $this->assertSame(0, (int) $thread->unread_count);

        // Mark-read must not write Tier-2 comms activity (high-noise).
        $this->assertSame($activitiesBefore, Activity::query()->count());

        // Benign race: subsequent inbound increments after zeroing.
        MessageThread::query()->whereKey($thread->id)->increment('unread_count');
        $thread->refresh();
        $this->assertSame(1, (int) $thread->unread_count);

        $this->getJson('/api/inbox/threads?unread=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $thread->id]);
    }
}
