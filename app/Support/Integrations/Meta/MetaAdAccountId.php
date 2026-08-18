<?php

namespace App\Support\Integrations\Meta;

/**
 * Canonical Meta Ad Account identity.
 *
 * ExternalResource.external_id is always `act_{digits}`.
 * Graph paths and legacy numeric IDs normalize through this helper so
 * `123` and `act_123` cannot create duplicate resources.
 */
final class MetaAdAccountId
{
    /**
     * Canonical ExternalResource identity: act_{account_id}.
     */
    public static function canonical(?string $raw): ?string
    {
        $digits = self::digits($raw);
        if ($digits === null) {
            return null;
        }

        return 'act_'.$digits;
    }

    /**
     * Numeric account id without act_ prefix (provider account_id).
     */
    public static function digits(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (str_starts_with(strtolower($value), 'act_')) {
            $value = substr($value, 4);
        }

        $value = trim($value);
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Graph API node path segment (always act_ form).
     */
    public static function graphNode(?string $raw): ?string
    {
        return self::canonical($raw);
    }

    /**
     * Require a valid Ad Account id and return canonical API form (act_{digits}).
     *
     * @throws \InvalidArgumentException
     */
    public static function toApiForm(string $raw): string
    {
        $canonical = self::canonical($raw);
        if ($canonical === null) {
            throw new \InvalidArgumentException('Meta Ad Account id is missing or invalid.');
        }

        return $canonical;
    }

    public static function equals(?string $a, ?string $b): bool
    {
        $left = self::canonical($a);
        $right = self::canonical($b);

        return $left !== null && $left === $right;
    }
}
