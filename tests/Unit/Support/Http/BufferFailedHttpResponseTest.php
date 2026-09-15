<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Http;

use App\Support\Http\BufferFailedHttpResponse;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BufferFailedHttpResponseTest extends TestCase
{
    #[Test]
    public function successful_stream_is_left_alone(): void
    {
        $payload = 'event: message_start\n';
        $stream = $this->oneShotStream($payload);
        $response = new Response(200, [], $stream);

        $result = (new BufferFailedHttpResponse)($response);

        $this->assertSame($response, $result);
        $this->assertFalse($result->getBody()->isSeekable());
    }

    #[Test]
    public function failed_stream_is_buffered_so_the_body_can_be_reread(): void
    {
        $payload = '{"error":{"type":"invalid_request_error","message":"tool_use ids and tool_result blocks must match"}}';
        $response = new Response(400, ['Content-Type' => 'application/json'], $this->oneShotStream($payload));

        $result = (new BufferFailedHttpResponse)($response);

        $this->assertNotSame($response, $result);
        $this->assertTrue($result->getBody()->isSeekable());
        $this->assertSame($payload, $result->getBody()->getContents());
        $result->getBody()->rewind();
        $this->assertSame($payload, $result->getBody()->getContents());
    }

    #[Test]
    public function already_seekable_error_body_is_left_alone(): void
    {
        $payload = '{"error":{"message":"already buffered"}}';
        $response = new Response(400, [], Utils::streamFor($payload));

        $result = (new BufferFailedHttpResponse)($response);

        $this->assertSame($response, $result);
        $this->assertSame($payload, (string) $result->getBody());
    }

    private function oneShotStream(string $payload): PumpStream
    {
        $sent = false;

        return new PumpStream(function () use (&$sent, $payload): string|false {
            if ($sent) {
                return false;
            }

            $sent = true;

            return $payload;
        });
    }
}
