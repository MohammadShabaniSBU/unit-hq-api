<?php

declare(strict_types=1);

namespace App\Support\Http;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Streamed 4xx/5xx bodies are not seekable, so RequestException and Telescope
 * report only "HTTP request returned status code 400". Buffer the bytes so
 * the JSON error (Anthropic's included) is readable.
 */
final class BufferFailedHttpResponse
{
    public function __invoke(ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() < 400) {
            return $response;
        }

        $body = $response->getBody();
        if ($body->isSeekable() && $body->isReadable()) {
            return $response;
        }

        $contents = $body->getContents();
        if ($contents === '') {
            return $response;
        }

        return $response->withBody(Utils::streamFor($contents));
    }
}
