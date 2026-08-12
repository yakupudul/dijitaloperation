<?php

namespace App\Filament\App\Concerns;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\ComparisonPeriod;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\History\MetaHistoricalQueryService;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Workspace\MetaWorkspaceFilters;

trait InteractsWithMetaExpertWorkspace
{
    protected function metaExpertAsset(): ?DigitalAsset
    {
        if (method_exists($this, 'getOwnerRecord')) {
            /** @var Model|null $owner */
            $owner = $this->getOwnerRecord();
            if ($owner instanceof DigitalAsset) {
                return $owner;
            }
        }

        if (method_exists($this, 'getRecord')) {
            /** @var Model|null $record */
            $record = $this->getRecord();
            if ($record instanceof DigitalAsset) {
                return $record;
            }
        }

        return null;
    }

    public function setMetaWorkspaceFilter(string $key, mixed $value = null): void
    {
        $asset = $this->metaExpertAsset();
        if ($asset === null) {
            return;
        }

        $allowed = [
            'period_preset',
            'period_start',
            'period_end',
            'compare',
            'delivery',
            'objective',
            'search',
            'trend_metric',
            'expert_columns',
        ];

        if (! in_array($key, $allowed, true)) {
            return;
        }

        if ($key === 'compare') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
        }

        if ($key === 'expert_columns') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
        }

        MetaWorkspaceFilters::put((int) $asset->id, [$key => $value]);

        // Selecting a new date range must prepare uncovered history in the background,
        // so the workspace fills in without an explicit "Analyze this period" gate.
        if (in_array($key, ['period_preset', 'period_start', 'period_end'], true)) {
            $this->maybeQueueMetaGapEnrichForSelectedPeriod();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyMetaWorkspaceFilters(array $payload = []): void
    {
        $asset = $this->metaExpertAsset();
        if ($asset === null) {
            return;
        }

        $mapped = [];
        foreach (['period_preset', 'period_start', 'period_end', 'delivery', 'objective', 'search', 'trend_metric'] as $key) {
            if (array_key_exists($key, $payload)) {
                $mapped[$key] = $payload[$key];
            }
        }
        if (array_key_exists('compare', $payload)) {
            $mapped['compare'] = filter_var($payload['compare'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? (bool) $payload['compare'];
        }

        if ($mapped !== []) {
            MetaWorkspaceFilters::put((int) $asset->id, $mapped);
        }

        // When the operator selects a range the historical store does not fully cover,
        // silently prepare it in the background so the workspace fills in when ready.
        $this->maybeQueueMetaGapEnrichForSelectedPeriod();
    }

    /**
     * Incremental refresh of the bound Meta Ad Account (correction window through today).
     * Preferred over "Analyze this period" once historical coverage exists.
     */
    public function refreshMetaWorkspaceData(): void
    {
        $asset = $this->metaExpertAsset();
        if ($asset === null || $asset->type !== 'meta_ads') {
            return;
        }

        if ($this->metaWorkspaceBinding($asset) === null) {
            Notification::make()
                ->title('No Meta Ad Account bound')
                ->body('Bind a Meta Ad Account to this asset before refreshing historical data.')
                ->warning()
                ->send();

            return;
        }

        $user = auth()->user();
        $operator = $user instanceof User ? $user : null;

        $result = app(AsyncOperationService::class)->queueMetaHistoryRefresh($asset, $operator);

        if (($result['queued'] ?? false) === true) {
            Notification::make()
                ->title('Refresh queued')
                ->body($result['message'].' Current dashboard stays available until the refresh finishes.')
                ->success()
                ->send();

            return;
        }

        if (($result['existing_run'] ?? null) !== null) {
            Notification::make()
                ->title('Refresh already running')
                ->body($result['message'])
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Could not queue refresh')
            ->body($result['message'] ?? 'Unknown error')
            ->danger()
            ->send();
    }

    /**
     * Queues a background gap-enrich for the currently selected period when the
     * historical store does not fully cover it. No-op when already covered, importing,
     * outside the provider window, or a gap-enrich is already in flight.
     */
    public function maybeQueueMetaGapEnrichForSelectedPeriod(): void
    {
        $asset = $this->metaExpertAsset();
        if ($asset === null || $asset->type !== 'meta_ads') {
            return;
        }

        $binding = $this->metaWorkspaceBinding($asset);
        $resource = $binding?->externalResource;
        if ($resource === null) {
            return;
        }

        $filters = MetaWorkspaceFilters::get((int) $asset->id);

        try {
            $period = ComparisonPeriod::forPreset(
                $filters['period_preset'],
                $filters['period_start'],
                $filters['period_end'],
                false,
            )['current'];
        } catch (\Throwable) {
            return;
        }

        $from = (string) ($period['start'] ?? '');
        $to = (string) ($period['end'] ?? '');
        if ($from === '' || $to === '') {
            return;
        }

        $coverage = app(MetaHistoricalQueryService::class)->isRangeCovered(
            $resource,
            $from,
            $to,
            MetaAdsHistoryCoverage::LAYER_DAILY_FACTS,
        );

        if (in_array($coverage, ['complete', 'importing', 'outside_provider'], true)) {
            return;
        }

        $async = app(AsyncOperationService::class);
        if ($async->activeRun((int) $asset->id, AsyncOperationTypes::META_HISTORY_GAP_ENRICH) !== null) {
            return;
        }

        $user = auth()->user();
        $async->queueMetaHistoryGapEnrich($asset, $from, $to, $user instanceof User ? $user : null);
    }

    protected function metaWorkspaceBinding(DigitalAsset $asset): ?CoreAssetBinding
    {
        return CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', MetaHistoricalImportService::RESOURCE_TYPE)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->with('externalResource.integration')
            ->latest('id')
            ->first();
    }

    public function analyzeMetaSelectedPeriod(): void
    {
        $asset = $this->metaExpertAsset();
        if ($asset === null || $asset->type !== 'meta_ads') {
            return;
        }

        $filters = MetaWorkspaceFilters::get((int) $asset->id);
        $user = auth()->user();
        $operator = $user instanceof User ? $user : null;

        $result = app(AsyncOperationService::class)->queueBoundCollect($asset, $operator, [
            'period_preset' => $filters['period_preset'],
            'period_start' => $filters['period_start'],
            'period_end' => $filters['period_end'],
            'compare' => $filters['compare'],
            'human_title' => 'Analyze this period',
        ]);

        if (($result['queued'] ?? false) === true) {
            Notification::make()
                ->title('Period analysis queued')
                ->body($result['message'].' Current dashboard stays available until the new period finishes.')
                ->success()
                ->send();

            return;
        }

        if (($result['existing_run'] ?? null) !== null) {
            Notification::make()
                ->title('Collection already running')
                ->body($result['message'])
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Could not queue analysis')
            ->body($result['message'] ?? 'Unknown error')
            ->danger()
            ->send();
    }

    public function generateMetaAdsAiGuidanceFromWorkspace(): void
    {
        $asset = $this->metaExpertAsset();
        if ($asset === null || $asset->type !== 'meta_ads') {
            return;
        }

        $user = auth()->user();
        $operator = $user instanceof User ? $user : null;

        $result = app(AsyncOperationService::class)->queueMetaAdsAiGuidance($asset, $operator);

        if (($result['queued'] ?? false) === true) {
            Notification::make()
                ->title('AI analysis queued')
                ->body($result['message'] ?? 'Meta Ads AI guidance will appear in Insights when ready.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Could not queue AI analysis')
            ->body($result['message'] ?? 'Unknown error')
            ->warning()
            ->send();
    }
}
