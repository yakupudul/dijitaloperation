<?php

namespace App\Services\Collection\Website;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Services\Collection\CancellationService;
use App\Services\Collection\CollectionStateMachine;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\Providers\Website\WebsiteCrawlPoliteness;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use MoxDop\Website\SeoIntelligence\WebsiteDomainTarget;

/**
 * Stops active website collection runs through the normal cancellation path: queued work and steps no worker holds
 * right now are cancelled at once, a step a worker is executing stops at its next safe boundary. The WordPress
 * refresh that waited for these runs may start right away.
 */
final class WebsiteCollectionStopper
{
    /** Dataset providers of a website collection run. */
    public const WEBSITE_PROVIDERS = ['WEBSITE_DIRECT', 'DOMAIN_DNS_TLS', 'PAGESPEED_TECHNICAL', 'WORDPRESS_SITE_CONNECTOR'];

    public const ACTIVE = ['queued', 'running', 'retrying', 'cancellation_requested'];

    public function __construct(
        private readonly CancellationService $cancellation,
        private readonly CollectionStateMachine $stateMachine,
        private readonly CollectionStatusAggregator $aggregator,
        private readonly WebsiteCrawlPoliteness $politeness,
    ) {}

    /**
     * Active website runs, of one asset or of every asset.
     *
     * @return Collection<int, CollectionRun>
     */
    public function activeRuns(?int $assetId = null): Collection
    {
        return CollectionRun::query()->whereIn('status', self::ACTIVE)
            ->when($assetId !== null, fn ($query) => $query->where('digital_asset_id', $assetId))
            ->whereHas('datasetRuns', fn ($query) => $query->whereIn('provider_or_source', self::WEBSITE_PROVIDERS))
            ->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, CollectionRun>  $runs
     * @return array{stopped: int, waiting: int}
     */
    public function stop(Collection $runs): array
    {
        $stopped = 0;
        $waiting = 0;
        foreach ($runs as $run) {
            try {
                $run = $this->cancellation->requestCancellation($run);
            } catch (InvalidArgumentException) {
                continue;
            }
            // A dataset marked running that no worker holds right now (between steps, or its worker died) is
            // cancelled here; one a worker is executing stops at its next safe boundary.
            foreach ($run->datasetRuns()->whereIn('status', ['running', 'cancellation_requested'])->get() as $datasetRun) {
                /** @var CollectionDatasetRun $datasetRun */
                $held = $datasetRun->dispatch_lock_token !== null && $datasetRun->dispatch_locked_at?->greaterThan(now()->subMinutes(15));
                if ($held) {
                    $waiting++;

                    continue;
                }
                try {
                    $this->stateMachine->transition($datasetRun, CollectionRunStatus::Cancelled);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $this->aggregator->refreshFromDataset($datasetRun);
            }
            $this->forgetHostWait($run);
            $stopped++;
        }

        if ($runs->isNotEmpty() && Schema::hasTable('website_connector_delivery')) {
            DB::table('website_connector_delivery')->whereIn('collection_run_id', $runs->pluck('id'))
                ->update(['collection_run_id' => null, 'next_reconcile_at' => null, 'last_error' => null]);
            DB::table('website_connector_delivery')->whereNull('collection_run_id')->where('next_reconcile_at', '>', now())
                ->update(['next_reconcile_at' => null]);
        }

        return ['stopped' => $stopped, 'waiting' => $waiting];
    }

    /** A restarted collection starts without the previous run's wait (it is re-learned if the site still struggles). */
    private function forgetHostWait(CollectionRun $run): void
    {
        $asset = $run->digitalAsset;
        $domain = $asset instanceof DigitalAsset ? WebsiteDomainTarget::fromAsset($asset) : null;
        $host = $domain !== null ? $this->politeness->host('https://'.$domain) : '';
        if ($host !== '') {
            $this->politeness->forget($host);
        }
    }
}
