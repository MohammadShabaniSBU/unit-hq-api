<?php

declare(strict_types=1);

namespace App\Support\Advertising\Providers\GoogleAds;

use App\Support\Advertising\AdCampaignStatus;
use App\Support\Advertising\AdHttp;
use App\Support\Advertising\AdPlatformException;
use App\Support\Advertising\Contracts\AdPlatformAccount;
use App\Support\Advertising\Contracts\CapturesClickIds;
use App\Support\Advertising\Contracts\ListsCampaigns;
use App\Support\Advertising\Contracts\ReportsConversions;
use App\Support\Advertising\Contracts\ReportsSpend;
use App\Support\Advertising\Results\AccountInfo;
use App\Support\Advertising\Results\CampaignRow;
use App\Support\Advertising\Results\ConversionPayload;
use App\Support\Advertising\Results\DeliveryResult;
use App\Support\Advertising\Results\SpendRow;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;

/**
 * Google Ads REST adapter. The gRPC client is intentionally not used.
 */
final class GoogleAdsAccount implements AdPlatformAccount, CapturesClickIds, ListsCampaigns, ReportsConversions, ReportsSpend
{
    private ?string $accessToken = null;

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function __construct(private readonly array $credentials) {}

    public static function make(array $credentials): self
    {
        return new self($credentials);
    }

