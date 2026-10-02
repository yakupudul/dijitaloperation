<?php

namespace App\Services\Integrations\Meta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Meta Graph API usage governor. Every response carries usage headers:
 *  - x-app-usage                  {"call_count": %, "total_time": %, "total_cputime": %}   (app level, code 4)
 *  - x-ad-account-usage           {"acc_id_util_pct": %, "reset_time_duration": s}         (ad account level)
 *  - x-business-use-case-usage    {"<business id>": [{"call_count": %, …, "estimated_time_to_regain_access": min}]}
 * The highest percentage and any "regain access" time are kept in the cache; heavy jobs (geo results, breakdown
 * datasets) wait for the cooldown instead of burning their attempts on "Application request limit reached".
 */
final class MetaUsageGovernor
{
    private const string KEY = 'meta-usage-governor:cooldown-until';

    private const string USAGE_KEY = 'meta-usage-governor:usage';

    /** Records the usage headers of one response. */
    public function observe(Response $response): void
    {
        try {
            [$usage, $regainSeconds] = $this->parse([
                'x-app-usage' => $response->header('x-app-usage'),
                'x-ad-account-usage' => $response->header('x-ad-account-usage'),
                'x-business-use-case-usage' => $response->header('x-business-use-case-usage'),
            ]);
            if ($usage === null && $regainSeconds === 0) {
                return;
            }
            Cache::put(self::USAGE_KEY, ['pct' => $usage, 'at' => now()->toIso8601String()], now()->addHour());
            $threshold = (float) config('moxdop.meta.usage_throttle_pct', 90);
            $cooldown = $regainSeconds;
            if ($usage !== null && $usage >= $threshold) {
                $cooldown = max($cooldown, (int) config('moxdop.meta.usage_throttle_seconds', 300));
            }
            if ($cooldown > 0) {
                $this->coolDown($cooldown);
            }
        } catch (Throwable) {
            // Usage headers are advisory; never break the request.
        }
    }

    /**
     * Highest usage percentage and the regain-access wait in seconds from the raw header values.
     *
     * @param  array<string, string|null>  $headers
     * @return array{0: float|null, 1: int}
     */
    public function parse(array $headers): array
    {
        $max = null;
        $regain = 0;
        $take = static function (mixed $value) use (&$max): void {
            if (is_numeric($value)) {
                $max = max($max ?? 0.0, (float) $value);
            }
        };
        foreach ($headers as $name => $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }
            if ($name === 'x-business-use-case-usage') {
                foreach ($decoded as $entries) {
                    foreach ((array) $entries as $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }
                        foreach (['call_count', 'total_time', 'total_cputime'] as $metric) {
                            $take($entry[$metric] ?? null);
                        }
                        $regain = max($regain, (int) round(60 * (float) ($entry['estimated_time_to_regain_access'] ?? 0)));
                    }
                }

                continue;
            }
            foreach (['call_count', 'total_time', 'total_cputime', 'acc_id_util_pct'] as $metric) {
                $take($decoded[$metric] ?? null);
            }
            if ($name === 'x-ad-account-usage' && (float) ($decoded['acc_id_util_pct'] ?? 0) >= 100) {
                $regain = max($regain, (int) ($decoded['reset_time_duration'] ?? 0));
            }
        }

        return [$max, $regain];
    }

    /** Seconds to wait after a rate-limit error with this Graph code (app level 4 resets slowest). */
    public function backoffForCode(?int $providerCode): int
    {
        $base = match ($providerCode) {
            4 => (int) config('moxdop.meta.app_limit_backoff_seconds', 900),
            32, 613 => 600,
            17, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014 => 300,
            default => 120,
        };

        return max($base, $this->cooldownSeconds());
    }

    /** A rate-limit error was returned: nobody calls Meta again until the backoff has passed. */
    public function rateLimited(?int $providerCode): int
    {
        $seconds = $this->backoffForCode($providerCode);
        $this->coolDown($seconds);

        return $seconds;
    }

    /** Seconds until the recorded cooldown ends (0 = free to call). */
    public function cooldownSeconds(): int
    {
        try {
            $until = (int) Cache::get(self::KEY, 0);
        } catch (Throwable) {
            return 0;
        }

        return max(0, $until - time());
    }

    private function coolDown(int $seconds): void
    {
        $seconds = min(max(0, $seconds), 3600);
        $until = time() + $seconds;
        if ($until > (int) Cache::get(self::KEY, 0)) {
            Cache::put(self::KEY, $until, now()->addSeconds($seconds + 60));
        }
    }
}
