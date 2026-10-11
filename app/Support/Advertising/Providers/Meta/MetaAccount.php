<?php

declare(strict_types=1);

namespace App\Support\Advertising\Providers\Meta;

use App\Support\Advertising\AdCampaignStatus;
use App\Support\Advertising\AdHttp;
use App\Support\Advertising\AdPlatformException;
use App\Support\Advertising\Contracts\AdPlatformAccount;
use App\Support\Advertising\Contracts\CapturesClickIds;
use App\Support\Advertising\Contracts\ListsCampaigns;
use App\Support\Advertising\Contracts\ListsConversionTargets;
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
 * Meta Marketing API and Conversions API over the Graph REST API.
 */
final class MetaAccount implements AdPlatformAccount, CapturesClickIds, ListsCampaigns, ListsConversionTargets, ReportsConversions, ReportsSpend
{
    private const PAGE_CAP = 50;

    private const ASYNC_POLL_CAP = 30;

    private const ASYNC_RANGE_DAYS = 30;

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function __construct(private readonly array $credentials) {}

    public static function make(array $credentials): self
    {
        return new self($credentials);
    }

    public function credentialFields(): array
    {
        return [
            'system_user_token' => ['label' => 'System user token', 'secret' => true],
            'capi_token' => ['label' => 'Conversions API token', 'secret' => true],
            'pixel_id' => ['label' => 'Pixel id', 'secret' => false],
        ];
    }

    public function clickIdKeys(): array
    {
        return ['fbc', 'fbp'];
    }

