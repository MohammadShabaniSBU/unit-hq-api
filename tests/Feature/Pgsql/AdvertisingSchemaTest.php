<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\AdConversionDelivery;
use App\Models\Contact;
use App\Models\MarketingTouch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Postgres-only: advertising immutability triggers via SQL.
 */
class AdvertisingSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Advertising immutability triggers are asserted on Postgres.');
        }
    }

    #[Test]
    public function touch_trigger_rejects_utm_source_and_allows_one_stitch(): void
    {
        $touch = MarketingTouch::factory()->create(['contact_id' => null]);
        $contact = Contact::factory()->create();
        $other = Contact::factory()->create();

        $this->assertRejected(function () use ($touch): void {
            DB::table('marketing_touches')->where('id', $touch->id)->update(['utm_source' => 'facebook']);
        }, 'marketing_touch_immutable');

        DB::table('marketing_touches')->where('id', $touch->id)->update([
            'utm_term' => null,
            'click_ids' => json_encode([]),
        ]);

        DB::table('marketing_touches')->where('id', $touch->id)->update([
            'contact_id' => $contact->id,
        ]);

        $this->assertSame($contact->id, (int) DB::table('marketing_touches')->where('id', $touch->id)->value('contact_id'));

        $this->assertRejected(function () use ($touch, $other): void {
            DB::table('marketing_touches')->where('id', $touch->id)->update([
                'contact_id' => $other->id,
            ]);
        }, 'marketing_touch_immutable');

        $this->assertRejected(function () use ($touch): void {
            DB::table('marketing_touches')->where('id', $touch->id)->delete();
        }, 'marketing_touch_immutable');
    }

    #[Test]
    public function delivery_trigger_rejects_a_skip_of_claimed(): void
    {
        $delivery = AdConversionDelivery::factory()->create();

        $this->assertRejected(function () use ($delivery): void {
            DB::table('ad_conversion_deliveries')->where('id', $delivery->id)->update([
                'status' => 'sent',
            ]);
        }, 'ad_conversion_delivery_status');

        DB::table('ad_conversion_deliveries')->where('id', $delivery->id)->update(['status' => 'claimed']);
        DB::table('ad_conversion_deliveries')->where('id', $delivery->id)->update([
            'status' => 'sent',
            'identifiers_used' => json_encode(['gclid']),
        ]);

        $this->assertRejected(function () use ($delivery): void {
            DB::table('ad_conversion_deliveries')->where('id', $delivery->id)->update([
                'identifiers_used' => json_encode(['email_sha256']),
            ]);
        }, 'ad_conversion_delivery_immutable');
    }

    private function assertRejected(callable $write, string $marker): void
    {
        try {
            DB::transaction($write);
            $this->fail('Write must be rejected.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($marker, $exception->getMessage());
        }
    }
}
