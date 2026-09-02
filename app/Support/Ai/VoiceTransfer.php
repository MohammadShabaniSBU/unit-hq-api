<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Time\SiteClock;
use Carbon\CarbonInterface;

/**
 * Maps a handoff reason to an approved Vocal Bridge destination key.
 * Never emits a phone number. Hours via SiteClock::withinWindow, not now().
 */
final class VoiceTransfer
{
    public const MainLine = 'main_line';

    public const Voicemail = 'voicemail';

    public function resolve(HandoffReason $reason, ?Site $site, ?CarbonInterface $now = null): VoiceTransferResult
    {
        $approved = $this->approvedDestinations();
        if ($approved === []) {
            $this->logUnmapped($reason, null, $site);

            return VoiceTransferResult::failClosed();
        }

        $key = $this->mappedKey($reason);

        if ($site !== null && $this->outsideHours($site, $now)) {
            $ooh = config('agents.voice.outside_hours_destination');
            $key = is_string($ooh) && $ooh !== '' ? $ooh : $key;
        }

        if ($key !== null && in_array($key, $approved, true)) {
            return VoiceTransferResult::to($key);
        }

        $this->logUnmapped($reason, $key, $site);

        if (in_array(self::MainLine, $approved, true)) {
            return VoiceTransferResult::to(self::MainLine);
        }

        return VoiceTransferResult::failClosed();
    }

    public function cannedText(HandoffReason $reason): string
    {
        return match ($reason) {
            HandoffReason::OutOfHours => (string) config('agents.voice.voicemail_sentence'),
            default => (string) config('agents.voice.handoff_sentence'),
        };
    }

    public function apology(): string
    {
        return (string) config('agents.voice.apology_sentence');
    }

    public function handoffSentence(): string
    {
        return (string) config('agents.voice.handoff_sentence');
    }

    /**
     * @return list<string>
     */
    private function approvedDestinations(): array
    {
        $raw = config('agents.voice.approved_destinations');
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $keys = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $keys[] = $value;
            }
        }

        return $keys;
    }

    private function mappedKey(HandoffReason $reason): ?string
    {
        $map = config('agents.voice.reason_destinations');
        if (! is_array($map)) {
            return null;
        }

        $mapped = $map[$reason->value] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : null;
    }

    private function outsideHours(Site $site, ?CarbonInterface $now): bool
    {
        $settings = Setting::general();

        return ! SiteClock::withinWindow($site, $settings->sendWindowStart, $settings->sendWindowEnd, $now);
    }

    private function logUnmapped(HandoffReason $reason, ?string $key, ?Site $site): void
    {
        SystemEvent::record('ai.voice.transfer_unmapped', $site, [
            'reason' => $reason->value,
            'key' => $key,
        ]);
    }
}