    public static function consentUrl(string $state): string
    {
        self::assertConfigured();

        $query = http_build_query([
            'client_id' => (string) config('advertising.google.client_id'),
            'redirect_uri' => (string) config('advertising.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => (string) config('advertising.google.scope'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return rtrim((string) config('advertising.google.oauth_authorize_url'), '?').'?'.$query;
    }

    /**
     * @return array{refresh_token: string, access_token: string}
     */
    public static function exchangeCode(string $code): array
    {
        self::assertConfigured();

        $response = AdHttp::request('POST', (string) config('advertising.google.oauth_token_url'), form: [
            'code' => $code,
            'client_id' => (string) config('advertising.google.client_id'),
            'client_secret' => (string) config('advertising.google.client_secret'),
            'redirect_uri' => (string) config('advertising.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        $refresh = $response->json('refresh_token');
        $access = $response->json('access_token');

        if (! is_string($refresh) || $refresh === '' || ! is_string($access) || $access === '') {
            throw new AdPlatformException(__('errors.advertising.refresh_token_missing'), false);
        }

        return [
            'refresh_token' => $refresh,
            'access_token' => $access,
        ];
    }

    /**
     * @return list<string>
     */
    public static function listAccessibleCustomers(string $accessToken): array
    {
        self::assertConfigured();

        $url = self::apiBase().'/'.self::apiVersion().'/customers:listAccessibleCustomers';
        $response = AdHttp::request('GET', $url, [
            'Authorization' => 'Bearer '.$accessToken,
            'developer-token' => self::developerToken(),
        ]);

        $names = $response->json('resourceNames');
        if (! is_array($names)) {
            return [];
        }

        $ids = [];

        foreach ($names as $name) {
            if (is_string($name) && preg_match('/^customers\/(\d+)$/', $name, $matches) === 1) {
                $ids[] = $matches[1];
            }
        }

        return $ids;
    }

    public function credentialFields(): array
    {
        return [
            'refresh_token' => ['label' => 'Refresh token', 'secret' => true],
        ];
    }

    public function clickIdKeys(): array
    {
        return ['gclid', 'gbraid', 'wbraid'];
    }

    public function verify(): AccountInfo
    {
        try {
            $rows = $this->search(
                'SELECT customer.currency_code, customer.time_zone, customer.descriptive_name FROM customer'
            );
        } catch (AdPlatformException $exception) {
            throw new AdPlatformException(
                __('errors.advertising.verify_failed'),
                $exception->retryable,
                $exception->status,
                'external_account_id',
            );
        }

        $customer = $rows[0]['customer'] ?? null;

        if (! is_array($customer)) {
            throw new AdPlatformException(
                __('errors.advertising.verify_failed'),
                false,
                field: 'external_account_id',
            );
        }

        $currency = $customer['currencyCode'] ?? null;
        $timezone = $customer['timeZone'] ?? null;
        $name = $customer['descriptiveName'] ?? null;

        if (! is_string($currency) || strlen($currency) !== 3 || ! is_string($timezone) || $timezone === '') {
            throw new AdPlatformException(
                __('errors.advertising.verify_failed'),
                false,
                field: 'external_account_id',
            );
        }

        return new AccountInfo(
            currency: strtoupper($currency),
            timezone: $timezone,
            name: is_string($name) && $name !== '' ? $name : null,
        );
    }

    public function campaigns(): array
    {
        $rows = $this->search(
            'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type FROM campaign'
        );

        $campaigns = [];

        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? null;
            if (! is_array($campaign)) {
                continue;
            }

            $id = self::stringValue($campaign['id'] ?? null);
            $name = self::stringValue($campaign['name'] ?? null);

            if ($id === '' || $name === '') {
                continue;
            }

            $channel = self::stringValue($campaign['advertisingChannelType'] ?? null);

            $campaigns[] = new CampaignRow(
                externalId: $id,
                name: $name,
                status: AdCampaignStatus::fromPlatform(self::stringValue($campaign['status'] ?? null)),
                channelType: $channel === '' ? null : strtolower($channel),
            );
        }

        return $campaigns;
    }

    public function spend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $currency = $this->accountCurrency();
        $start = self::civilDate($from);
        $end = self::civilDate($to);
        $query = 'SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks'
            .' FROM campaign WHERE segments.date BETWEEN \''.$start.'\' AND \''.$end.'\'';

        $spend = [];

        foreach ($this->search($query) as $row) {
            $campaign = $row['campaign'] ?? null;
            $segments = $row['segments'] ?? null;
            $metrics = $row['metrics'] ?? null;

            if (! is_array($campaign) || ! is_array($segments) || ! is_array($metrics)) {
                continue;
            }

            $id = self::stringValue($campaign['id'] ?? null);
            $date = self::stringValue($segments['date'] ?? null);

            if ($id === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $spend[] = new SpendRow(
                externalCampaignId: $id,
                spendDate: $date,
                cost: self::microsToCost(self::stringValue($metrics['costMicros'] ?? '0')),
                impressions: self::whole($metrics['impressions'] ?? 0),
                clicks: self::whole($metrics['clicks'] ?? 0),
                currency: $currency,
            );
        }

        return $spend;
    }

    public function deliver(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        $conversions = [];

        foreach ($payloads as $payload) {
            $row = [
                'conversionAction' => $payload->externalRef,
                'orderId' => $payload->dedupeId,
            ];

            if ($payload->gclid !== null) {
                $row['gclid'] = $payload->gclid;
            }

            if ($payload->gbraid !== null) {
                $row['gbraid'] = $payload->gbraid;
            }

            if ($payload->wbraid !== null) {
                $row['wbraid'] = $payload->wbraid;
            }

            if ($payload->conversionDateTime !== null) {
                $row['conversionDateTime'] = $payload->conversionDateTime;
            }

            if ($payload->value !== null && $payload->currency !== null) {
                $row['conversionValue'] = $payload->value;
                $row['currencyCode'] = $payload->currency;
            }

            if ($payload->userIdentifiers !== []) {
                $row['userIdentifiers'] = $payload->userIdentifiers;
            }

            if ($payload->adUserData !== null) {
                $row['consent'] = [
                    'adUserData' => $payload->adUserData,
                    'adPersonalization' => $payload->adPersonalization ?? 'DENIED',
                ];
            }

            $conversions[] = $row;
        }

        $response = AdHttp::request(
            'POST',
            $this->customerUrl(':uploadClickConversions'),
            $this->adsHeaders(),
            [
                'conversions' => $conversions,
                'partialFailure' => true,
            ],
        );

        $requestId = $response->header('request-id');
        $requestId = $requestId !== '' ? $requestId : null;

        return array_map(
            fn (ConversionPayload $payload): DeliveryResult => new DeliveryResult(
                dedupeId: $payload->dedupeId,
                ok: true,
                retryable: false,
                providerRequestId: $requestId,
            ),
            $payloads,
        );
    }

    public static function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'redirect_uri', 'developer_token'] as $key) {
            if ((string) config('advertising.google.'.$key) === '') {
                throw new AdPlatformException(__('errors.advertising.google_not_configured'), false);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $query): array
    {
        $response = AdHttp::request(
            'POST',
            $this->customerUrl('/googleAds:searchStream'),
            $this->adsHeaders(),
            ['query' => $query],
        );

        return self::streamResults($response->json());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function streamResults(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $batches = array_is_list($body) ? $body : [$body];
        $results = [];

        foreach ($batches as $batch) {
            if (! is_array($batch)) {
                continue;
            }

            $rows = $batch['results'] ?? [];

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $results[] = $row;
                }
            }
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function adsHeaders(): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->accessToken(),
            'developer-token' => self::developerToken(),
        ];

        $login = self::digits((string) ($this->credentials['login_customer_id'] ?? ''));

        if ($login !== '') {
            $headers['login-customer-id'] = $login;
        }

        return $headers;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        self::assertConfigured();

        $refresh = (string) ($this->credentials['refresh_token'] ?? '');

        if ($refresh === '') {
            throw new AdPlatformException(
                __('errors.advertising.verify_failed'),
                false,
                field: 'credentials.refresh_token',
            );
        }

        $response = AdHttp::request('POST', (string) config('advertising.google.oauth_token_url'), form: [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => (string) config('advertising.google.client_id'),
            'client_secret' => (string) config('advertising.google.client_secret'),
        ]);

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new AdPlatformException(__('errors.advertising.verify_failed'), false);
        }

        return $this->accessToken = $token;
    }

    private function customerUrl(string $suffix): string
    {
        return self::apiBase().'/'.self::apiVersion().'/customers/'.$this->customerId().$suffix;
    }

    private function customerId(): string
    {
        $id = self::digits((string) ($this->credentials['external_account_id'] ?? ''));

        if ($id === '') {
            throw new AdPlatformException(
                __('errors.advertising.verify_failed'),
                false,
                field: 'external_account_id',
            );
        }

        return $id;
    }

    private function accountCurrency(): string
    {
        $currency = strtoupper((string) ($this->credentials['currency'] ?? ''));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new AdPlatformException(__('errors.advertising.currency_missing'), false);
        }

        return $currency;
    }

    private static function apiBase(): string
    {
        return rtrim((string) config('advertising.google.api_base'), '/');
    }

    private static function apiVersion(): string
    {
        $version = (string) config('advertising.google.api_version');

        if (preg_match('/^v\d+$/', $version) !== 1) {
            throw new AdPlatformException(__('errors.advertising.google_not_configured'), false);
        }

        return $version;
    }

    private static function developerToken(): string
    {
        $token = (string) config('advertising.google.developer_token');

        if ($token === '') {
            throw new AdPlatformException(__('errors.advertising.google_not_configured'), false);
        }

        return $token;
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function civilDate(CarbonImmutable $date): string
    {
        $formatted = $date->toDateString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $formatted) !== 1) {
            throw new AdPlatformException(__('errors.advertising.verify_failed'), false);
        }

        return $formatted;
    }

    private static function microsToCost(string $micros): string
    {
        if (preg_match('/^-?\d+$/', $micros) !== 1) {
            throw new AdPlatformException(__('errors.advertising.spend_invalid'), false);
        }

        return BillingMath::round2(bcdiv($micros, '1000000', 8));
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function whole(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }
}
