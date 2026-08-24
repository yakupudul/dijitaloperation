<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Models\Collection\CollectionDatasetAttempt;
use App\Models\CoreIntegration;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Provider-side safety rail for Google Ads reads.
 *
 * - Serializes requests per Google Ads customer so dataset families cannot burst
 *   the same CID in parallel.
 * - Applies a small per-customer spacing between calls.
 * - Turns RESOURCE_EXHAUSTED / long Retry hints into one shared developer-token
 *   cooldown so every other dataset stops before making another provider call.
 * - Restores an active cooldown from recent dataset-attempt errors after deploy or
 *   cache resets, preventing an immediate quota re-hit.
 */
final class GoogleAdsRequestGovernor
{
    private const GLOBAL_COOLDOWN_KEY = 'moxdop:gads:quota:global-until';
    private const RECOVERY_MARKER_KEY = 'moxdop:gads:quota:recovery-scanned';

    /** @template T of Response @param callable():T $request @return T */
    public function run(CoreIntegration $integration, string $customerId, callable $request): Response
    {
        $this->restoreCooldownFromRecentAttempts();
        $this->assertNoGlobalCooldown();

        $customer = preg_replace('/\D+/', '', $customerId) ?: $customerId;
        $lockKey = 'moxdop:gads:request:customer:'.hash('sha256', $integration->getKey().'|'.$customer);
        $lockSeconds = max(30, (int) config('moxdop-google-ads-collector.request_lock_seconds', 180));
        $waitSeconds = max(1, (int) config('moxdop-google-ads-collector.request_lock_wait_seconds', 30));

        try {
            /** @var Response $response */
            $response = Cache::lock($lockKey, $lockSeconds)->block($waitSeconds, function () use ($request, $integration, $customer): Response {
                $this->assertNoGlobalCooldown();
                $this->paceCustomer($integration, $customer);

                $response = $request();
                $this->observeResponse($response);

                return $response;
            });

            return $response;
        } catch (LockTimeoutException) {
            throw new GoogleAdsQuotaCooldownException(
                max(5, (int) config('moxdop-google-ads-collector.request_lock_contention_retry_seconds', 15)),
                'customer_concurrency',
            );
        }
    }

    /** Restore persisted provider cooldown without making any Google Ads request. */
    public function synchronizeCooldownFromHistory(): int
    {
        $this->restoreCooldownFromRecentAttempts();

        return $this->remainingGlobalCooldownSeconds();
    }

    public function remainingGlobalCooldownSeconds(): int
    {
        $until = (int) Cache::get(self::GLOBAL_COOLDOWN_KEY, 0);

        return $until > time() ? $until - time() : 0;
    }

    private function assertNoGlobalCooldown(): void
    {
        $remaining = $this->remainingGlobalCooldownSeconds();
        if ($remaining > 0) {
            throw new GoogleAdsQuotaCooldownException($remaining, 'developer_token');
        }
    }

    private function paceCustomer(CoreIntegration $integration, string $customerId): void
    {
        $minimumMs = max(0, (int) config('moxdop-google-ads-collector.minimum_request_interval_ms', 500));
        if ($minimumMs === 0) {
            return;
        }

        $key = 'moxdop:gads:last-request:'.hash('sha256', $integration->getKey().'|'.$customerId);
        $nowMs = (int) floor(microtime(true) * 1000);
        $lastMs = (int) Cache::get($key, 0);
        $remainingMs = $minimumMs - ($nowMs - $lastMs);
        if ($lastMs > 0 && $remainingMs > 0) {
            usleep($remainingMs * 1000);
        }

        Cache::put($key, (int) floor(microtime(true) * 1000), now()->addMinutes(10));
    }

    private function observeResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->body();
        $retry = $this->retrySeconds($response, $body);
        $upper = strtoupper($body);
        $resourceExhausted = str_contains($upper, 'RESOURCE_EXHAUSTED');
        $temporarilyExhausted = str_contains($upper, 'RESOURCE_TEMPORARILY_EXHAUSTED');

        if ($resourceExhausted && ! $temporarilyExhausted) {
            $this->activateGlobalCooldown($retry > 0 ? $retry : 3600, 'provider_resource_exhausted');

            return;
        }

        if ($response->status() === 429 && $retry >= 300) {
            $this->activateGlobalCooldown($retry, 'provider_429_long_retry');
        }
    }

    private function activateGlobalCooldown(int $seconds, string $reason): void
    {
        $seconds = max(1, $seconds);
        $candidate = time() + $seconds;
        $existing = (int) Cache::get(self::GLOBAL_COOLDOWN_KEY, 0);
        $until = max($existing, $candidate);

        Cache::put(self::GLOBAL_COOLDOWN_KEY, $until, now()->addSeconds(max(3600, $until - time() + 300)));

        Log::warning('collection.google_ads.global_quota_cooldown', [
            'reason' => $reason,
            'retry_after_seconds' => max(1, $until - time()),
            'until' => date(DATE_ATOM, $until),
        ]);
    }

    private function restoreCooldownFromRecentAttempts(): void
    {
        if (Cache::has(self::RECOVERY_MARKER_KEY) || $this->remainingGlobalCooldownSeconds() > 0) {
            return;
        }

        Cache::put(self::RECOVERY_MARKER_KEY, true, now()->addMinutes(10));

        try {
            $attempts = CollectionDatasetAttempt::query()
                ->whereNotNull('finished_at')
                ->where('finished_at', '>=', now()->subDays(2))
                ->where('error_message', 'like', '%Google Ads%')
                ->where(function ($query): void {
                    $query->where('error_message', 'like', '%RESOURCE_EXHAUSTED%')
                        ->orWhere('error_message', 'like', '%Retry in % seconds%');
                })
                ->orderByDesc('finished_at')
                ->limit(100)
                ->get(['finished_at', 'error_message']);

            $bestUntil = 0;
            foreach ($attempts as $attempt) {
                $message = (string) ($attempt->error_message ?? '');
                if (! str_contains(strtoupper($message), 'RESOURCE_EXHAUSTED')) {
                    continue;
                }
                $retry = $this->retrySecondsFromText($message);
                if ($retry <= 0 || $attempt->finished_at === null) {
                    continue;
                }
                $bestUntil = max($bestUntil, $attempt->finished_at->getTimestamp() + $retry);
            }

            if ($bestUntil > time()) {
                $this->activateGlobalCooldown($bestUntil - time(), 'recovered_from_attempt_history');
            }
        } catch (\Throwable $e) {
            Log::notice('collection.google_ads.quota_cooldown_recovery_skipped', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function retrySeconds(Response $response, string $body): int
    {
        $header = trim((string) $response->header('Retry-After'));
        if ($header !== '') {
            if (is_numeric($header)) {
                return max(1, (int) $header);
            }
            $timestamp = strtotime($header);
            if ($timestamp !== false) {
                return max(1, $timestamp - time());
            }
        }

        return $this->retrySecondsFromText($body);
    }

    private function retrySecondsFromText(string $text): int
    {
        if (preg_match('/retry\s+(?:after|in)\s+(\d+)\s+seconds?/iu', $text, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return 0;
    }
}
