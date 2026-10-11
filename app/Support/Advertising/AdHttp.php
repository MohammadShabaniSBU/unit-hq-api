<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared REST client for ad platforms. Retries 429 and 5xx with linear
 * backoff. Other 4xx fail immediately. The exception message never includes
 * the response body, which can echo a token.
 */
final class AdHttp
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>|null  $query
     * @param  array<string, string>|null  $form
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?array $query = null,
        ?array $form = null,
    ): Response {
        $attempts = max(1, (int) config('advertising.http.retry', 3));
        $sleepMs = (int) config('advertising.http.retry_sleep_ms', 200);
        $timeout = (int) config('advertising.http.timeout', 15);

        $httpMethod = strtoupper($method);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $pending = Http::withHeaders($headers)->timeout($timeout);

                if ($query !== null) {
                    $pending = $pending->withQueryParameters($query);
                }

                $response = match (true) {
                    $form !== null => $pending->asForm()->post($url, $form),
                    $json !== null && $httpMethod === 'POST' => $pending->post($url, $json),
                    $httpMethod === 'GET' => $pending->get($url),
                    default => $pending->send($httpMethod, $url),
                };
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    self::pause($sleepMs * $attempt);

                    continue;
                }

                throw new AdPlatformException('Ad platform request failed.', true);
            } catch (Throwable) {
                throw new AdPlatformException('Ad platform request failed.', false);
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $retryable = $status === 429 || $status >= 500;

            if ($retryable && $attempt < $attempts) {
                self::pause($sleepMs * $attempt);

                continue;
            }

            throw new AdPlatformException('Ad platform request failed.', $retryable, $status);
        }

        throw new AdPlatformException('Ad platform request failed.', true);
    }

    private static function pause(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
