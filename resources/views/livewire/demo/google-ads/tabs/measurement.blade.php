<livewire:operator.google-ads.measurement-control-panel
    :asset-id="$this->assetId"
    :period="$this->period"
    :period-start="$this->periodStart"
    :period-end="$this->periodEnd"
    :key="'google-ads-measurement-'.$this->assetId.'-'.$this->period.'-'.($this->periodStart ?? 'na').'-'.($this->periodEnd ?? 'na')"
/>
