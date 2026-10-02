<?php

namespace App\Services\DataPool\Support;

final class SecretSanitizer
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_FRAGMENTS = [
        'access_token',
        'refresh_token',
        'authorization',
        'client_secret',
        'api_secret',
        'password',
        'private_key',
        'signed_url',
        'bearer',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            $keyLower = strtolower((string) $key);
            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                if (str_contains($keyLower, $fragment)) {
                    continue 2;
                }
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function assertSafe(array $metadata): void
    {
        $stack = [$metadata];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($node as $key => $value) {
                $keyLower = strtolower((string) $key);
                foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                    if (str_contains($keyLower, $fragment)) {
                        throw new \InvalidArgumentException("Raw metadata must not contain secret key [{$key}]");
                    }
                }
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }
}
