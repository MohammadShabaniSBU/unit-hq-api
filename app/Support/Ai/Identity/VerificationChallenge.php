<?php

declare(strict_types=1);

namespace App\Support\Ai\Identity;

use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\ContactVerification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class VerificationChallenge
{
    /**
     * @return array{ok: true, row: ContactVerification, code: string}|array{ok: false, reason: 'rate_limited'}
     */
    public static function issue(
        Contact $contact,
        ContactChannel $channel,
        ?int $conversationId,
        ?int $siteId,
    ): array {
        return DB::transaction(function () use ($contact, $channel, $conversationId, $siteId): array {
            Contact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();

            $maxPerHour = self::maxIssuedPerHour();
            $issuedLastHour = ContactVerification::query()
                ->where('contact_id', $contact->id)
                ->where('created_at', '>', now()->subHour())
                ->count();
            if ($issuedLastHour >= $maxPerHour) {
                return ['ok' => false, 'reason' => 'rate_limited'];
            }

            self::closeOpen($contact->id);

            $length = self::codeLength();
            $max = (10 ** $length) - 1;
            $code = str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);

            $row = ContactVerification::query()->create([
                'contact_id' => $contact->id,
                'agent_conversation_id' => $conversationId,
                'contact_channel_id' => $channel->id,
                'site_id' => $siteId,
                'code_hash' => self::hash($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::ttlMinutes()),
                'consumed_at' => null,
                'created_at' => now(),
            ]);

            return ['ok' => true, 'row' => $row, 'code' => $code];
        });
    }

    /**
     * @return array{ok: true, row: ContactVerification}|array{ok: false, reason: 'invalid'}
     */
    public static function verify(Contact $contact, string $code): array
    {
        return DB::transaction(function () use ($contact, $code): array {
            Contact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();

            $open = self::openQuery($contact->id)->orderByDesc('id')->first();
            if ($open === null) {
                return ['ok' => false, 'reason' => 'invalid'];
            }

            if (! hash_equals($open->code_hash, self::hash($code))) {
                $open->attempts++;
                $open->save();

                return ['ok' => false, 'reason' => 'invalid'];
            }

            $open->consumed_at = now();
            $open->save();

            return ['ok' => true, 'row' => $open];
        });
    }

    public static function close(ContactVerification $row): void
    {
        if ($row->consumed_at !== null) {
            return;
        }

        $row->consumed_at = now();
        $row->save();
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function maxAttempts(): int
    {
        return (int) config('agents.verification.max_attempts', 5);
    }

    private static function closeOpen(int $contactId): void
    {
        self::openQuery($contactId)->update(['consumed_at' => now()]);
    }

    /**
     * Open = not consumed, not expired, attempts remaining. Expiry and
     * exhaustion are read-time predicates (invariant 13) — no sweeper.
     *
     * @return Builder<ContactVerification>
     */
    private static function openQuery(int $contactId)
    {
        return ContactVerification::query()
            ->where('contact_id', $contactId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::maxAttempts());
    }

    private static function ttlMinutes(): int
    {
        return (int) config('agents.verification.ttl_minutes', 10);
    }

    private static function codeLength(): int
    {
        return (int) config('agents.verification.code_length', 6);
    }

    private static function maxIssuedPerHour(): int
    {
        return (int) config('agents.verification.max_issued_per_hour', 3);
    }
}
