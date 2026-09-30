<?php

namespace App\Services\Collection\Providers\Website;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use MoxDop\Website\Discovery\PublicHttpFetcher;

/**
 * "Nazik mod": how hard the public crawler may hit one site.
 *
 * Small shared hosts run out of PHP workers / database connections quickly, so per host:
 * - a few pages at a time (crawl.concurrency, default 2) and a short pause between steps (crawl.min_delay_seconds);
 * - one crawl step at a time across all workers (host lock);
 * - robots.txt Crawl-delay is honoured (one page per step, that many seconds apart, capped);
 * - when the site answers 429 / 502 / 503 / 504, times out or shows WordPress's database-connection error, the
 *   crawl stops, drops to one page at a time and waits 5 → 15 → 60 minutes before trying again.
 */
final class WebsiteCrawlPoliteness
{
    private const int STATE_TTL_SECONDS = 86400;

    /** How long a site stays in slow mode (one page at a time) after it struggled. */
    private const int SLOW_MODE_SECONDS = 21600;

    private const int MAX_CRAWL_DELAY_SECONDS = 30;

    /** Reasons that mean the whole host is struggling, not one page. */
    private const array HOST_REASONS = ['database', 'rate_limited', 'unavailable'];

    public function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Pages fetched at the same time. */
    public function concurrency(string $host, ?int $crawlDelay = null): int
    {
        if ($crawlDelay !== null && $crawlDelay > 0) {
            return 1;
        }

        return $this->slow($host) ? 1 : max(1, (int) config('moxdop-website-intelligence.crawl.concurrency', 2));
    }

    /** Pages fetched in one crawl step. */
    public function batchSize(string $host, ?int $crawlDelay = null): int
    {
        if ($crawlDelay !== null && $crawlDelay > 0) {
            return 1;
        }
        if ($this->slow($host)) {
            return 2;
        }

        return max($this->concurrency($host), (int) config('moxdop-website-intelligence.crawl.batch_size', 6));
    }

    /** Seconds between two crawl steps of the same site. */
    public function stepDelay(?int $crawlDelay = null): int
    {
        $delay = max(0, (int) config('moxdop-website-intelligence.crawl.min_delay_seconds', 2));
        if ($crawlDelay !== null && $crawlDelay > 0) {
            $delay = max($delay, min(self::MAX_CRAWL_DELAY_SECONDS, $crawlDelay));
        }

        return $delay;
    }

    /** Seconds until this host may be fetched again (0 = now). */
    public function waitSeconds(string $host): int
    {
        $until = (int) ($this->state($host)['until'] ?? 0);

        return max(0, $until - now()->getTimestamp());
    }

    /** One crawl step per host at a time, across every worker. Null when another step holds it. */
    public function lock(string $host): ?Lock
    {
        $lock = Cache::lock('website-crawl:host:'.$host, 300);

        return $lock->get() ? $lock : null;
    }

    /**
     * Why a fetched batch says the host is struggling, or null when it is fine.
     *
     * @param  array<string, array<string, mixed>>  $fetches
     */
    public function distress(array $fetches): ?string
    {
        $reasons = [];
        $serverErrors = 0;
        foreach ($fetches as $fetch) {
            $status = (int) ($fetch['status_code'] ?? 0);
            $error = (string) ($fetch['error'] ?? '');
            $reasons[] = match (true) {
                str_contains($error, PublicHttpFetcher::DATABASE_ERROR) => 'database',
                $status === 429 => 'rate_limited',
                $status === 503 => 'unavailable',
                in_array($status, [502, 504], true) => 'gateway',
                str_starts_with($error, 'timeout_or_connection') => 'timeout',
                default => null,
            };
            if ($status === 500) {
                $serverErrors++;
            }
        }
        foreach (['database', 'rate_limited', 'unavailable', 'gateway', 'timeout'] as $reason) {
            if (in_array($reason, $reasons, true)) {
                return $reason;
            }
        }
        // One broken page answers 500; every page of a batch answering 500 is the server itself.
        if (count($fetches) > 1 && $serverErrors === count($fetches)) {
            return 'server_error';
        }

        return null;
    }

