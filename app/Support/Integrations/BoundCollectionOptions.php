<?php

namespace App\Support\Integrations;

/**
 * Request-scoped options for bound collectors (e.g. Meta selected-period Insights).
 * Cleared after each CollectLiveBoundDataService::collect() call.
 *
 * @phpstan-type PeriodOptions array{
 *     period_preset?: string,
 *     period_start?: string,
 *     period_end?: string,
 *     compare?: bool
 * }
 */
final class BoundCollectionOptions
{
    /** @var array<string, mixed> */
    private static array $options = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public static function set(array $options): void
    {
        self::$options = $options;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$options;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$options[$key] ?? $default;
    }

    public static function clear(): void
    {
        self::$options = [];
    }
}
