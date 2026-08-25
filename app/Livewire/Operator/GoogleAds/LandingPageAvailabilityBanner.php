<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

/**
 * Explains why the Landing Pages workspace is empty without fabricating data.
 *
 * The Google Ads account may be connected while the selected analytical period has
 * no landing_page_view rows. In that case we surface the latest period that actually
 * contains canonical landing-page observations so the operator can navigate there.
 */
class LandingPageAvailabilityBanner extends Component
{
    public string $assetId;

    public ?string $periodStart = null;

    public ?string $periodEnd = null;

    public function mount(string $assetId, ?string $periodStart = null, ?string $periodEnd = null): void
    {
        $this->assetId = $assetId;
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
    }

    public function render(): View
    {
        return view('livewire.operator.google-ads.landing-page-availability-banner', [
            'availability' => $this->availability(),
        ]);
    }

    /** @return array<string,mixed> */
    private function availability(): array
    {
        $base = [
            'state' => 'unavailable',
            'selected_rows' => 0,
            'historical_rows' => 0,
            'first_date' => null,
            'last_date' => null,
            'suggested_start' => null,
            'suggested_end' => null,
            'source_mode' => null,
        ];

        if (! Schema::hasTable('google_ads_landing_page_daily')) {
            return [...$base, 'state' => 'dataset_unavailable'];
        }

        $binding = app(GoogleAdsSpecialistBindingResolver::class)->resolve($this->assetId);
        if (
            $binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->externalResourceId === null
            || $binding->customerId === null
            || ! ctype_digit($this->assetId)
        ) {
            return [...$base, 'state' => 'not_connected'];
        }

        if (! filled($this->periodStart) || ! filled($this->periodEnd)) {
            return [...$base, 'state' => 'period_unavailable'];
        }

        $digitalAssetId = (int) $this->assetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;

        $canonicalBase = DB::table('google_ads_landing_page_daily')
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId);

        $hasCentralRows = (clone $canonicalBase)
            ->whereNull('digital_asset_id')
            ->exists();

        $scope = $this->scopedRows($canonicalBase, $digitalAssetId, $hasCentralRows)
            ->whereNotNull('landing_page')
            ->where('landing_page', '<>', '');

        $selectedRows = (clone $scope)
            ->whereBetween('reporting_date', [$this->periodStart, $this->periodEnd])
            ->count();

        if ($selectedRows > 0) {
            return [
                ...$base,
                'state' => 'ready',
                'selected_rows' => $selectedRows,
                'source_mode' => $hasCentralRows ? 'central' : 'asset_bound',
            ];
        }

        $history = (clone $scope)
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('MIN(reporting_date) as first_date')
            ->selectRaw('MAX(reporting_date) as last_date')
            ->first();

        $historicalRows = (int) ($history->rows_count ?? 0);
        $firstDate = filled($history->first_date ?? null) ? (string) $history->first_date : null;
        $lastDate = filled($history->last_date ?? null) ? (string) $history->last_date : null;

        if ($historicalRows === 0 || $lastDate === null) {
            return [
                ...$base,
                'state' => 'no_stored_rows',
                'source_mode' => $hasCentralRows ? 'central' : 'asset_bound',
            ];
        }

        $latest = CarbonImmutable::parse($lastDate)->startOfDay();
        $earliest = $firstDate !== null
            ? CarbonImmutable::parse($firstDate)->startOfDay()
            : $latest;
        $suggestedStart = $latest->subDays(27);
        if ($suggestedStart->lessThan($earliest)) {
            $suggestedStart = $earliest;
        }

        return [
            ...$base,
            'state' => 'selected_period_empty',
            'historical_rows' => $historicalRows,
            'first_date' => $firstDate,
            'last_date' => $lastDate,
            'suggested_start' => $suggestedStart->toDateString(),
            'suggested_end' => $latest->toDateString(),
            'source_mode' => $hasCentralRows ? 'central' : 'asset_bound',
        ];
    }

    private function scopedRows(Builder $base, int $digitalAssetId, bool $central): Builder
    {
        return $central
            ? $base->whereNull('digital_asset_id')
            : $base->where('digital_asset_id', $digitalAssetId);
    }
}
