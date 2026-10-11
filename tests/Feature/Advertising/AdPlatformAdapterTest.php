<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\AdPlatformException;
use App\Support\Advertising\AdPlatformRegistry;
use App\Support\Advertising\Providers\GoogleAds\GoogleAdsAccount;
use App\Support\Advertising\Providers\Meta\MetaAccount;
use App\Support\Advertising\Results\ConversionPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdPlatformAdapterTest extends TestCase
{
    #[Test]
    public function google_verify_sends_the_developer_token_and_pinned_version(): void
    {
        $this->fakeGoogleCustomer();

        $info = $this->google()->verify();

        $this->assertSame('EUR', $info->currency);
        $this->assertSame('Europe/Madrid', $info->timezone);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/v21/customers/1234567890/googleAds:searchStream')
                && $request->hasHeader('developer-token', 'dev-token')
                && $request->hasHeader('login-customer-id', '998877');
        });
    }

    #[Test]
    public function google_http_400_is_not_retryable(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29-access']),
            'https://googleads.googleapis.com/*' => Http::response(['error' => 'bad'], 400),
        ]);

        try {
            $this->google()->verify();
            $this->fail('A 400 response must fail verification.');
        } catch (AdPlatformException $exception) {
            $this->assertFalse($exception->retryable);
            $this->assertSame(400, $exception->status);
        }

        $this->assertSame(1, $this->sentCount('googleAds:searchStream'));
    }

    #[Test]
    public function google_http_500_is_retryable(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29-access']),
            'https://googleads.googleapis.com/*' => Http::response(['error' => 'down'], 500),
        ]);

        try {
            $this->google()->verify();
            $this->fail('A 500 response must fail verification.');
        } catch (AdPlatformException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame(500, $exception->status);
        }

        $this->assertSame(3, $this->sentCount('googleAds:searchStream'));
    }

    #[Test]
    public function google_lists_customers_campaigns_spend_and_posts_conversions(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'listAccessibleCustomers')) {
                return Http::response(['resourceNames' => ['customers/1234567890', 'customers/42']]);
            }

            if (str_contains($url, 'uploadClickConversions')) {
                return Http::response(['results' => []], 200, ['request-id' => 'req-1']);
            }

            if (str_contains($url, 'googleAds:searchStream') && str_contains((string) $request->body(), 'metrics.cost_micros')) {
                return Http::response([[
                    'results' => [[
                        'campaign' => ['id' => '11'],
                        'segments' => ['date' => '2026-10-01'],
                        'metrics' => ['costMicros' => '1234567', 'impressions' => '10', 'clicks' => '2'],
                    ], [
                        'campaign' => ['id' => '12'],
                        'segments' => ['date' => '2026-10-02'],
                        'metrics' => ['costMicros' => '1235000', 'impressions' => '1', 'clicks' => '0'],
                    ]],
                ]]);
            }

            if (str_contains($url, 'googleAds:searchStream')) {
                return Http::response([[
                    'results' => [[
                        'campaign' => [
                            'id' => '11',
                            'name' => 'Brand',
                            'status' => 'ENABLED',
                            'advertisingChannelType' => 'SEARCH',
                        ],
                    ], [
                        'campaign' => [
                            'id' => '12',
                            'name' => 'Old',
                            'status' => 'REMOVED',
                            'advertisingChannelType' => 'DISPLAY',
                        ],
                    ]],
                ]]);
            }

            return Http::response(['access_token' => 'ya29-access']);
        });

        $this->assertSame(
            ['1234567890', '42'],
            GoogleAdsAccount::listAccessibleCustomers('ya29-access'),
        );

        $campaigns = iterator_to_array($this->google()->campaigns());
        $this->assertSame('enabled', $campaigns[0]->status->value);
        $this->assertSame('search', $campaigns[0]->channelType);
        $this->assertSame('removed', $campaigns[1]->status->value);

        $spend = iterator_to_array($this->google()->spend(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-02'),
        ));
        $this->assertSame('1.23', $spend[0]->cost);
        $this->assertSame('1.24', $spend[1]->cost);
        $this->assertSame('EUR', $spend[0]->currency);

        $results = $this->google()->deliver([
            new ConversionPayload(
                dedupeId: 'contract:1:move_in',
                externalRef: 'customers/1234567890/conversionActions/9',
                gclid: 'gclid-1',
                conversionDateTime: '2026-10-01 12:00:00+02:00',
            ),
        ]);

        $this->assertTrue($results[0]->ok);
        $this->assertSame('contract:1:move_in', $results[0]->dedupeId);
        $this->assertSame(['gclid', 'gbraid', 'wbraid'], $this->google()->clickIdKeys());

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'uploadClickConversions')) {
                return false;
            }

            $body = $request->data();

            return ($body['partialFailure'] ?? null) === true
                && ($body['conversions'][0]['orderId'] ?? null) === 'contract:1:move_in'
                && str_contains($request->url(), '/v21/');
        });
    }

    #[Test]
    public function meta_verify_requires_both_tokens_and_posts_events(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/events')) {
                return Http::response(['events_received' => 1]);
            }

            if (str_contains($url, '/insights')) {
                return Http::response([
                    'data' => [[
                        'campaign_id' => '77',
                        'spend' => '1.20',
                        'impressions' => '4',
                        'clicks' => '1',
                        'date_start' => '2026-10-03',
                    ]],
                ]);
            }

            if (str_contains($url, '/campaigns')) {
                $query = [];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if (! isset($query['after'])) {
                    return Http::response([
                        'data' => [[
                            'id' => '77',
                            'name' => 'Prospecting',
                            'effective_status' => 'ACTIVE',
                            'objective' => 'OUTCOME_LEADS',
                        ]],
                        'paging' => [
                            'cursors' => ['after' => 'CURSOR'],
                            'next' => 'https://graph.facebook.com/v21.0/act_99/campaigns?access_token=secret-token',
                        ],
                    ]);
                }

                return Http::response([
                    'data' => [[
                        'id' => '78',
                        'name' => 'Paused',
                        'effective_status' => 'PAUSED',
                        'objective' => 'OUTCOME_LEADS',
                    ]],
                ]);
            }

            if (str_contains($url, 'act_99')) {
                return Http::response([
                    'currency' => 'EUR',
                    'timezone_name' => 'Europe/Madrid',
                    'account_status' => 1,
                ]);
            }

            if (str_contains($url, '/555')) {
                return Http::response(['id' => '555', 'name' => 'Pixel']);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $info = $this->meta()->verify();
        $this->assertSame('EUR', $info->currency);
        $this->assertSame('Europe/Madrid', $info->timezone);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/v21.0/act_99')
                && $request->hasHeader('Authorization', 'Bearer system-token');
        });
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/v21.0/555')
                && $request->hasHeader('Authorization', 'Bearer capi-token');
        });

        $campaigns = iterator_to_array($this->meta()->campaigns());
        $this->assertCount(2, $campaigns);
        $this->assertSame('enabled', $campaigns[0]->status->value);
        $this->assertSame('paused', $campaigns[1]->status->value);

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), 'access_token=');
        });

        $spend = iterator_to_array($this->meta()->spend(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-07'),
        ));
        $this->assertSame('1.20', $spend[0]->cost);
        $this->assertSame('EUR', $spend[0]->currency);

        $results = $this->meta()->deliver([
            new ConversionPayload(
                dedupeId: 'contract:1:move_in',
                externalRef: 'Purchase',
                eventTime: 1_700_000_000,
                metaUserData: ['em' => ['abc']],
            ),
        ]);
        $this->assertTrue($results[0]->ok);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/555/events')) {
                return false;
            }

            $event = $request->data()['data'][0] ?? [];

            return ($event['action_source'] ?? null) === 'system_generated'
                && ($event['event_id'] ?? null) === 'contract:1:move_in';
        });

        $this->assertSame(['fbc', 'fbp'], $this->meta()->clickIdKeys());
    }

    #[Test]
    public function meta_pixel_failure_is_a_field_error(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'act_99')) {
                return Http::response([
                    'currency' => 'EUR',
                    'timezone_name' => 'Europe/Madrid',
                    'account_status' => 1,
                ]);
            }

            return Http::response(['error' => 'bad pixel'], 400);
        });

        try {
            $this->meta()->verify();
            $this->fail('A rejected pixel token must fail verification.');
        } catch (AdPlatformException $exception) {
            $this->assertSame('credentials.capi_token', $exception->field);
            $this->assertFalse($exception->retryable);
        }

        $this->assertSame(1, $this->sentCount('act_99'));
    }

    #[Test]
    public function meta_http_500_is_retryable(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['error' => 'down'], 500),
        ]);

        try {
            $this->meta()->verify();
            $this->fail('A 500 response must fail verification.');
        } catch (AdPlatformException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame(500, $exception->status);
            $this->assertSame('credentials.system_user_token', $exception->field);
        }

        $this->assertSame(3, $this->sentCount('act_99'));
    }

    #[Test]
    public function openai_has_no_adapter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AdPlatformRegistry::class)->adapterClass(AdPlatform::OpenAi);
    }

    private function google(): GoogleAdsAccount
    {
        return GoogleAdsAccount::make([
            'refresh_token' => 'refresh-token',
            'external_account_id' => '1234567890',
            'login_customer_id' => '998877',
            'currency' => 'EUR',
        ]);
    }

    private function meta(): MetaAccount
    {
        return MetaAccount::make([
            'external_account_id' => '99',
            'system_user_token' => 'system-token',
            'capi_token' => 'capi-token',
            'pixel_id' => '555',
            'currency' => 'EUR',
        ]);
    }

    private function fakeGoogleCustomer(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29-access']),
            'https://googleads.googleapis.com/*' => Http::response([[
                'results' => [[
                    'customer' => [
                        'currencyCode' => 'EUR',
                        'timeZone' => 'Europe/Madrid',
                        'descriptiveName' => 'Test',
                    ],
                ]],
            ]]),
        ]);
    }

    private function sentCount(string $needle): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $needle))
            ->count();
    }
}
