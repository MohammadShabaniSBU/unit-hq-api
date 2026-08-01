<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\MessageDirection;
use App\Support\Communications\Providers\AircallAdapter;
use App\Support\Communications\Providers\BrevoAdapter;
use App\Support\Communications\Providers\PostmarkAdapter;
use App\Support\Communications\Providers\SinchAdapter;
use App\Support\Communications\Providers\TwilioSmsAdapter;
use App\Support\Communications\Results\DeliveryEventId;
use App\Support\Communications\Results\DeliveryStatus;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AdapterParseTest extends TestCase
{
    public function test_brevo_fixtures(): void
    {
        $adapter = BrevoAdapter::make(['api_key' => 'key']);

        $delivered = $adapter->parseDeliveryEvents($this->fixture('brevo_delivered.json'));
        $this->assertCount(1, $delivered);
        $this->assertSame(DeliveryStatus::Delivered, $delivered[0]->status);
        $this->assertSame('123456789', $delivered[0]->providerEventId);
        $this->assertFalse($delivered[0]->isPermanent);
        $this->assertSame('<brevo-msg-delivered@smtp-relay.mailin.fr>', $delivered[0]->providerMessageId);

        $hard = $adapter->parseDeliveryEvents($this->fixture('brevo_hard_bounce.json'));
        $this->assertSame(DeliveryStatus::Bounced, $hard[0]->status);
        $this->assertTrue($hard[0]->isPermanent);
        $this->assertSame('123456790', $hard[0]->providerEventId);

        $soft = $adapter->parseDeliveryEvents($this->fixture('brevo_soft_bounce.json'));
        $this->assertSame(DeliveryStatus::Bounced, $soft[0]->status);
        $this->assertFalse($soft[0]->isPermanent);
        $this->assertSame(
            DeliveryEventId::derive(
                '<brevo-msg-soft@smtp-relay.mailin.fr>',
                'softBounce',
                CarbonImmutable::parse('2026-08-02T10:17:00+00:00'),
            ),
            $soft[0]->providerEventId,
        );
    }

    public function test_postmark_fixtures(): void
    {
        $adapter = PostmarkAdapter::make(['server_token' => 'token']);

        $delivery = $adapter->parseDeliveryEvents($this->fixture('postmark_delivery.json'));
        $this->assertCount(1, $delivery);
        $this->assertSame(DeliveryStatus::Delivered, $delivery[0]->status);
        $this->assertFalse($delivery[0]->isPermanent);
        $this->assertSame(
            DeliveryEventId::derive(
                '00000000-0000-0000-0000-000000000003',
                'Delivery',
                CarbonImmutable::parse('2026-08-02T10:22:00Z'),
            ),
            $delivery[0]->providerEventId,
        );

        $bounce = $adapter->parseDeliveryEvents($this->fixture('postmark_bounce.json'));
        $this->assertSame(DeliveryStatus::Bounced, $bounce[0]->status);
        $this->assertTrue($bounce[0]->isPermanent);
        $this->assertSame('postmark:4321:Bounce', $bounce[0]->providerEventId);

        $spam = $adapter->parseDeliveryEvents($this->fixture('postmark_spam.json'));
        $this->assertSame(DeliveryStatus::Spam, $spam[0]->status);
        $this->assertTrue($spam[0]->isPermanent);
        $this->assertSame('postmark:4322:SpamComplaint', $spam[0]->providerEventId);
    }

    public function test_twilio_fixtures(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-02T10:30:00Z'));

        $adapter = TwilioSmsAdapter::make([
            'account_sid' => 'ACxxx',
            'auth_token' => 'tok',
        ]);

        $delivered = $adapter->parseDeliveryEvents($this->fixture('twilio_delivered.json'));
        $this->assertCount(1, $delivered);
        $this->assertSame(DeliveryStatus::Delivered, $delivered[0]->status);
        $this->assertFalse($delivered[0]->isPermanent);
        $this->assertSame(
            DeliveryEventId::derive(
                'SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'delivered',
                CarbonImmutable::parse('2026-08-02T10:30:00Z'),
            ),
            $delivered[0]->providerEventId,
        );

        $undelivered = $adapter->parseDeliveryEvents($this->fixture('twilio_undelivered.json'));
        $this->assertSame(DeliveryStatus::Failed, $undelivered[0]->status);
        $this->assertTrue($undelivered[0]->isPermanent);
        $this->assertSame('30005', (string) ($undelivered[0]->raw['ErrorCode'] ?? ''));

        CarbonImmutable::setTestNow();
    }

    public function test_sinch_fixtures(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-02T10:40:00Z'));

        $adapter = SinchAdapter::make([
            'service_plan_id' => 'plan',
            'api_token' => 'tok',
            'region' => 'us',
        ]);

        $delivered = $adapter->parseDeliveryEvents($this->fixture('sinch_delivered.json'));
        $this->assertCount(1, $delivered);
        $this->assertSame(DeliveryStatus::Delivered, $delivered[0]->status);
        $this->assertSame('01FC66621XXXXX', $delivered[0]->providerMessageId);
        $this->assertFalse($delivered[0]->isPermanent);
        $this->assertSame(
            DeliveryEventId::derive(
                '01FC66621XXXXX',
                'Delivered',
                CarbonImmutable::parse('2026-08-02T10:40:00.000Z'),
            ),
            $delivered[0]->providerEventId,
        );

        $failed = $adapter->parseDeliveryEvents($this->fixture('sinch_failed.json'));
        $this->assertSame(DeliveryStatus::Failed, $failed[0]->status);
        $this->assertTrue($failed[0]->isPermanent);

        $this->assertNull($adapter->parseInbound($this->fixture('sinch_delivered.json')));
        $this->assertSame([], $adapter->parseDeliveryEvents($this->inboundFixture('sinch_mo.json')));

        $inbound = $adapter->parseInbound($this->inboundFixture('sinch_mo.json'));
        $this->assertNotNull($inbound);
        $this->assertSame('01FC66621MO0001', $inbound->providerMessageId);
        $this->assertSame('+15551234567', $inbound->from);
        $this->assertSame('Yes, please call me tomorrow.', $inbound->bodyText);

        CarbonImmutable::setTestNow();
    }

    public function test_aircall_fixtures(): void
    {
        $adapter = AircallAdapter::make([
            'api_id' => 'id',
            'api_token' => 'tok',
        ]);

        $created = $adapter->parseInbound($this->inboundFixture('aircall_call_created.json'));
        $this->assertNotNull($created);
        $this->assertSame('812001', $created->providerMessageId);
        $this->assertSame('812001:call.created', $created->providerEventId);
        $this->assertSame(MessageDirection::Inbound, $created->direction);
        $this->assertFalse($created->shouldCountAsUnread());

        $ended = $adapter->parseInbound($this->inboundFixture('aircall_call_ended.json'));
        $this->assertNotNull($ended);
        $this->assertSame('https://assets.aircall.io/calls/812001/recording.mp3', $ended->sourceRef['recording_url'] ?? null);
        $this->assertFalse($ended->shouldCountAsUnread());

        $missed = $adapter->parseInbound($this->inboundFixture('aircall_call_missed.json'));
        $this->assertNotNull($missed);
        $this->assertTrue($missed->shouldCountAsUnread());
        $this->assertStringContainsString('missed', (string) $missed->bodyText);

        $voicemail = $adapter->parseInbound($this->inboundFixture('aircall_voicemail_left.json'));
        $this->assertNotNull($voicemail);
        $this->assertTrue($voicemail->shouldCountAsUnread());
        $this->assertSame(
            'https://assets.aircall.io/calls/812003/voicemail.mp3',
            $voicemail->sourceRef['voicemail_url'] ?? null,
        );

        $this->assertNull($adapter->parseInbound([
            'event' => 'user.connected',
            'data' => ['id' => 1],
        ]));
    }

    public function test_postmark_inbound_fixture(): void
    {
        $adapter = PostmarkAdapter::make(['server_token' => 'token']);

        $this->assertNull($adapter->parseInbound($this->fixture('postmark_delivery.json')));

        $inbound = $adapter->parseInbound($this->inboundFixture('postmark_inbound_multipart.json'));
        $this->assertNotNull($inbound);
        $this->assertSame('00000000-0000-0000-0000-00000000in01', $inbound->providerMessageId);
        $this->assertSame('renter@example.com', $inbound->from);
        $this->assertCount(1, $inbound->attachments);
        $this->assertSame('lease-notes.txt', $inbound->attachments[0]->filename);
        $this->assertFalse($inbound->autoGenerated);
        $this->assertArrayHasKey('In-Reply-To', $inbound->headers);
    }

    public function test_twilio_inbound_fixture(): void
    {
        $adapter = TwilioSmsAdapter::make([
            'account_sid' => 'ACxxx',
            'auth_token' => 'tok',
        ]);

        $this->assertNull($adapter->parseInbound($this->fixture('twilio_delivered.json')));

        $inbound = $adapter->parseInbound($this->inboundFixture('twilio_inbound_sms.json'));
        $this->assertNotNull($inbound);
        $this->assertSame('SMinbound000000000000000000000001', $inbound->providerMessageId);
        $this->assertSame('+15551234567', $inbound->from);
        $this->assertSame('Yes, please call me tomorrow.', $inbound->bodyText);
        $this->assertSame([], $adapter->parseDeliveryEvents($this->inboundFixture('twilio_inbound_sms.json')));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $path = base_path('tests/fixtures/communications/delivery/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundFixture(string $name): array
    {
        $path = base_path('tests/fixtures/communications/inbound/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