    public function verify(): AccountInfo
    {
        $actId = self::digits((string) ($this->credentials['external_account_id'] ?? ''));
        $pixelId = self::digits((string) ($this->credentials['pixel_id'] ?? ''));
        $systemToken = (string) ($this->credentials['system_user_token'] ?? '');
        $capiToken = (string) ($this->credentials['capi_token'] ?? '');

        if ($actId === '' || $systemToken === '') {
            throw new AdPlatformException(
                __('errors.advertising.ad_account_token_rejected'),
                false,
                field: 'credentials.system_user_token',
            );
        }

        if ($pixelId === '' || $capiToken === '') {
            throw new AdPlatformException(
                __('errors.advertising.pixel_token_rejected'),
                false,
                field: 'credentials.capi_token',
            );
        }

        try {
            $account = AdHttp::request('GET', $this->url('act_'.$actId), [
                'Authorization' => 'Bearer '.$systemToken,
            ], query: [
                'fields' => 'currency,timezone_name,account_status',
            ]);
        } catch (AdPlatformException $exception) {
            throw new AdPlatformException(
                __('errors.advertising.ad_account_token_rejected'),
                $exception->retryable,
                $exception->status,
                'credentials.system_user_token',
            );
        }

        try {
            AdHttp::request('GET', $this->url($pixelId), [
                'Authorization' => 'Bearer '.$capiToken,
            ], query: [
                'fields' => 'id,name',
            ]);
        } catch (AdPlatformException $exception) {
            throw new AdPlatformException(
                __('errors.advertising.pixel_token_rejected'),
                $exception->retryable,
                $exception->status,
                'credentials.capi_token',
            );
        }

        $currency = $account->json('currency');
        $timezone = $account->json('timezone_name');

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
        );
    }

    public function campaigns(): array
    {
        $rows = $this->pages('act_'.$this->actId().'/campaigns', $this->systemToken(), [
            'fields' => 'id,name,effective_status,objective',
            'limit' => 100,
        ]);

        $campaigns = [];

        foreach ($rows as $row) {
            $id = self::stringValue($row['id'] ?? null);
            $name = self::stringValue($row['name'] ?? null);

            if ($id === '' || $name === '') {
                continue;
            }

            $objective = self::stringValue($row['objective'] ?? null);

            $campaigns[] = new CampaignRow(
                externalId: $id,
                name: $name,
                status: AdCampaignStatus::fromPlatform(self::stringValue($row['effective_status'] ?? null)),
                channelType: $objective === '' ? null : $objective,
            );
        }

        return $campaigns;
    }

    public function reportedCurrency(): string
    {
        $response = AdHttp::request('GET', $this->url('act_'.$this->actId()), [
            'Authorization' => 'Bearer '.$this->systemToken(),
        ], query: [
            'fields' => 'currency',
        ]);

        $currency = self::currencyCode($response->json('currency'));

        if ($currency === null) {
            throw new AdPlatformException(__('errors.advertising.currency_missing'), false);
        }

        return $currency;
    }

    public function spend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fallback = $this->reportedCurrency();
        $query = $this->insightQuery($from, $to);
        $inclusive = BillingMath::daysBetween($from->startOfDay(), $to->startOfDay()) + 1;
        $rows = $inclusive > self::ASYNC_RANGE_DAYS
            ? $this->asyncInsights($query)
            : $this->pages('act_'.$this->actId().'/insights', $this->systemToken(), $query);

        $spend = [];

        foreach ($rows as $row) {
            $id = self::stringValue($row['campaign_id'] ?? null);
            $date = self::stringValue($row['date_start'] ?? null);

            if ($id === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $spend[] = new SpendRow(
                externalCampaignId: $id,
                spendDate: $date,
                cost: self::decimalCost($row['spend'] ?? '0'),
                impressions: self::whole($row['impressions'] ?? 0),
                clicks: self::whole($row['clicks'] ?? 0),
                currency: self::currencyCode($row['account_currency'] ?? null) ?? $fallback,
            );
        }

        return $spend;
    }

    /**
     * @return list<array{ref: string, name: string, kind: string}>
     */
    public function conversionTargets(): array
    {
        return [
            ['ref' => 'Lead', 'name' => 'Lead', 'kind' => 'standard'],
            ['ref' => 'Schedule', 'name' => 'Schedule', 'kind' => 'standard'],
            ['ref' => 'SubmitApplication', 'name' => 'SubmitApplication', 'kind' => 'standard'],
            ['ref' => 'Purchase', 'name' => 'Purchase', 'kind' => 'standard'],
        ];
    }

    public function deliver(array $payloads, bool $validateOnly = false, ?string $testEventCode = null): array
    {
        if ($payloads === []) {
            return [];
        }

        $data = [];

        foreach ($payloads as $payload) {
            $event = [
                'event_name' => $payload->externalRef,
                'event_time' => $payload->eventTime ?? 0,
                'event_id' => $payload->dedupeId,
                'action_source' => 'system_generated',
                'user_data' => $payload->metaUserData,
            ];

            if ($payload->metaCustomData !== null) {
                $event['custom_data'] = $payload->metaCustomData;
            }

            $data[] = $event;
        }

        $query = null;
        if ($testEventCode !== null && $testEventCode !== '') {
            $query = ['test_event_code' => $testEventCode];
        }

        $response = AdHttp::request('POST', $this->url($this->pixelId().'/events'), [
            'Authorization' => 'Bearer '.$this->capiToken(),
        ], [
            'data' => $data,
        ], $query);

        $requestId = $response->header('x-fb-trace-id');
        $requestId = $requestId !== '' ? $requestId : null;
        $received = $response->json('events_received');

        if (is_numeric($received) && (int) $received < count($payloads)) {
            return array_map(
                fn (ConversionPayload $payload): DeliveryResult => new DeliveryResult(
                    dedupeId: $payload->dedupeId,
                    ok: false,
                    retryable: false,
                    error: 'events_rejected',
                    providerRequestId: $requestId,
                ),
                $payloads,
            );
        }

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

    /**
     * @return array<string, mixed>
     */
    private function insightQuery(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'level' => 'campaign',
            'time_increment' => 1,
            'time_range' => json_encode([
                'since' => $from->toDateString(),
                'until' => $to->toDateString(),
            ], JSON_THROW_ON_ERROR),
            'fields' => 'campaign_id,spend,impressions,clicks,account_currency',
            'limit' => 100,
        ];
    }

    /**
     * Graph rejects a synchronous insights read once the range exceeds 30 days.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function asyncInsights(array $query): array
    {
        $response = AdHttp::request('POST', $this->url('act_'.$this->actId().'/insights'), [
            'Authorization' => 'Bearer '.$this->systemToken(),
        ], query: $query);

        $runId = self::digits(self::stringValue($response->json('report_run_id')));

        if ($runId === '') {
            throw new AdPlatformException(__('errors.advertising.spend_invalid'), false);
        }

        for ($attempt = 0; $attempt < self::ASYNC_POLL_CAP; $attempt++) {
            $status = AdHttp::request('GET', $this->url($runId), [
                'Authorization' => 'Bearer '.$this->systemToken(),
            ], query: [
                'fields' => 'async_status,async_percent_completion',
            ]);

            $async = $status->json('async_status');

            if ($async === 'Job Completed') {
                return $this->pages($runId.'/insights', $this->systemToken(), ['limit' => 100]);
            }

            if (in_array($async, ['Job Failed', 'Job Skipped'], true)) {
                throw new AdPlatformException(__('errors.advertising.spend_invalid'), false);
            }

            if ($attempt < self::ASYNC_POLL_CAP - 1) {
                usleep(2_000_000);
            }
        }

        throw new AdPlatformException(__('errors.advertising.spend_invalid'), true);
    }

    /**
     * Follow Graph cursors. The absolute paging.next URL is ignored because
     * it embeds the access token.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, string $token, array $query): array
    {
        $rows = [];
        $after = null;

        for ($page = 0; $page < self::PAGE_CAP; $page++) {
            if ($after !== null) {
                $query['after'] = $after;
            }

            $response = AdHttp::request('GET', $this->url($path), [
                'Authorization' => 'Bearer '.$token,
            ], query: $query);

            $data = $response->json('data');

            if (is_array($data)) {
                foreach ($data as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }

            $cursor = $response->json('paging.cursors.after');
            $next = $response->json('paging.next');

            if (! is_string($cursor) || $cursor === '' || $cursor === $after || ! is_string($next) || $next === '') {
                break;
            }

            $after = $cursor;
        }

        return $rows;
    }

    private function url(string $path): string
    {
        return self::apiBase().'/'.self::apiVersion().'/'.ltrim($path, '/');
    }

    private function actId(): string
    {
        $id = self::digits((string) ($this->credentials['external_account_id'] ?? ''));

        if ($id === '') {
            throw new AdPlatformException(__('errors.advertising.verify_failed'), false, field: 'external_account_id');
        }

        return $id;
    }

    private function pixelId(): string
    {
        $id = self::digits((string) ($this->credentials['pixel_id'] ?? ''));

        if ($id === '') {
            throw new AdPlatformException(
                __('errors.advertising.pixel_token_rejected'),
                false,
                field: 'credentials.pixel_id',
            );
        }

        return $id;
    }

    private function systemToken(): string
    {
        $token = (string) ($this->credentials['system_user_token'] ?? '');

        if ($token === '') {
            throw new AdPlatformException(
                __('errors.advertising.ad_account_token_rejected'),
                false,
                field: 'credentials.system_user_token',
            );
        }

        return $token;
    }

    private function capiToken(): string
    {
        $token = (string) ($this->credentials['capi_token'] ?? '');

        if ($token === '') {
            throw new AdPlatformException(
                __('errors.advertising.pixel_token_rejected'),
                false,
                field: 'credentials.capi_token',
            );
        }

        return $token;
    }

    private static function apiBase(): string
    {
        return rtrim((string) config('advertising.meta.api_base'), '/');
    }

    private static function apiVersion(): string
    {
        $version = (string) config('advertising.meta.api_version');

        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            throw new AdPlatformException(__('errors.advertising.verify_failed'), false);
        }

        return $version;
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function currencyCode(mixed $value): ?string
    {
        $string = self::stringValue($value);

        if (preg_match('/^[A-Za-z]{3}$/', $string) !== 1) {
            return null;
        }

        return strtoupper($string);
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

    private static function decimalCost(mixed $value): string
    {
        $string = is_int($value) ? (string) $value : (is_string($value) ? $value : '');

        if (preg_match('/^-?\d+(\.\d+)?$/', $string) !== 1) {
            throw new AdPlatformException(__('errors.advertising.spend_invalid'), false);
        }

        return BillingMath::round2($string);
    }
}
