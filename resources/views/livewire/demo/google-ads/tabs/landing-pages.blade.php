<livewire:operator.google-ads.landing-page-control-panel
    :asset-id="$this->assetId"
    :period="$this->period"
    :period-start="$this->periodStart"
    :period-end="$this->periodEnd"
    :key="'google-ads-landing-pages-'.$this->assetId.'-'.$this->period.'-'.($this->periodStart ?? 'na').'-'.($this->periodEnd ?? 'na')"
/>
