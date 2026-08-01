<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\Providers\BrevoAdapter;
use App\Support\Communications\Providers\PostmarkAdapter;
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
}
