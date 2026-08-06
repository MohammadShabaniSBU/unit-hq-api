<?php

declare(strict_types=1);

namespace App\Support\Insights\Providers;

use App\Models\InsightReport;
use App\Support\Communications\Results\VerificationResult;
use App\Support\Insights\Contracts\AnalyticsProvider;
use App\Support\Insights\Contracts\SignsEmbedTokens;
use App\Support\Insights\Exceptions\EmbedUrlException;
use App\Support\Insights\IframeHostGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class IframeProvider implements AnalyticsProvider, SignsEmbedTokens
{
    private function __construct(
        private readonly string $baseUrl,
    ) {}

    public static function make(array $credentials, string $baseUrl): static
    {
        return new self($baseUrl);
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function verify(): VerificationResult
    {
        try {
            IframeHostGuard::assertAllowed($this->baseUrl);
        } catch (ValidationException $e) {
            $messages = $e->errors();
            $first = $messages['base_url'][0] ?? 'iframe URL template is not allowed.';

            return VerificationResult::failed($first);
        }

        $probeUrl = IframeHostGuard::probeUrl($this->baseUrl);

        try {
            $response = Http::timeout(15)->head($probeUrl);
        } catch (\Throwable) {
            return VerificationResult::failed(
                'Host is unreachable (reachability check only — not authentication).'
            );
        }

        if ($response->successful() || $response->status() === 405) {
            return VerificationResult::ok();
        }

        return VerificationResult::failed(
            'Host returned HTTP '.$response->status().' (reachability check only — not authentication).'
        );
    }

    public function resourceKinds(): array
    {
        return [];
    }

    public function embedUrl(InsightReport $report, array $resolvedParams): string
    {
        $url = $this->baseUrl;

        foreach ($resolvedParams as $name => $value) {
            $placeholder = '{'.$name.'}';
            $url = str_replace($placeholder, self::encodeValue($value), $url);
        }

        if (preg_match('/\{[^}]+\}/', $url, $matches) === 1) {
            throw EmbedUrlException::unfilledPlaceholder($matches[0]);
        }

        IframeHostGuard::assertAllowed($url);

        return $url;
    }

    private static function encodeValue(mixed $value): string
    {
        if (is_array($value)) {
            $flat = array_map(
                static fn (mixed $item): string => is_scalar($item) || $item === null
                    ? (string) $item
                    : json_encode($item, JSON_THROW_ON_ERROR),
                $value
            );

            return rawurlencode(implode(',', $flat));
        }

        if (is_bool($value)) {
            return rawurlencode($value ? 'true' : 'false');
        }

        if ($value === null) {
            return '';
        }

        return rawurlencode((string) $value);
    }
}
