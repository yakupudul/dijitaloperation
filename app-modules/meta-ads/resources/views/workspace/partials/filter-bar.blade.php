@php
    use App\Support\Integrations\ComparisonPeriod;
    use MoxDop\MetaAds\Workspace\MetaWorkspaceFilters;

    /** @var array<string, mixed> $data */
    $filters = $data['filters'] ?? MetaWorkspaceFilters::get(0);
    unset($presets); // keep all presets including last 28 (collector default)
    $presets = $data['preset_labels'] ?? ComparisonPeriod::presetLabels();
    $objectives = collect($data['campaigns'] ?? [])
        ->pluck('objective')
        ->filter()
        ->unique()
        ->sort()
        ->values();
@endphp

<section class="mox-meta-filter-bar" aria-label="Workspace filters">
    <div class="mox-meta-filter-bar__row">
        <label class="mox-meta-filter">
            <span>Period</span>
            <select
                wire:change="setMetaWorkspaceFilter('period_preset', $event.target.value)"
            >
                @foreach ($presets as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['period_preset'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="mox-meta-filter">
            <span>Compare</span>
            <select wire:change="setMetaWorkspaceFilter('compare', $event.target.value)">
                <option value="1" @selected(($filters['compare'] ?? true) === true)>Previous period</option>
                <option value="0" @selected(($filters['compare'] ?? true) === false)>Off</option>
            </select>
        </label>

        <label class="mox-meta-filter">
            <span>Delivery</span>
            <select wire:change="setMetaWorkspaceFilter('delivery', $event.target.value)">
                <option value="{{ MetaWorkspaceFilters::DELIVERY_DELIVERED }}" @selected(($filters['delivery'] ?? '') === MetaWorkspaceFilters::DELIVERY_DELIVERED)>Delivered in selected period</option>
                <option value="{{ MetaWorkspaceFilters::DELIVERY_ACTIVE }}" @selected(($filters['delivery'] ?? '') === MetaWorkspaceFilters::DELIVERY_ACTIVE)>Active now</option>
                <option value="{{ MetaWorkspaceFilters::DELIVERY_PAUSED }}" @selected(($filters['delivery'] ?? '') === MetaWorkspaceFilters::DELIVERY_PAUSED)>Paused</option>
                <option value="{{ MetaWorkspaceFilters::DELIVERY_ALL }}" @selected(($filters['delivery'] ?? '') === MetaWorkspaceFilters::DELIVERY_ALL)>All</option>
            </select>
        </label>

        <label class="mox-meta-filter">
            <span>Objective</span>
            <select wire:change="setMetaWorkspaceFilter('objective', $event.target.value)">
                <option value="" @selected(($filters['objective'] ?? '') === '')>All</option>
                @foreach ($objectives as $objective)
                    <option value="{{ $objective }}" @selected(($filters['objective'] ?? '') === $objective)>{{ $objective }}</option>
                @endforeach
            </select>
        </label>

        <label class="mox-meta-filter mox-meta-filter--grow">
            <span>Campaign search</span>
            <input
                type="search"
                value="{{ $filters['search'] ?? '' }}"
                placeholder="Search campaigns"
                wire:change="setMetaWorkspaceFilter('search', $event.target.value)"
            >
        </label>
    </div>
</section>
