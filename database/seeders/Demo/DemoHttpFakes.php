<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Provider HTTP fakes shared by DemoPipeline and persona mini-clocks.
 */
final class DemoHttpFakes
{
    public static function install(): void
    {
        $brevo = 0;
        $twilio = 0;
        $sinch = 0;

        Http::fake([
            'api.brevo.com/*' => static function () use (&$brevo) {
                $brevo++;

                return Http::response([
                    'messageId' => '<demo-brevo-'.$brevo.'.'.Str::uuid().'@unit-hq.test>',
                ], 201);
            },
            'api.twilio.com/*' => static function () use (&$twilio) {
                $twilio++;

                return Http::response([
                    'sid' => 'SM'.str_pad((string) $twilio, 32, '0', STR_PAD_LEFT),
                ], 201);
            },
            'us.conversation.api.sinch.com/*' => static function () use (&$sinch) {
                $sinch++;

                return Http::response([
                    'message_id' => '01WA-DEMO-'.str_pad((string) $sinch, 8, '0', STR_PAD_LEFT),
                ], 200);
            },
            'eu.conversation.api.sinch.com/*' => static function () use (&$sinch) {
                $sinch++;

                return Http::response([
                    'message_id' => '01WA-DEMO-'.str_pad((string) $sinch, 8, '0', STR_PAD_LEFT),
                ], 200);
            },
            'api.aircall.io/*' => Http::response(['ok' => true, 'call_id' => 1], 200),
        ]);
    }
}
