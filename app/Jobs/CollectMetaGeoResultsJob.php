<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Services\Integrations\Meta\MetaException;
use App\Services\Integrations\Meta\MetaUsageGovernor;
use App\Services\MetaAds\MetaGeoResults;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Meta country + city results of one ad account (read-only Insights). */
final class CollectMetaGeoResultsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Meta unreachable (connection error / timeout): retried this many times, then recorded as failed without a report. */
    private const int TRANSPORT_RETRIES = 4;

    private const int TRANSPORT_RETRY_SECONDS = 300;

    private const int RETRY_HOURS = 12;

    /** Real errors fail once; rate-limit and connection waits are releases bounded by retryUntil(), not attempts. */
    public int $maxExceptions = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
    }

    /**
     * One geo collection at a time across all accounts (heavy two-breakdown Insights reads share Meta's app-level
     * budget); a second one waits instead of both hitting "Application request limit reached".
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('meta-geo-results'))->shared()->releaseAfter(120)->expireAfter(900)];
    }

    public function __construct(public int $assetId, public ?int $days = null) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public static function stateKey(int $assetId): string
    {
        return 'meta-geo-results:'.$assetId;
    }

    public function handle(MetaGeoResults $results): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        // v2: collection is free and continues for passive customers (AI never runs for them).
        if ($asset === null) {
            return;
        }
        $governor = app(MetaUsageGovernor::class);
        if (($wait = $governor->cooldownSeconds()) > 0) {
            // Meta asked us to slow down (usage headers / earlier rate-limit error): come back later.
            $this->release($wait + random_int(0, 60));

            return;
        }
        try {
            $rows = $results->collect($asset, $this->days);
            Cache::put(self::stateKey($this->assetId), ['state' => 'done', 'rows' => $rows, 'at' => now()->toIso8601String()], now()->addDay());
        } catch (MetaException $exception) {
            if ($exception->kind === MetaException::KIND_RATE_LIMIT) {
                Cache::put(self::stateKey($this->assetId), ['state' => 'waiting', 'error' => 'Meta istek sınırı; daha sonra tekrar denenecek.', 'at' => now()->toIso8601String()], now()->addDay());
                $this->release($governor->backoffForCode($exception->providerCode) + random_int(0, 60));

                return;
            }
            // A connection error / timeout is a Meta or network outage, not an application error: wait and retry.
            if ($exception->kind === MetaException::KIND_TRANSPORT && $exception->getPrevious() instanceof ConnectionException) {
                $retries = $this->transportRetries();
                if ($retries < self::TRANSPORT_RETRIES) {
                    Cache::put(self::stateKey($this->assetId), ['state' => 'waiting', 'error' => 'Meta bağlantı hatası; daha sonra tekrar denenecek.', 'transport_retries' => $retries + 1, 'at' => now()->toIso8601String()], now()->addDay());
                    $this->release(self::TRANSPORT_RETRY_SECONDS + random_int(0, 60));

                    return;
                }
                // Still unreachable after every retry: the next daily run tries again; no "application error" alert.
                Cache::put(self::stateKey($this->assetId), ['state' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 200), 'at' => now()->toIso8601String()], now()->addDay());

                return;
            }
            Cache::put(self::stateKey($this->assetId), ['state' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 200), 'at' => now()->toIso8601String()], now()->addDay());
            report($exception);
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($this->assetId), ['state' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 200), 'at' => now()->toIso8601String()], now()->addDay());
            report($exception);
        }
    }

    /**
     * Connection retries this run already spent. Only its own connection wait counts: unrelated waits (overlap,
     * cooldown, rate limit) do not use them up, and a rate-limit wait, an operator re-run, a finished run or a state
     * older than the retry window starts again from zero.
     */
    private function transportRetries(): int
    {
        $state = Cache::get(self::stateKey($this->assetId));
        if (! is_array($state) || ($state['state'] ?? null) !== 'waiting' || ! is_string($state['at'] ?? null)) {
            return 0;
        }

        return now()->subHours(self::RETRY_HOURS)->lt($state['at']) ? (int) ($state['transport_retries'] ?? 0) : 0;
    }
}
