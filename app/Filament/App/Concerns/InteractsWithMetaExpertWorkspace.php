<?php

namespace App\Filament\App\Concerns;

use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
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
