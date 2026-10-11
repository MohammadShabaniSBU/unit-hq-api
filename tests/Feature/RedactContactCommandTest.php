<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\SubscriptionList;
use App\Models\SystemEvent;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentChannel;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ConsentWriter;
use App\Support\RecordsActivity;
use Database\Seeders\SubscriptionListSeeder;
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

    public function test_redaction_nulls_consent_evidence_and_withdraws_grants(): void
    {
        $this->seed(SubscriptionListSeeder::class);
        $list = SubscriptionList::query()->where('key', 'newsletter')->firstOrFail();
        $contact = Contact::factory()->create();
        $text = ConsentText::factory()->create();

        ConsentWriter::grant(
            $contact,
            Channel::Email,
            $list,
            ConsentSource::Form,
            $text->id,
            ['ip' => '203.0.113.9'],
        );

        $this->artisan('contacts:redact', ['contact' => $contact->id])->assertSuccessful();

        $this->assertNull(ConsentEvent::query()->where('source', ConsentSource::Form)->firstOrFail()->evidence);
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $list->id));
        $this->assertSame(1, ConsentEvent::query()->where('source', ConsentSource::Redaction)->count());
    }

    public function test_redaction_withdraws_advertising_consent(): void
    {
        $this->seed(SubscriptionListSeeder::class);
        $list = SubscriptionList::query()->where('key', 'advertising')->firstOrFail();
        $contact = Contact::factory()->create();
        $text = ConsentText::query()->where('key', 'advertising')->where('locale', 'en')->firstOrFail();

        ConsentWriter::grant(
            $contact,
            ConsentChannel::Ads,
            $list,
            ConsentSource::Form,
            $text->id,
            ['ip' => '203.0.113.9', 'form_id' => 1],
        );

        $this->artisan('contacts:redact', ['contact' => $contact->id])->assertSuccessful();

        $this->assertFalse(ConsentState::isGranted($contact->id, ConsentChannel::Ads, $list->id));
        $this->assertSame(
            1,
            ConsentEvent::query()
                ->where('source', ConsentSource::Redaction)
                ->where('channel', 'ads')
                ->count(),
        );
    }
}
