<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Services\Integrations\Meta\MetaException;
use App\Services\Integrations\Meta\MetaUsageGovernor;
use App\Services\MetaAds\MetaGeoResults;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Meta country + city results of one ad account (read-only Insights). */
final class CollectMetaGeoResultsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Real errors fail once; rate-limit waits are releases bounded by retryUntil(), not attempts. */
    public int $maxExceptions = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
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
            Cache::put(self::stateKey($this->assetId), ['state' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 200), 'at' => now()->toIso8601String()], now()->addDay());
            report($exception);
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($this->assetId), ['state' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 200), 'at' => now()->toIso8601String()], now()->addDay());
            report($exception);
        }
    }
}
