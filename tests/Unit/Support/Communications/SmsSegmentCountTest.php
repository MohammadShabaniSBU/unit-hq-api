<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\Messages\SmsMessage;
use Tests\TestCase;

class SmsSegmentCountTest extends TestCase
{
    public function test_gsm7_single_and_concatenated_boundaries(): void
    {
        $this->assertSame(1, (new SmsMessage('+1', str_repeat('a', 160)))->segmentCount());
        $this->assertSame(2, (new SmsMessage('+1', str_repeat('a', 161)))->segmentCount());
    }

    public function test_ucs2_single_and_concatenated_boundaries(): void
    {
        $this->assertSame(1, (new SmsMessage('+1', str_repeat('あ', 70)))->segmentCount());
        $this->assertSame(2, (new SmsMessage('+1', str_repeat('あ', 71)))->segmentCount());
    }

    public function test_emoji_forces_ucs2_and_inflates_segments(): void
    {
        $withEmoji = 'Hello 👋';
        $this->assertSame(1, (new SmsMessage('+1', $withEmoji))->segmentCount());

        // 70 UCS-2 code points = 1 segment; 71 = 2.
        $long = str_repeat('あ', 70).'👋';
        $this->assertSame(2, (new SmsMessage('+1', $long))->segmentCount());
    }
}
