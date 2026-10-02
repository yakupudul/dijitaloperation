<?php

namespace App\Support\Integrations\Meta;

/**
 * Canonical Meta ExternalResource type taxonomy.
 *
 * Stored `resource_type` for Ad Accounts remains `meta_ads` (capability-aligned,
 * existing discovery upserts). Semantic label: META_AD_ACCOUNT.
 * META_BUSINESS is a provider container / access context — not bindable.
 */
final class MetaResourceType
{
    /** Provider-side Business / Business Portfolio container. */
    public const string META_BUSINESS = 'meta_business';

    /**
     * Meta Ad Account (capability id stored as resource_type for compatibility).
     * Canonical semantic: META_AD_ACCOUNT.
     */
    public const string META_AD_ACCOUNT = 'meta_ads';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::META_BUSINESS,
            self::META_AD_ACCOUNT,
        ];
    }

    public static function isValid(string $resourceType): bool
    {
        return in_array($resourceType, self::all(), true);
    }

    public static function label(string $resourceType): string
    {
        return match ($resourceType) {
            self::META_BUSINESS => 'Meta Business',
            self::META_AD_ACCOUNT => 'Meta Ad Account',
            default => str($resourceType)->replace('_', ' ')->title()->toString(),
        };
    }

    public static function isContainer(string $resourceType): bool
    {
        return $resourceType === self::META_BUSINESS;
    }

    public static function isSelectable(string $resourceType): bool
    {
        return $resourceType === self::META_AD_ACCOUNT;
    }

    public static function isBindable(string $resourceType): bool
    {
        return $resourceType === self::META_AD_ACCOUNT;
    }

    public static function visualType(string $resourceType): string
    {
        return match ($resourceType) {
            self::META_BUSINESS => 'meta_ads',
            self::META_AD_ACCOUNT => 'meta_ads',
            default => 'website',
        };
    }
}
