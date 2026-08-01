<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Support\Communications\Messages\SmsMessage;
use Tests\TestCase;

class SmsSegmentTest extends TestCase
{
    public function test_gsm7_ucs2(): void
    {
        // € is GSM-7 extension (two septets). 153 septets/segment when concatenated.
        // 152 'a' + € = 154 septets → still 1 segment? 152+2=154 <= 160 → 1 segment.
        $withEuro = str_repeat('a', 152).'€';
        $this->assertSame(1, (new SmsMessage('+15550001111', $withEuro))->segmentCount());

        // 159 'a' + € = 161 septets → 2 segments.
        $twoSeg = str_repeat('a', 159).'€';
        $this->assertSame(2, (new SmsMessage('+15550001111', $twoSeg))->segmentCount());

        // UCS-2: non-GSM character forces 70/67 math.
        $ucs2 = str_repeat('あ', 70);
        $this->assertSame(1, (new SmsMessage('+15550001111', $ucs2))->segmentCount());

        $ucs2Two = str_repeat('あ', 71);
        $this->assertSame(2, (new SmsMessage('+15550001111', $ucs2Two))->segmentCount());
    }
}