    /**
     * Records a struggling host: slow mode and the next wait (5 → 15 → 60 minutes, then 60 again).
     *
     * @return array{level: int, until: int, wait_seconds: int, reason: string, give_up: bool, skip_page: bool}
     */
    public function backOff(string $host, string $reason, int $batchSize): array
    {
        $state = $this->state($host);
        $steps = $this->backoffMinutes();
        $level = (int) ($state['level'] ?? 0);
        // A page-level problem (one slow or broken page) that survived every wait is recorded and skipped.
        if ($level >= count($steps) && $batchSize <= 2 && ! in_array($reason, self::HOST_REASONS, true)) {
            $this->put($host, ['level' => 0, 'until' => 0, 'reason' => $reason, 'slow_until' => now()->getTimestamp() + self::SLOW_MODE_SECONDS]);

            return ['level' => $level, 'until' => 0, 'wait_seconds' => 0, 'reason' => $reason, 'give_up' => false, 'skip_page' => true];
        }
        $level++;
        $minutes = $steps[min($level, count($steps)) - 1];
        $until = now()->getTimestamp() + $minutes * 60;
        $this->put($host, ['level' => $level, 'until' => $until, 'reason' => $reason, 'slow_until' => now()->getTimestamp() + self::SLOW_MODE_SECONDS]);

        return [
            'level' => $level,
            'until' => $until,
            'wait_seconds' => $minutes * 60,
            'reason' => $reason,
            'give_up' => $level > max(count($steps), (int) config('moxdop-website-intelligence.crawl.max_backoffs', 8)),
            'skip_page' => false,
        ];
    }

    /** A step went through: the wait level resets; slow mode stays for a while. */
    public function recovered(string $host): void
    {
        $state = $this->state($host);
        if ($state === [] || ((int) ($state['level'] ?? 0) === 0 && (int) ($state['until'] ?? 0) === 0)) {
            return;
        }
        $this->put($host, array_merge($state, ['level' => 0, 'until' => 0]));
    }

    public function forget(string $host): void
    {
        Cache::forget($this->key($host));
    }

    /**
     * What the collection screen shows.
     *
     * @return array{mode: string, concurrency: int, reason: ?string, next_attempt_at: ?string, crawl_delay: ?int}
     */
    public function view(string $host, ?int $crawlDelay = null): array
    {
        $state = $this->state($host);
        $until = (int) ($state['until'] ?? 0);
        $backingOff = $until > now()->getTimestamp();

        return [
            'mode' => $backingOff ? 'backoff' : ($this->slow($host) || ($crawlDelay ?? 0) > 0 ? 'slow' : 'normal'),
            'concurrency' => $this->concurrency($host, $crawlDelay),
            'reason' => $backingOff || $this->slow($host) ? ($state['reason'] ?? null) : null,
            'next_attempt_at' => $backingOff ? gmdate('c', $until) : null,
            'crawl_delay' => $crawlDelay,
        ];
    }

    /** robots.txt Crawl-delay for "*" or MoxDOP, in whole seconds. */
    public function robotsCrawlDelay(?string $robots): ?int
    {
        if ($robots === null || trim($robots) === '') {
            return null;
        }
        $agents = [];
        $inRules = false;
        $delay = null;
        foreach (preg_split('/\r\n|\r|\n/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);

                continue;
            }
            $inRules = true;
            if ($field === 'crawl-delay' && is_numeric($value)) {
                $applies = array_filter($agents, fn (string $agent): bool => $agent === '*' || str_contains($agent, 'moxdop'));
                if ($applies !== []) {
                    $delay = max($delay ?? 0, (int) ceil((float) $value));
                }
            }
        }

        return $delay !== null && $delay > 0 ? min(self::MAX_CRAWL_DELAY_SECONDS, $delay) : null;
    }

    /** @return list<int> */
    private function backoffMinutes(): array
    {
        $minutes = array_values(array_filter(array_map('intval', (array) config('moxdop-website-intelligence.crawl.backoff_minutes', [5, 15, 60])), fn (int $value): bool => $value > 0));

        return $minutes !== [] ? $minutes : [5, 15, 60];
    }

    private function slow(string $host): bool
    {
        return (int) ($this->state($host)['slow_until'] ?? 0) > now()->getTimestamp();
    }

    /** @return array<string, mixed> */
    private function state(string $host): array
    {
        $state = Cache::get($this->key($host));

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function put(string $host, array $state): void
    {
        Cache::put($this->key($host), $state, self::STATE_TTL_SECONDS);
    }

    private function key(string $host): string
    {
        return 'website-crawl:state:'.$host;
    }
}
