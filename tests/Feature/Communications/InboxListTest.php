<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class InboxListTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
    }

    public function test_filters_compose_bounded_queries(): void
    {
        $mine = Contact::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Miner',
            'email' => 'alice.miner@example.com',
        ]);
        $this->givePrimaryEmail($mine, 'alice.miner@example.com');

        $other = Contact::factory()->create([
            'first_name' => 'Bob',
            'last_name' => 'Other',
            'email' => 'bob.other@example.com',
        ]);
        $this->givePrimaryEmail($other, 'bob.other@example.com');

        $this->makeInboxThread($mine, [
            'subject' => 'Lease question',
            'assigned_employee_id' => $this->employee->id,
            'unread_count' => 2,
            'last_message_at' => now()->subMinutes(10),
        ]);

        $this->makeInboxThread($other, [
            'channel' => Channel::Sms,
            'subject' => null,
            'channel_key' => '+15551234567',
            'assigned_employee_id' => null,
            'unread_count' => 0,
            'last_message_at' => now()->subMinutes(5),
        ], [
            'from_address' => '+15551234567',
            'to_address' => '+15550001111',
            'body_text' => 'SMS preview',
        ]);

        SuppressionWriter::write(
            Channel::Email,
            'alice.miner@example.com',
            SuppressionScope::All,
            SuppressionReason::Unsubscribed,
        );

        $this->getJson('/api/inbox/threads?filter=mine&unread=1&channel=email&q=Alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', 'email')
            ->assertJsonPath('data.0.contact.name', 'Alice Miner')
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('data.0.suppressed', true)
            ->assertJsonPath('data.0.preview.direction', 'inbound')
            ->assertJsonStructure([
                'data' => [['id', 'channel', 'contact', 'preview', 'unread_count', 'assigned_employee', 'last_message_at', 'suppressed']],
                'meta' => ['next_cursor'],
            ]);

        // Seed 500 threads for the bounded-query assertion.
        $bulkContact = Contact::factory()->create();
        $base = now()->subDays(30);
        for ($i = 0; $i < 500; $i++) {
            $at = $base->copy()->addSeconds($i);
            $thread = MessageThread::query()->create([
                'contact_id' => $bulkContact->id,
                'channel' => Channel::Email,
                'subject' => "Bulk {$i}",
                'channel_key' => null,
                'last_message_at' => $at,
                'unread_count' => $i % 7 === 0 ? 1 : 0,
            ]);
            Message::query()->create([
                'message_thread_id' => $thread->id,
                'direction' => MessageDirection::Outbound,
                'status' => MessageStatus::Sent,
                'body_text' => "Body {$i}",
                'body_html' => null,
                'from_address' => 'desk@example.com',
                'to_address' => 'bulk@example.com',
                'source' => MessageSource::Manual,
                'auto_generated' => false,
                'sent_at' => $at,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/inbox/threads?per_page=25')
            ->assertOk()
            ->assertJsonCount(25, 'data');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Aggregate + eager loads + suppression batch — never N+1 across 25 rows.
        $this->assertLessThanOrEqual(12, $queryCount, "Expected bounded queries, got {$queryCount}");
    }

    public function test_cursor_stable_under_arrivals(): void
    {
        $contact = Contact::factory()->create();

        $threads = [];
        for ($i = 0; $i < 5; $i++) {
            $threads[] = $this->makeInboxThread($contact, [
                'subject' => "Thread {$i}",
                'last_message_at' => now()->subMinutes(10 - $i),
            ]);
        }

        // Newest-first: threads[4], [3], [2], [1], [0]
        $page1 = $this->getJson('/api/inbox/threads?per_page=2')->assertOk();
        $ids1 = collect($page1->json('data'))->pluck('id')->all();
        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $this->assertSame([$threads[4]->id, $threads[3]->id], $ids1);

        // Arrival lands above the fold while client is mid-scroll.
        $arrival = $this->makeInboxThread($contact, [
            'subject' => 'Brand new',
            'last_message_at' => now()->addMinute(),
        ]);

        $page2 = $this->getJson('/api/inbox/threads?per_page=2&cursor='.urlencode((string) $cursor))
            ->assertOk();
        $ids2 = collect($page2->json('data'))->pluck('id')->all();

        $this->assertSame([$threads[2]->id, $threads[1]->id], $ids2);
        $this->assertEmpty(array_intersect($ids1, $ids2));
        $this->assertNotContains($arrival->id, $ids2);

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonStructure(['data' => ['unread_threads', 'triage_count']]);
    }

    public function test_thread_detail_paginates_newest_first(): void
    {
        $contact = Contact::factory()->create();
        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Detail thread',
            'last_message_at' => now(),
        ]);

        $olderIds = [];
        for ($i = 0; $i < 3; $i++) {
            $olderIds[] = Message::query()->create([
                'message_thread_id' => $thread->id,
                'direction' => MessageDirection::Outbound,
                'status' => MessageStatus::Sent,
                'body_text' => "Extra {$i}",
                'body_html' => null,
                'from_address' => 'desk@example.com',
                'to_address' => 'renter@example.com',
                'source' => MessageSource::Manual,
                'auto_generated' => false,
                'sent_at' => now()->subMinutes(3 - $i),
            ])->id;
        }

        $first = $this->getJson("/api/inbox/threads/{$thread->id}?per_page=2")
            ->assertOk()
            ->assertJsonPath('data.id', $thread->id)
            ->assertJsonCount(2, 'data.messages');

        $pageIds = collect($first->json('data.messages'))->pluck('id')->all();
        $this->assertSame($olderIds[2], $pageIds[0]); // newest first
        $nextBefore = $first->json('data.meta.next_before');
        $this->assertNotNull($nextBefore);

        $second = $this->getJson("/api/inbox/threads/{$thread->id}?per_page=2&before={$nextBefore}")
            ->assertOk();
        $page2Ids = collect($second->json('data.messages'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($pageIds, $page2Ids));
    }
}
