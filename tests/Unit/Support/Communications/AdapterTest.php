<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\Exceptions\ProviderRequestFailed;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Providers\BrevoAdapter;
use App\Support\Communications\Providers\PostmarkAdapter;
use App\Support\Communications\Providers\TwilioSmsAdapter;
use App\Support\Communications\Results\DeliveryStatus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrevoAdapterTest extends TestCase
{
    public function test_verify_success(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/account' => Http::response(['email' => 'ok@example.com'], 200),
        ]);

        $this->assertTrue(BrevoAdapter::make(['api_key' => 'key'])->verify()->ok);
    }

    public function test_verify_failure(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/account' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $result = BrevoAdapter::make(['api_key' => 'bad'])->verify();

        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
    }

    public function test_send_email_payload_shape(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'msg-1'], 201),
        ]);

        $result = BrevoAdapter::make(['api_key' => 'key'])->sendEmail(new EmailMessage(
            to: [new EmailAddress('to@example.com')],
            subject: 'Hi',
            html: '<p>Hi</p>',
            text: 'Hi',
            from: new EmailAddress('from@example.com', 'From'),
            tags: ['site:1'],
        ));

        $this->assertSame('msg-1', $result->providerMessageId);
        $this->assertSame(0, $result->accountId);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $data['subject'] === 'Hi'
                && $data['tags'] === ['site:1']
                && $data['sender']['email'] === 'from@example.com';
        });
    }

    public function test_send_raises_on_non_2xx(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['message' => 'fail'], 500),
        ]);

        $this->expectException(ProviderRequestFailed::class);

        BrevoAdapter::make(['api_key' => 'key'])->sendEmail(new EmailMessage(
            to: [new EmailAddress('to@example.com')],
            subject: 'Hi',
            html: '<p>Hi</p>',
            text: 'Hi',
            from: new EmailAddress('from@example.com'),
        ));
    }

    public function test_parse_delivery_events_including_unknown(): void
    {
        $adapter = BrevoAdapter::make(['api_key' => 'key']);

        $events = $adapter->parseDeliveryEvents([
            'event' => 'delivered',
            'message-id' => 'm1',
            'email' => 'a@b.c',
        ]);
        $this->assertCount(1, $events);
        $this->assertSame(DeliveryStatus::Delivered, $events[0]->status);
        $this->assertSame('delivered', $events[0]->rawStatus);

        $this->assertSame([], $adapter->parseDeliveryEvents(['event' => 'totally-unknown', 'message-id' => 'm2']));
    }
}

class PostmarkAdapterTest extends TestCase
{
    public function test_verify_and_send_payload(): void
    {
        Http::fake([
            'api.postmarkapp.com/server' => Http::response(['Name' => 'Server'], 200),
            'api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-1'], 200),
        ]);

        $adapter = PostmarkAdapter::make(['server_token' => 'token']);
        $this->assertTrue($adapter->verify()->ok);

        $result = $adapter->sendEmail(new EmailMessage(
            to: [new EmailAddress('to@example.com', 'To')],
            subject: 'Subj',
            html: '<p>x</p>',
            text: 'x',
            from: new EmailAddress('from@example.com', 'From'),
            tags: ['site:9', 'extra'],
        ));

        $this->assertSame('pm-1', $result->providerMessageId);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/email')) {
                return true;
            }
            $data = $request->data();

            return $data['From'] === 'From <from@example.com>'
                && $data['Tag'] === 'site:9'
                && $data['MessageStream'] === 'outbound';
        });
    }

    public function test_delivery_mapping(): void
    {
        $adapter = PostmarkAdapter::make(['server_token' => 't']);
        $events = $adapter->parseDeliveryEvents([
            'RecordType' => 'Bounce',
            'MessageID' => 'm',
            'Description' => 'hard',
        ]);
        $this->assertSame(DeliveryStatus::Bounced, $events[0]->status);
        $this->assertSame([], $adapter->parseDeliveryEvents(['RecordType' => 'Weird']));
    }

    public function test_send_failure(): void
    {
        Http::fake(['api.postmarkapp.com/email' => Http::response([], 422)]);
        $this->expectException(ProviderRequestFailed::class);
        PostmarkAdapter::make(['server_token' => 't'])->sendEmail(new EmailMessage(
            to: [new EmailAddress('to@example.com')],
            subject: 'S',
            html: 'h',
            text: 't',
            from: new EmailAddress('from@example.com'),
        ));
    }
}

class TwilioSmsAdapterTest extends TestCase
{
    public function test_verify_send_and_delivery(): void
    {
        Http::fake([
            'api.twilio.com/2010-04-01/Accounts/ACxxx.json' => Http::response(['sid' => 'ACxxx'], 200),
            'api.twilio.com/2010-04-01/Accounts/ACxxx/Messages.json' => Http::response(['sid' => 'SMxxx'], 201),
        ]);

        $adapter = TwilioSmsAdapter::make([
            'account_sid' => 'ACxxx',
            'auth_token' => 'secret',
            'messaging_service_sid' => 'MGxxx',
        ]);

        $this->assertTrue($adapter->verify()->ok);

        $result = $adapter->sendSms(new SmsMessage('+15551212', 'Hello'));
        $this->assertSame('SMxxx', $result->providerMessageId);
        $this->assertSame(0, $result->accountId);

        $events = $adapter->parseDeliveryEvents([
            'MessageStatus' => 'delivered',
            'MessageSid' => 'SMxxx',
            'To' => '+15551212',
        ]);
        $this->assertSame(DeliveryStatus::Delivered, $events[0]->status);
        $this->assertSame([], $adapter->parseDeliveryEvents(['MessageStatus' => 'unknown-status', 'MessageSid' => 'x']));
    }

    public function test_send_failure(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['message' => 'fail'], 401),
        ]);

        $this->expectException(ProviderRequestFailed::class);

        TwilioSmsAdapter::make([
            'account_sid' => 'ACxxx',
            'auth_token' => 'bad',
        ])->sendSms(new SmsMessage('+1', 'Hi'));
    }
}
