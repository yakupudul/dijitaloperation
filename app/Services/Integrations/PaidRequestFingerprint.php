<?php

namespace App\Services\Integrations;

/**
 * Provider-agnostic canonical request fingerprint for paid-API cost guards.
 *
 * Credentials, Authorization headers, and non-result-affecting timestamps
 * must never be fingerprint inputs.
 */
final class PaidRequestFingerprint
{
    /**
     * @param  array<string, mixed>  $parameters  Result-affecting request parameters only
     */
    public static function make(
        string $provider,
        string $useCase,
        string $endpoint,
        array $parameters = [],
    ): string {
        $canonical = [
            'provider' => strtolower(trim($provider)),
            'use_case' => trim($useCase),
            'endpoint' => ltrim(trim($endpoint), '/'),
            'parameters' => self::canonicalize($parameters),
        ];

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \InvalidArgumentException('Unable to canonicalize paid request fingerprint.');
        }

        return hash('sha256', $json);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|list<mixed>
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
                return $value;
            }

            return (string) $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $stringKey = (string) $key;
            if (self::isSecretOrTransientKey($stringKey)) {
                continue;
            }

            $normalized[$stringKey] = self::canonicalize($item);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private static function isSecretOrTransientKey(string $key): bool
    {
        $lower = strtolower($key);

        return in_array($lower, [
            'login',
            'password',
            'api_login',
            'api_password',
            'authorization',
            'auth',
            'token',
            'access_token',
            'refresh_token',
            'client_secret',
            'secret',
            'credential',
            'credentials',
            'timestamp',
            'requested_at',
            'nonce',
        ], true);
    }
}
