<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\SystemEvent;
use App\Support\RecordsActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedactContactCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_redacts_allowlisted_keys_and_logs_core_event(): void
    {
        $contact = Contact::factory()->create(['email' => 'secret@example.com']);

        RecordsActivity::core('updated', $contact, [
            'email' => 'secret@example.com',
            'value' => '+15551212',
            'safe' => 'keep-me',
        ]);

        SystemEvent::query()->create([
            'event' => 'test.event',
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
            'payload' => ['email' => 'secret@example.com', 'ok' => true],
            'created_at' => now(),
        ]);

        $this->artisan('contacts:redact', ['contact' => $contact->id])->assertSuccessful();

        $activity = Activity::query()
            ->where('description', 'updated')
            ->where('subject_id', $contact->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->properties->get('email'));
        $this->assertNull($activity->properties->get('value'));
        $this->assertSame('keep-me', $activity->properties->get('safe'));

        $event = SystemEvent::query()->where('event', 'test.event')->first();
        $this->assertNull($event->payload['email']);
        $this->assertTrue($event->payload['ok']);

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'contact.redacted')
                ->where('subject_id', $contact->id)
                ->exists()
        );
    }
}
