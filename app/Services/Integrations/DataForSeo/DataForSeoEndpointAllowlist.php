<?php

namespace App\Services\Integrations\DataForSeo;

/**
 * Explicit approved DataForSEO API v3 endpoint identifiers.
 * Collectors must use allowlisted methods — no arbitrary endpoint console.
 */
final class DataForSeoEndpointAllowlist
{
    public const string APPENDIX_USER_DATA = 'appendix/user_data';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::APPENDIX_USER_DATA,
        ];
    }

    public static function isAllowed(string $endpoint): bool
    {
        $normalized = ltrim(trim($endpoint), '/');

        return in_array($normalized, self::all(), true);
    }

    public static function assertAllowed(string $endpoint): string
    {
        $normalized = ltrim(trim($endpoint), '/');

        if (! self::isAllowed($normalized)) {
            throw new DataForSeoException(
                'DataForSEO endpoint is not allowlisted for MoxDOP: '.$normalized,
                kind: DataForSeoException::KIND_ENDPOINT_NOT_ALLOWED,
            );
        }

        return $normalized;
    }
}
