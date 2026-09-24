<?php

namespace App\Support\Time;

use DateTimeZone;
use Throwable;

/**
 * Provider accounts can report legacy time zone names ("Turkey") that newer PHP / tzdata builds reject.
 * Maps the known legacy names to their canonical zone and falls back to UTC for anything unknown.
 */
final class SafeTimezone
{
    /** @var array<string, string> */
    private const LEGACY = [
        'turkey' => 'Europe/Istanbul',
        'asia/istanbul' => 'Europe/Istanbul',
        'gb' => 'Europe/London',
        'gb-eire' => 'Europe/London',
        'eire' => 'Europe/Dublin',
        'us/eastern' => 'America/New_York',
        'us/central' => 'America/Chicago',
        'us/mountain' => 'America/Denver',
        'us/pacific' => 'America/Los_Angeles',
        'europe/kiev' => 'Europe/Kyiv',
        'w-su' => 'Europe/Moscow',
        'israel' => 'Asia/Jerusalem',
        'iran' => 'Asia/Tehran',
        'egypt' => 'Africa/Cairo',
        'japan' => 'Asia/Tokyo',
        'singapore' => 'Asia/Singapore',
        'utc' => 'UTC',
        'gmt' => 'UTC',
    ];

    public static function normalize(?string $timezone, string $fallback = 'UTC'): string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return $fallback;
        }
        $mapped = self::LEGACY[strtolower($timezone)] ?? $timezone;
        try {
            new DateTimeZone($mapped);

            return $mapped;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
