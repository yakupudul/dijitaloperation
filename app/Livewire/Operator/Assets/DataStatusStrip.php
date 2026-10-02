<?php

namespace App\Livewire\Operator\Assets;

use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\Presentation\DataSyncScopeService;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * The "Veri durumu" strip under the asset context bar of every asset page: one line per data source from
 * DataStatusReader, with its single action. "Verileri yenile" starts the canonical collection for that source
 * (the same path the former per-page sync controls used); it refreshes itself while a collection is running.
 */
final class DataStatusStrip extends Component
{
    /** Central collection provider code per bindable source. */
    private const array PROVIDERS = [
        'ga4' => 'GA4', 'search_console' => 'SEARCH_CONSOLE', 'google_ads' => 'GOOGLE_ADS', 'meta_ads' => 'META_ADS',
    ];

    public int $assetId;

    public string $feedback = '';

    public string $feedbackTone = 'info';

    public function mount(int|string $assetId): void
    {
        $this->assetId = (int) $assetId;
    }

    public function refreshSource(string $capability, DataSyncScopeService $sync, AsyncOperationService $async, DataStatusReader $reader): void
    {
        $asset = DigitalAsset::query()->findOrFail($this->assetId);
        $status = $reader->forAssetSource($asset, $capability);
        $source = $status->sourceLabel();
        if (! $status->isBound()) {
            [$this->feedback, $this->feedbackTone] = [__('data_status.feedback.action_required', ['source' => $source], 'tr'), 'warning'];

            return;
        }

        try {
            if (isset(self::PROVIDERS[$capability])) {
                $outcome = (string) ($sync->start($this->assetId, [$capability], [self::PROVIDERS[$capability]], auth()->user())['outcome'] ?? 'failed');
            } else {
                $outcome = ($async->queueBoundCollect($asset, auth()->user(), ['trigger' => 'operator.data-status.refresh'])['ok'] ?? false) ? 'started' : 'failed';
            }
        } catch (Throwable $error) {
            report($error);
            $outcome = 'failed';
        }
        $outcome = in_array($outcome, ['started', 'active_equivalent', 'data_current', 'action_required'], true) ? $outcome : 'failed';
        $this->feedback = __('data_status.feedback.'.$outcome, ['source' => $source], 'tr');
        $this->feedbackTone = in_array($outcome, ['started', 'active_equivalent', 'data_current'], true) ? 'success' : 'warning';
        $reader->flush();
    }

    public function render(DataStatusReader $reader): View
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        $statuses = $asset !== null ? $reader->forAsset($asset) : [];

        return view('livewire.operator.assets.data-status-strip', [
            'statuses' => $statuses,
            'asset' => $asset,
            'polling' => collect($statuses)->contains(fn (DataStatus $s): bool => $s->collecting || $s->state === DataStatus::FIRST_LOAD),
        ]);
    }
}
