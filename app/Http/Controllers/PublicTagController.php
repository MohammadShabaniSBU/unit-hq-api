<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-party ad tag. Public static script. No credentials.
 */
class PublicTagController extends Controller
{
    public function show(Request $request): Response
    {
        $path = resource_path('js/tag/keevaris-tag.js');
        if (! is_file($path)) {
            abort(404);
        }

        $body = (string) file_get_contents($path);
        $etag = '"'.hash('sha256', $body).'"';
        $headers = [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
        ];

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }
}
