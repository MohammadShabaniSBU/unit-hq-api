<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class InboxDeltaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsInboxThreads;

    public function test_updated_after_contract(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create();

        $stable = $this->makeInboxThread($contact, [
            'subject' => 'Stable',
            'last_message_at' => now()->subHour(),
            'unread_count' => 1,
        ]);
        $toAssign = $this->makeInboxThread($contact, [
            'subject' => 'Assign me',
            'last_message_at' => now()->subMinutes(50),
            'unread_count' => 0,
        ]);
        $toRead = $this->makeInboxThread($contact, [
            'subject' => 'Read me',
            'last_message_at' => now()->subMinutes(40),
            'unread_count' => 3,
        ]);

        // Ensure stable row is older than the watermark.
        $stable->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $watermark = Carbon::now()->toIso8601String();
        $this->travel(2)->seconds();

        $this->postJson("/api/inbox/threads/{$toAssign->id}/assign", [
            'employee_id' => $employee->id,
        ])->assertOk();

        $this->postJson("/api/inbox/threads/{$toRead->id}/read")->assertOk();

        $response = $this->getJson('/api/inbox/threads?updated_after='.urlencode($watermark))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$toAssign->id, $toRead->id])->sort()->values()->all();

        $this->assertSame($expected, $ids);
        $this->assertNotContains($stable->id, $ids);

        $response->assertJsonStructure([
            'data' => [[
                'id',
                'channel',
                'contact' => ['id', 'name', 'avatar_initials'],
                'preview',
                'unread_count',
                'assigned_employee',
                'last_message_at',
                'suppressed',
            ]],
            'meta' => ['next_cursor'],
        ]);
    }
}
