<?php

namespace App\Support\Playbooks;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Safe external URL validation for Playbook references. Never fetches the URL.
 */
final class PlaybookReferenceUrl
{
    public static function assertSafeExternalUrl(string $url): string
    {
        $url = trim($url);
        $validator = Validator::make(
            ['url' => $url],
            ['url' => ['required', 'string', 'max:2048', 'url:http,https']]
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'url' => 'External Playbook references must use http or https URLs.',
            ]);
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                'url' => 'Unsafe URL scheme rejected.',
            ]);
        }

        return $url;
    }
}
