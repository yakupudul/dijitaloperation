<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\PageSpeedConnectionProbeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Website Integrations')]
final class WebsiteIntegrationIndex extends Component
{
    public string $message = '';

    public string $messageTone = 'info';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $filter = 'all';

    public function collectNow(int $assetId, WebsiteCollectionOrchestrator $orchestrator): void
    {
        $asset = DigitalAsset::query()
            ->where('type', 'website')
            ->findOrFail($assetId);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $run = $orchestrator->start(
                asset: $asset,
                requestedBy: $actor,
                context: [
                    'trigger' => 'operator.integrations.website.collect',
                    'force_refresh' => true,
                ],
            );

            $this->messageTone = 'success';
            $this->message = app()->getLocale() === 'tr'
                ? "{$asset->name} için Website veri toplama kuyruğa alındı. Collection #{$run->id}."
                : "Website collection queued for {$asset->name}. Collection #{$run->id}.";
        } catch (Throwable $exception) {
            report($exception);
            $this->messageTone = 'error';
            $this->message = app()->getLocale() === 'tr'
                ? 'Website veri toplama başlatılamadı: '.$exception->getMessage()
                : 'Website collection could not be started: '.$exception->getMessage();
        }
    }

    public function render(): View
    {
        $assets = DigitalAsset::query()
            ->with(['brand.customer', 'connections.credential'])
            ->where('type', 'website')
            ->orderBy('name')
            ->get();

        $runs = $assets->isEmpty()
            ? collect()
            : CollectionRun::query()
                ->whereIn('digital_asset_id', $assets->pluck('id'))
                ->latest('id')
                ->get()
                ->filter(fn (CollectionRun $run): bool => in_array(
                    'WEBSITE_DIRECT',
                    (array) data_get($run->request_context, 'provider_sources', []),
                    true,
                ))
                ->unique('digital_asset_id')
                ->keyBy('digital_asset_id');

        $allRows = $assets->map(function (DigitalAsset $asset) use ($runs): array {
            $pageSpeed = $asset->connections->first(
                fn (CoreConnection $connection): bool => $connection->type === PageSpeedConnectionProbeService::CONNECTION_TYPE,
            );
            $credentialPayload = $pageSpeed?->credential?->encrypted_payload;
            $pageSpeedReady = $pageSpeed instanceof CoreConnection
                && $pageSpeed->enabled
                && is_array($credentialPayload)
                && filled($credentialPayload['api_key'] ?? null);

            /** @var CollectionRun|null $run */
            $run = $runs->get($asset->id);
            $collectable = filled($asset->primary_url) || filled($asset->domain);
            $wordpressDetected = str_contains(strtolower((string) $asset->cms), 'wordpress');
            $status = $run?->status?->value;
            $datasetsTotal = max(0, (int) ($run?->datasets_total ?? 0));
            $datasetsCompleted = max(0, (int) ($run?->datasets_completed ?? 0));
            $progress = $datasetsTotal > 0
                ? min(100, (int) round(($datasetsCompleted / $datasetsTotal) * 100))
                : 0;

            $overallState = match (true) {
                ! $collectable => 'needs_setup',
                in_array($status, ['failed', 'cancelled'], true) => 'attention',
                $status === 'partial' => 'partial',
                in_array($status, ['queued', 'running', 'retrying'], true) => 'running',
                default => 'ready',
            };

            return [
                'asset' => $asset,
                'run' => $run,
                'collectable' => $collectable,
                'page_speed_ready' => $pageSpeedReady,
                'wordpress_detected' => $wordpressDetected,
                'overall_state' => $overallState,
                'progress' => $progress,
                'recently_collected' => $run?->updated_at?->gte(now()->subDays(7)) ?? false,
                'next_action' => $this->nextAction(
                    $collectable,
                    $pageSpeedReady,
                    $wordpressDetected,
                    $status,
                ),
            ];
        });

        $stats = [
            'total' => $allRows->count(),
            'collect_ready' => $allRows->where('collectable', true)->count(),
            'pagespeed_connected' => $allRows->where('page_speed_ready', true)->count(),
            'wordpress_detected' => $allRows->where('wordpress_detected', true)->count(),
            'recently_collected' => $allRows->where('recently_collected', true)->count(),
        ];

        $rows = $this->filterRows($allRows);

        return view('livewire.operator.integrations.website-integration-index', [
            'rows' => $rows,
            'stats' => $stats,
            'filters' => $this->filterOptions($allRows),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterRows(Collection $rows): Collection
    {
        $query = mb_strtolower(trim($this->search));

        if ($query !== '') {
            $rows = $rows->filter(function (array $row) use ($query): bool {
                /** @var DigitalAsset $asset */
                $asset = $row['asset'];
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $asset->name,
                    $asset->primary_url,
                    $asset->domain,
                    $asset->cms,
                    $asset->brand?->name,
                    $asset->brand?->customer?->name,
                ])));

                return str_contains($haystack, $query);
            });
        }

        $rows = match ($this->filter) {
            'ready' => $rows->where('collectable', true),
            'pagespeed_needed' => $rows->where('page_speed_ready', false),
            'wordpress' => $rows->where('wordpress_detected', true),
            'partial' => $rows->filter(fn (array $row): bool => ($row['overall_state'] ?? null) === 'partial'),
            default => $rows,
        };

        return $rows->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, count: int}>
     */
    private function filterOptions(Collection $rows): array
    {
        $tr = app()->getLocale() === 'tr';

        return [
            ['key' => 'all', 'label' => $tr ? 'Tümü' : 'All', 'count' => $rows->count()],
            ['key' => 'ready', 'label' => $tr ? 'Collect Ready' : 'Collect Ready', 'count' => $rows->where('collectable', true)->count()],
            ['key' => 'pagespeed_needed', 'label' => $tr ? 'PageSpeed Eksik' : 'PageSpeed Needed', 'count' => $rows->where('page_speed_ready', false)->count()],
            ['key' => 'wordpress', 'label' => 'WordPress', 'count' => $rows->where('wordpress_detected', true)->count()],
            ['key' => 'partial', 'label' => 'Partial', 'count' => $rows->filter(fn (array $row): bool => ($row['overall_state'] ?? null) === 'partial')->count()],
        ];
    }

    private function nextAction(
        bool $collectable,
        bool $pageSpeedReady,
        bool $wordpressDetected,
        ?string $status,
    ): string {
        $tr = app()->getLocale() === 'tr';

        if (! $collectable) {
            return $tr
                ? 'Primary URL veya domain ekleyin; Website collection bundan sonra çalıştırılabilir.'
                : 'Add a primary URL or domain before Website collection can run.';
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            return $tr
                ? 'Son collection başarısız oldu. Kaynak ayrıntılarını kontrol edip yeniden veri çekin.'
                : 'The latest collection failed. Review source details and run collection again.';
        }

        if ($status === 'partial') {
            return $tr
                ? 'Collection kısmi tamamlandı. Başarısız datasetleri Kaynakları Yönet ekranından inceleyin.'
                : 'Collection completed partially. Inspect failed datasets from Manage Sources.';
        }

        if (! $pageSpeedReady) {
            return $tr
                ? 'PageSpeed API bağlantısı eklenirse Lighthouse performans verileri de aynı collection akışına katılır.'
                : 'Connect the PageSpeed API to include Lighthouse performance data in the same collection flow.';
        }

        if ($wordpressDetected) {
            return $tr
                ? 'WordPress algılandı. Authenticated connector production olduğunda daha geniş CMS envanteri alınabilecek.'
                : 'WordPress detected. A broader CMS inventory will be available when the authenticated connector is production-ready.';
        }

        return $tr
            ? 'Public Website kaynakları hazır. Güncel teknik veriyi almak için Veri Çek çalıştırabilirsiniz.'
            : 'Public Website sources are ready. Run Collect Data to refresh technical evidence.';
    }
}
