<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Models\Contact;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use App\Support\Communications\Threading;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThreadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_subject_reuse_and_sms_number_race(): void
    {
        $contact = Contact::factory()->create();

        $first = Threading::forOutbound($contact, Channel::Email, 'Storage quote');
        $reuse = Threading::forOutbound($contact, Channel::Email, 'Re: Storage quote');
        $fwd = Threading::forOutbound($contact, Channel::Email, 'Fwd: Re: Storage quote');
        $other = Threading::forOutbound($contact, Channel::Email, 'Different subject');

        $this->assertSame($first['thread']->id, $reuse['thread']->id);
        $this->assertSame($first['thread']->id, $fwd['thread']->id);
        $this->assertNotSame($first['thread']->id, $other['thread']->id);
        $this->assertSame('subject', $first['evidence']['strategy']);
        $this->assertSame('Storage quote', $reuse['evidence']['normalized_subject']);

        $this->assertSame(2, MessageThread::query()->where('channel', Channel::Email)->count());

        $smsA = Threading::forOutbound($contact, Channel::Sms, '+15551234567');
        $smsB = Threading::forOutbound($contact, Channel::Sms, '+15551234567');
        $smsOther = Threading::forOutbound($contact, Channel::Sms, '+15559876543');

        $this->assertSame($smsA['thread']->id, $smsB['thread']->id);
        $this->assertNotSame($smsA['thread']->id, $smsOther['thread']->id);
        $this->assertSame('+15551234567', $smsA['evidence']['channel_key']);

        // Unique index is live for SMS channel_key pairs.
        $raceNumber = '+15550001111';
        MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Sms,
            'subject' => null,
            'channel_key' => $raceNumber,
            'last_message_at' => now()->subMinute(),
            'unread_count' => 0,
        ]);

        try {
            // Nested transaction = SAVEPOINT so the aborted insert does not
            // poison the outer RefreshDatabase transaction on pgsql.
            DB::transaction(function () use ($contact, $raceNumber): void {
                MessageThread::query()->create([
                    'contact_id' => $contact->id,
                    'channel' => Channel::Sms,
                    'subject' => null,
                    'channel_key' => $raceNumber,
                    'last_message_at' => now(),
                    'unread_count' => 0,
                ]);
            });
            $this->fail('Expected unique constraint on SMS channel_key');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        $recovered = Threading::forOutbound($contact, Channel::Sms, $raceNumber);
        $this->assertSame(1, MessageThread::query()
            ->where('contact_id', $contact->id)
            ->where('channel', Channel::Sms)
            ->where('channel_key', $raceNumber)
            ->count());
        $this->assertSame($raceNumber, $recovered['thread']->channel_key);

        // Peer row inserted before forOutbound create — find path returns it.
        $contactB = Contact::factory()->create();
        $numberB = '+15552223333';

        DB::table('message_threads')->insert([
            'contact_id' => $contactB->id,
            'channel' => 'sms',
            'subject' => null,
            'channel_key' => $numberB,
            'last_message_at' => now()->toIso8601String(),
            'unread_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $peerId = (int) DB::table('message_threads')
            ->where('contact_id', $contactB->id)
            ->where('channel_key', $numberB)
            ->value('id');

        $resolved = Threading::forOutbound($contactB, Channel::Sms, $numberB);
        $this->assertSame($peerId, $resolved['thread']->id);
    }

    public function test_normalize_subject_strips_prefixes(): void
    {
        $this->assertSame('Hello', Threading::normalizeSubject('Re: Hello'));
        $this->assertSame('Hello', Threading::normalizeSubject('RE: FW: Hello'));
        $this->assertSame('Hello', Threading::normalizeSubject('Fwd: Re: Hello'));
        $this->assertSame('Hello', Threading::normalizeSubject('  Aw: Hello  '));
    }
}
