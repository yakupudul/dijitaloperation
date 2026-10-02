<?php

namespace App\Support\Time;

use DateTimeZone;
use Throwable;

/**
 * Time zone normalizer for provider accounts (Google Ads, Meta, GA4, Search Console, Business Profile).
 *
 * Providers can report legacy tz "backward" link names ("Turkey", "US/Eastern", "Asia/Calcutta") that newer PHP /
 * tzdata builds reject — PHP 8.5 CarbonTimeZone throws DateInvalidTimeZoneException ("Unknown or bad timezone
 * (Turkey)") and failed advisor / analyst jobs. Resolution order:
 *   1. known backward links → canonical zone,
 *   2. the canonical spelling of a case-insensitive match among DateTimeZone::listIdentifiers(),
 *   3. the name itself when this PHP accepts it,
 *   4. the fallback (default: config app.timezone when valid, else Europe/Istanbul).
 * Never throws.
 */
final class SafeTimezone
{
    /** @var array<string, string> tzdata "backward" links (lower-case) → canonical zone */
    private const LEGACY = [
        'turkey' => 'Europe/Istanbul',
        'asia/istanbul' => 'Europe/Istanbul',
        'gb' => 'Europe/London',
        'gb-eire' => 'Europe/London',
        'eire' => 'Europe/Dublin',
        'portugal' => 'Europe/Lisbon',
        'poland' => 'Europe/Warsaw',
        'europe/kiev' => 'Europe/Kyiv',
        'europe/uzhgorod' => 'Europe/Kyiv',
        'europe/zaporozhye' => 'Europe/Kyiv',
        'europe/belfast' => 'Europe/London',
        'europe/nicosia' => 'Asia/Nicosia',
        'europe/tiraspol' => 'Europe/Chisinau',
        'w-su' => 'Europe/Moscow',
        'us/eastern' => 'America/New_York',
        'us/central' => 'America/Chicago',
        'us/mountain' => 'America/Denver',
        'us/pacific' => 'America/Los_Angeles',
        'us/alaska' => 'America/Anchorage',
        'us/hawaii' => 'Pacific/Honolulu',
        'us/arizona' => 'America/Phoenix',
        'us/east-indiana' => 'America/Indiana/Indianapolis',
        'us/michigan' => 'America/Detroit',
        'canada/eastern' => 'America/Toronto',
        'canada/central' => 'America/Winnipeg',
        'canada/mountain' => 'America/Edmonton',
        'canada/pacific' => 'America/Vancouver',
        'canada/atlantic' => 'America/Halifax',
        'brazil/east' => 'America/Sao_Paulo',
        'mexico/general' => 'America/Mexico_City',
        'america/buenos_aires' => 'America/Argentina/Buenos_Aires',
        'america/indianapolis' => 'America/Indiana/Indianapolis',
        'america/godthab' => 'America/Nuuk',
        'israel' => 'Asia/Jerusalem',
        'asia/tel_aviv' => 'Asia/Jerusalem',
        'iran' => 'Asia/Tehran',
        'egypt' => 'Africa/Cairo',
        'libya' => 'Africa/Tripoli',
        'japan' => 'Asia/Tokyo',
        'rok' => 'Asia/Seoul',
        'prc' => 'Asia/Shanghai',
        'roc' => 'Asia/Taipei',
        'hongkong' => 'Asia/Hong_Kong',
        'singapore' => 'Asia/Singapore',
        'asia/calcutta' => 'Asia/Kolkata',
        'asia/katmandu' => 'Asia/Kathmandu',
        'asia/saigon' => 'Asia/Ho_Chi_Minh',
        'asia/rangoon' => 'Asia/Yangon',
        'asia/dacca' => 'Asia/Dhaka',
        'asia/ulan_bator' => 'Asia/Ulaanbaatar',
        'asia/chongqing' => 'Asia/Shanghai',
        'asia/harbin' => 'Asia/Shanghai',
        'australia/nsw' => 'Australia/Sydney',
        'australia/act' => 'Australia/Sydney',
        'australia/victoria' => 'Australia/Melbourne',
        'australia/queensland' => 'Australia/Brisbane',
        'australia/west' => 'Australia/Perth',
        'nz' => 'Pacific/Auckland',
        'iceland' => 'Atlantic/Reykjavik',
        'cuba' => 'America/Havana',
        'jamaica' => 'America/Jamaica',
        'navajo' => 'America/Denver',
        'utc' => 'UTC',
        'gmt' => 'UTC',
        'etc/utc' => 'UTC',
        'etc/gmt' => 'UTC',
        'universal' => 'UTC',
        'zulu' => 'UTC',
        'greenwich' => 'UTC',
        'uct' => 'UTC',
    ];

    /** @var array<string, string>|null lower-case identifier → identifier (this PHP build) */
    private static ?array $identifiers = null;

    public static function normalize(?string $timezone, ?string $fallback = null): string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return $fallback ?? self::fallback();
        }
        $lower = strtolower($timezone);
        foreach ([self::LEGACY[$lower] ?? null, self::identifiers()[$lower] ?? null, $timezone] as $candidate) {
            if ($candidate !== null && self::valid($candidate)) {
                return $candidate;
            }
        }

        return $fallback ?? self::fallback();
    }

    /** Nullable variant: keeps "no time zone" as null, normalizes everything else. */
    public static function normalizeNullable(?string $timezone, ?string $fallback = null): ?string
    {
        return $timezone === null || trim($timezone) === '' ? null : self::normalize($timezone, $fallback);
    }

    /** The application time zone when valid, else Europe/Istanbul (the agency's clock). */
    public static function fallback(): string
    {
        try {
            $configured = trim((string) config('app.timezone', ''));
        } catch (Throwable) {
            return 'UTC'; // outside the application (plain unit tests, early bootstrap)
        }

        return $configured !== '' && self::valid($configured) ? $configured : 'Europe/Istanbul';
    }

    private static function valid(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    private static function identifiers(): array
    {
        if (self::$identifiers === null) {
            self::$identifiers = [];
            foreach (DateTimeZone::listIdentifiers() as $identifier) {
                self::$identifiers[strtolower($identifier)] = $identifier;
            }
        }

        return self::$identifiers;
    }
}
