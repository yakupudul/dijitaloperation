<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\PageSpeedConnectionProbeService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Website Integrations')]
final class WebsiteIntegrationIndex extends Component
{
    public string $message = '';

    public string $messageTone = 'info';

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

        $rows = $assets->map(function (DigitalAsset $asset) use ($runs): array {
            $pageSpeed = $asset->connections->first(
                fn (CoreConnection $connection): bool => $connection->type === PageSpeedConnectionProbeService::CONNECTION_TYPE,
            );
            $credentialPayload = $pageSpeed?->credential?->encrypted_payload;
            $pageSpeedReady = $pageSpeed instanceof CoreConnection
                && $pageSpeed->enabled
                && is_array($credentialPayload)
                && filled($credentialPayload['api_key'] ?? null);

            return [
                'asset' => $asset,
                'run' => $runs->get($asset->id),
                'collectable' => filled($asset->primary_url) || filled($asset->domain),
                'page_speed_ready' => $pageSpeedReady,
                'wordpress_detected' => str_contains(strtolower((string) $asset->cms), 'wordpress'),
            ];
        });

        return view('livewire.operator.integrations.website-integration-index', [
            'rows' => $rows,
        ]);
    }
}
