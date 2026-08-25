<div class="space-y-4">
    <livewire:operator.google-ads.landing-page-availability-banner
        :asset-id="$this->assetId"
        :period-start="$this->periodStart"
        :period-end="$this->periodEnd"
        :key="'google-ads-landing-availability-'.$this->assetId.'-'.$this->period.'-'.($this->periodStart ?? 'na').'-'.($this->periodEnd ?? 'na')"
    />

    <livewire:operator.google-ads.landing-page-control-panel
        :asset-id="$this->assetId"
        :period="$this->period"
        :period-start="$this->periodStart"
        :period-end="$this->periodEnd"
        :key="'google-ads-landing-pages-'.$this->assetId.'-'.$this->period.'-'.($this->periodStart ?? 'na').'-'.($this->periodEnd ?? 'na')"
    />
</div>
