<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves stored JSON verbatim with ETag + X-Content-Version so the app can cache offline.
 * Sends 304 when If-None-Match matches.
 */
class ContentResponse
{
    public static function make(Request $request, string $json, string $etag, ?string $contentVersion = null): Response
    {
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ];
        if ($contentVersion !== null) {
            $headers['X-Content-Version'] = $contentVersion;
        }

        $sent = array_map(
            fn ($tag) => preg_replace('/^W\//', '', trim($tag)),
            explode(',', (string) $request->header('If-None-Match', '')),
        );

        if (in_array($etag, $sent, true) || in_array('*', $sent, true)) {
            return response('', 304, $headers);
        }

        return response($json, 200, $headers + ['Content-Type' => 'application/json']);
    }

    public static function etagFor(string $json): string
    {
        return '"'.sha1($json).'"';
    }
}
