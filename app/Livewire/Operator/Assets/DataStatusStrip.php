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
 * The "Veri durumu" dot in the one-line asset page head: the worst source state as a dot and a word, every source's
 * line (DataStatusReader) on hover and in its small panel with the reconnect / bind links, and one "Yenile" that starts
 * the canonical collection of every source that can be refreshed (hidden when the page has its own refresh button).
 * It refreshes itself while a collection is running.
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

    /** Show the single "Yenile"; false when the page has its own refresh button. */
    public bool $refresh = true;

    public function mount(int|string $assetId, bool $refresh = true): void
    {
        $this->assetId = (int) $assetId;
        $this->refresh = $refresh;
    }

    /** "Yenile": starts the collection of every source whose action is a refresh; the feedback names each source. */
    public function refreshAll(DataSyncScopeService $sync, AsyncOperationService $async, DataStatusReader $reader): void
    {
        $asset = DigitalAsset::query()->findOrFail($this->assetId);
        $capabilities = collect($reader->forAsset($asset))->filter(fn (DataStatus $s): bool => $s->action === DataStatus::ACTION_REFRESH)->pluck('capability')->all();
        if ($capabilities === []) {
            [$this->feedback, $this->feedbackTone] = ['Yenilenecek bağlı kaynak yok.', 'warning'];

            return;
        }
        $lines = [];
        $ok = true;
        foreach ($capabilities as $capability) {
            $this->refreshSource($capability, $sync, $async, $reader);
            $lines[] = $this->feedback;
            $ok = $ok && $this->feedbackTone === 'success';
        }
        $this->feedback = implode(' ', array_unique($lines));
        $this->feedbackTone = $ok ? 'success' : 'warning';
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
        $worst = null;
        foreach (['bad', 'warn', 'muted', 'ok'] as $tone) {
            $worst ??= collect($statuses)->first(fn (DataStatus $s): bool => $s->tone() === $tone);
        }

        return view('livewire.operator.assets.data-status-strip', [
            'statuses' => $statuses,
            'asset' => $asset,
            'worst' => $worst,
            'canRefresh' => $this->refresh && collect($statuses)->contains(fn (DataStatus $s): bool => $s->action === DataStatus::ACTION_REFRESH),
            'polling' => collect($statuses)->contains(fn (DataStatus $s): bool => $s->collecting || $s->state === DataStatus::FIRST_LOAD),
        ]);
    }
}
