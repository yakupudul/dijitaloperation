<?php

namespace App\Jobs\Async;

use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\ModuleRegistry;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Website\PublicDiscovery\StoredDiscoverySource;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Permissions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use MoxDop\Website\Discovery\PublicDiscoveryService;
use Throwable;

class PublicDiscoveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(AsyncOperationService $async): void
    {
        $lock = Cache::lock('public-discovery-job:'.$this->runId, 330);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $run = Run::query()->find($this->runId);
            if ($run === null || ! in_array($run->status, ['queued', 'running'], true)
                || data_get($run->metadata, 'operation_type') !== AsyncOperationTypes::PUBLIC_DISCOVERY) {
                return;
            }
            try {
                $asset = DigitalAsset::query()->where('type', 'website')->findOrFail($run->digital_asset_id);
                $actor = User::query()->find(data_get($run->metadata, 'triggered_by_user_id'));
                abort_unless($actor?->is_active && $actor->can(Permissions::ACCESS_APP) && ModuleRegistry::isEnabled('website'), 403);
                $source = app(StoredDiscoverySource::class);
                $collection = CollectionRun::query()->where('digital_asset_id', $asset->id)
                    ->where('idempotency_key', 'public-discovery:'.$run->id)->first();
                if ($collection === null) {
                    $inventory = $source->inventory($asset);
                    if (! $inventory['needs_collection']) {
                        $probe = $source->read($asset, $inventory);
                        $unreadable = collect($probe['failures'])->whereIn('error', ['stored_html_unreadable', 'stored_html_ineligible'])->pluck('url')->all();
                        if ($unreadable !== []) {
                            $inventory['needs_collection'] = true;
                            $inventory['refresh_urls'] = array_slice($unreadable, 0, 100);
                        }
                    }
                    if ($inventory['needs_collection']) {
                        $async->setPhase($run, 'awaiting_collection', 'Eksik veya eski HTML toplanıyor', status: 'running');
                        $context = [
                            'idempotency_key' => 'public-discovery:'.$run->id,
                            'public_discovery_operation_id' => $run->id,
                            'collection_intent' => 'public_discovery_refresh',
                            'collection_intent_label' => 'Kamu keşfi için HTML yenileme',
                            'force_refresh' => true,
                        ];
                        if ($inventory['stored_urls'] > 0 && $inventory['refresh_urls'] !== []) {
                            $context['targeted_verification'] = ['urls' => $inventory['refresh_urls']];
                        }
                        $collection = app(WebsiteCollectionOrchestrator::class)->start(
                            $asset, $actor,
                            [WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL],
                            $context, publicDiscovery: true,
                        );
                    }
                }
                if ($collection !== null) {
                    $run->refresh()->update(['metadata' => array_merge($run->metadata, ['source_collection_run_id' => $collection->id])]);
                    if (! $collection->status->isTerminal()) {
                        $async->setPhase($run, 'awaiting_collection', 'HTML toplama sürüyor; tamamlanınca keşif devam edecek', status: 'running');

                        return;
                    }
                }
                $async->markRunning($run, 'reading_stored_html', 'Kayıtlı sayfalar inceleniyor');
                $result = app(PublicDiscoveryService::class)->discover($asset);
                $status = match ($result['status']) {
                    'succeeded' => 'completed', 'partial' => 'partial', default => 'failed'
                };
                // A failed refresh must not be hidden by a usable subset of stored pages.
                if ($collection !== null && $collection->status->value !== 'completed' && $status === 'completed') {
                    $status = 'partial';
                }
                $async->markFinished($run->fresh(), $status, $status === 'completed' ? 'Keşif tamamlandı' : 'Keşif kapsamını kontrol edin', [
                    'result_summary' => $result['message'], 'child_run_ids' => [$result['run']->id],
                    'coverage' => $result['coverage'], 'cached' => $result['cached'],
                    'source_collection_status' => $collection?->status->value,
                    'retryable' => $status !== 'completed',
                    'failure_summary' => $status === 'failed' ? $result['message'] : null,
                ]);
            } catch (Throwable $exception) {
                $async->markFailed($run->fresh() ?? $run, $exception);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = Run::query()->find($this->runId);
        if ($run !== null && $exception !== null && in_array($run->status, ['queued', 'running'], true)) {
            app(AsyncOperationService::class)->markFailed($run, $exception);
        }
    }
}
