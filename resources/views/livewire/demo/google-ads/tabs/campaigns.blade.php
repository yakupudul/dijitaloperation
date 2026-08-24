@php
    $isTr = app()->getLocale() === 'tr';
    $currency = (string) (data_get($identity, 'currency') ?: ($professional['currency'] ?? ''));
    $money = fn ($v) => is_numeric($v) ? trim(number_format((float) $v, 2, ',', '.').' '.$currency) : '—';
    $number = fn ($v, int $d = 0) => is_numeric($v) ? number_format((float) $v, $d, ',', '.') : '—';
    $percent = fn ($v) => is_numeric($v) ? number_format((float) $v, 1, ',', '.').'%' : '—';
    $statusLabel = function ($status) use ($isTr) {
        return match (strtoupper((string) $status)) {
            'ENABLED' => $isTr ? 'Etkin' : 'Enabled',
            'PAUSED' => $isTr ? 'Duraklatıldı' : 'Paused',
            'REMOVED' => $isTr ? 'Kaldırıldı' : 'Removed',
            default => filled($status) ? (string) $status : '—',
        };
    };
    $statusTone = fn ($status) => strtoupper((string) $status) === 'ENABLED'
        ? 'success'
        : (strtoupper((string) $status) === 'PAUSED' ? 'light' : 'warning');

    $campaigns = collect($campaignRows ?? []);
    $adGroups = collect(data_get($professional, 'ad_groups', []));
    $ads = collect(data_get($professional, 'ad_daily', []));
    $campaignOptions = collect(data_get($professional, 'campaign_options', []));
    $adGroupOptions = collect(data_get($professional, 'ad_group_options', []));
    $query = mb_strtolower(trim((string) $entity_query));

    $campaignTypes = $campaigns->pluck('type')->filter()->unique()->sort()->values();
    $adTypes = $ads->pluck('type')->filter()->unique()->sort()->values();
    $visibleAdGroupOptions = $adGroupOptions
        ->filter(fn ($row) => $entity_campaign === 'all' || (string) ($row['campaign_id'] ?? '') === (string) $entity_campaign)
        ->values();

    $filteredCampaigns = $campaigns->filter(function ($row) use ($entity_campaign, $entity_status, $entity_type, $query) {
        if ($entity_campaign !== 'all' && (string) ($row['id'] ?? '') !== (string) $entity_campaign) return false;
        if ($entity_status !== 'all' && strtoupper((string) ($row['status'] ?? '')) !== strtoupper($entity_status)) return false;
        if ($entity_type !== 'all' && (string) ($row['type'] ?? '') !== (string) $entity_type) return false;
        if ($query !== '' && ! str_contains(mb_strtolower((string) ($row['name'] ?? '').' '.(string) ($row['id'] ?? '')), $query)) return false;
        return true;
    })->values();

    $filteredAdGroups = $adGroups->filter(function ($row) use ($entity_campaign, $entity_status, $query) {
        if ($entity_campaign !== 'all' && (string) ($row['campaign_id'] ?? '') !== (string) $entity_campaign) return false;
        if ($entity_status !== 'all' && strtoupper((string) ($row['status'] ?? '')) !== strtoupper($entity_status)) return false;
        $haystack = mb_strtolower((string) ($row['ad_group_name'] ?? '').' '.(string) ($row['ad_group_id'] ?? '').' '.(string) ($row['campaign_name'] ?? ''));
        return $query === '' || str_contains($haystack, $query);
    })->values();

    $filteredAds = $ads->filter(function ($row) use ($entity_campaign, $entity_ad_group, $entity_status, $entity_type, $query) {
        if ($entity_campaign !== 'all' && (string) ($row['campaign_id'] ?? '') !== (string) $entity_campaign) return false;
        if ($entity_ad_group !== 'all' && (string) ($row['ad_group_id'] ?? '') !== (string) $entity_ad_group) return false;
        if ($entity_status !== 'all' && strtoupper((string) ($row['status'] ?? '')) !== strtoupper($entity_status)) return false;
        if ($entity_type !== 'all' && filled($row['type'] ?? null) && (string) ($row['type'] ?? '') !== (string) $entity_type) return false;
        $haystack = mb_strtolower((string) ($row['ad_name'] ?? '').' '.(string) ($row['ad_id'] ?? '').' '.(string) ($row['ad_group_name'] ?? '').' '.(string) ($row['campaign_name'] ?? ''));
        return $query === '' || str_contains($haystack, $query);
    })->values();
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kampanyalar' : 'Campaigns' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kampanya → reklam grubu → reklam hiyerarşisini tek çalışma alanında yönetin.' : 'Inspect the Campaign → Ad group → Ad hierarchy in one workspace.' }}</p>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" aria-label="Campaign entity tabs">
        @foreach ([
            'campaigns' => $isTr ? 'Kampanyalar' : 'Campaigns',
            'ad_groups' => $isTr ? 'Reklam Grupları' : 'Ad groups',
            'ads' => $isTr ? 'Reklamlar' : 'Ads',
        ] as $key => $label)
            <button type="button" wire:click="setCampaignSub('{{ $key }}')" @class([
                'border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $campaign_sub === $key,
                'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $campaign_sub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </nav>

    <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap gap-2">
            <input type="search" wire:model.live.debounce.300ms="entity_query" placeholder="{{ $isTr ? 'Ara…' : 'Search…' }}" class="min-w-[220px] flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white lg:max-w-sm">

            <select wire:model.live="entity_campaign" class="min-w-[220px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                <option value="all">{{ $isTr ? 'Tüm kampanyalar' : 'All campaigns' }}</option>
                @foreach ($campaignOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>

            @if ($campaign_sub === 'ads')
                <select wire:model.live="entity_ad_group" class="min-w-[220px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="all">{{ $isTr ? 'Tüm reklam grupları' : 'All ad groups' }}</option>
                    @foreach ($visibleAdGroupOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            @endif

            <select wire:model.live="entity_status" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                <option value="all">{{ $isTr ? 'Tüm durumlar' : 'All statuses' }}</option>
                <option value="ENABLED">{{ $isTr ? 'Etkin' : 'Enabled' }}</option>
                <option value="PAUSED">{{ $isTr ? 'Duraklatıldı' : 'Paused' }}</option>
                <option value="REMOVED">{{ $isTr ? 'Kaldırıldı' : 'Removed' }}</option>
            </select>

            @if ($campaign_sub === 'campaigns')
                <select wire:model.live="entity_type" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="all">{{ $isTr ? 'Tüm türler' : 'All types' }}</option>
                    @foreach ($campaignTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                </select>
            @elseif ($campaign_sub === 'ads')
                <select wire:model.live="entity_type" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="all">{{ $isTr ? 'Tüm reklam türleri' : 'All ad types' }}</option>
                    @foreach ($adTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                </select>
            @endif

            <button type="button" wire:click="resetEntityFilters" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Temizle' : 'Clear' }}</button>
        </div>
    </div>

    @if ($campaign_sub === 'campaigns')
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kampanya envanteri' : 'Campaign inventory' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Seçili dönemde harcaması olmayan kampanyalar da envanterde kalır.' : 'Campaigns remain in inventory even with no spend in the selected period.' }}</p></div>
                <span class="text-xs text-gray-500">{{ $filteredCampaigns->count() }} / {{ $campaigns->count() }}</span>
            </header>
            <div class="overflow-x-auto"><table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-4 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Tür' : 'Type' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Bütçe' : 'Budget' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th><th class="px-3 py-2.5 text-right">CPA</th><th class="px-3 py-2.5 text-right">Search IS</th><th class="px-4 py-2.5"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($filteredCampaigns as $row)
                        @php $cpa = is_numeric($row['spend'] ?? null) && is_numeric($row['leads'] ?? null) && (float) $row['leads'] > 0 ? (float) $row['spend'] / (float) $row['leads'] : null; @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]"><td class="px-4 py-2.5"><p class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</p><p class="mt-0.5 text-[11px] text-gray-400">ID {{ $row['id'] }}@if(empty($row['period_activity'])) · {{ $isTr ? 'bu dönemde aktivite yok' : 'no period activity' }}@endif</p></td><td class="px-3 py-2.5"><x-ta.badge :color="$statusTone($row['status'] ?? null)" size="sm">{{ $statusLabel($row['status'] ?? null) }}</x-ta.badge></td><td class="px-3 py-2.5">{{ $row['type'] ?? '—' }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['budget'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['spend'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['leads'] ?? null, 2) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $cpa !== null ? $money($cpa) : '—' }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $percent($row['impr_share'] ?? null) }}</td><td class="px-4 py-2.5 text-right"><button type="button" wire:click="openCampaign('{{ $row['id'] }}')" class="text-xs font-semibold text-brand-600 hover:underline">{{ $isTr ? 'Detay' : 'Details' }}</button></td></tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Bu filtrelerle eşleşen kampanya yok.' : 'No campaigns match these filters.' }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>
    @elseif ($campaign_sub === 'ad_groups')
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam grupları' : 'Ad groups' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Kampanya adıyla birlikte temiz hiyerarşik görünüm.' : 'Clean hierarchical view with campaign names.' }}</p></div><span class="text-xs text-gray-500">{{ $filteredAdGroups->count() }} / {{ $adGroups->count() }}</span></header>
            <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-4 py-2.5 text-left">{{ $isTr ? 'Reklam grubu' : 'Ad group' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th><th class="px-4 py-2.5 text-right">CPA</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($filteredAdGroups as $row)
                    <tr><td class="px-4 py-2.5"><p class="font-medium text-gray-900 dark:text-white">{{ $row['ad_group_name'] ?? ('Ad group '.($row['ad_group_id'] ?? '')) }}</p><p class="mt-0.5 text-[11px] text-gray-400">ID {{ $row['ad_group_id'] ?? '—' }}@if(empty($row['period_activity'])) · {{ $isTr ? 'bu dönemde aktivite yok' : 'no period activity' }}@endif</p></td><td class="px-3 py-2.5">{{ $row['campaign_name'] ?? '—' }}</td><td class="px-3 py-2.5"><x-ta.badge :color="$statusTone($row['status'] ?? null)" size="sm">{{ $statusLabel($row['status'] ?? null) }}</x-ta.badge></td><td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['cost'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['clicks'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['conversions'] ?? null, 2) }}</td><td class="px-4 py-2.5 text-right tabular-nums">{{ $money($row['cpa'] ?? null) }}</td></tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Bu filtrelerle eşleşen reklam grubu yok.' : 'No ad groups match these filters.' }}</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    @else
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Reklamlar' : 'Ads' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Her reklamı reklam grubu ve kampanya bağlamında görün.' : 'See every ad in ad-group and campaign context.' }}</p></div><span class="text-xs text-gray-500">{{ $filteredAds->count() }} / {{ $ads->count() }}</span></header>
            <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-4 py-2.5 text-left">{{ $isTr ? 'Reklam' : 'Ad' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Reklam grubu' : 'Ad group' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th><th class="px-4 py-2.5 text-right">CPA</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($filteredAds as $row)
                    <tr><td class="px-4 py-2.5"><p class="font-medium text-gray-900 dark:text-white">{{ $row['ad_name'] ?? ('Ad '.($row['ad_id'] ?? '')) }}</p><p class="mt-0.5 text-[11px] text-gray-400">{{ $row['type'] ?? '—' }} · ID {{ $row['ad_id'] ?? '—' }}@if(empty($row['period_activity'])) · {{ $isTr ? 'bu dönemde aktivite yok' : 'no period activity' }}@endif</p></td><td class="px-3 py-2.5">{{ $row['ad_group_name'] ?? '—' }}</td><td class="px-3 py-2.5">{{ $row['campaign_name'] ?? '—' }}</td><td class="px-3 py-2.5"><x-ta.badge :color="$statusTone($row['status'] ?? null)" size="sm">{{ $statusLabel($row['status'] ?? null) }}</x-ta.badge></td><td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['cost'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['clicks'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['conversions'] ?? null, 2) }}</td><td class="px-4 py-2.5 text-right tabular-nums">{{ $money($row['cpa'] ?? null) }}</td></tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Bu filtrelerle eşleşen reklam yok.' : 'No ads match these filters.' }}</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    @endif

    <div class="rounded-xl bg-blue-50 px-4 py-3 text-xs text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        {{ $isTr ? 'Entity envanteri snapshot verisinden, tarih aralığına bağlı metrikler günlük performans verisinden gelir. Bu nedenle yalnız harcama yapan kayıtlarla sınırlı bir liste gösterilmez.' : 'Entity inventory comes from snapshots while date-range metrics come from daily performance, so the list is not restricted to entities with spend.' }}
    </div>
</div>

@if ($selectedCampaign && $campaign_sub === 'campaigns')
    @php $drawerCpa = is_numeric($selectedCampaign['spend'] ?? null) && is_numeric($selectedCampaign['leads'] ?? null) && (float) $selectedCampaign['leads'] > 0 ? (float) $selectedCampaign['spend'] / (float) $selectedCampaign['leads'] : null; @endphp
    <x-demo.gads-drawer :title="$selectedCampaign['name']" :subtitle="($selectedCampaign['type'] ?? 'Google Ads').' · '.$statusLabel($selectedCampaign['status'] ?? null)">
        <div class="grid grid-cols-2 gap-3">
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Harcama' : 'Spend' }}</p><p class="font-semibold">{{ $money($selectedCampaign['spend'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</p><p class="font-semibold">{{ $number($selectedCampaign['leads'] ?? null, 2) }}</p></div>
            <div><p class="text-xs text-gray-400">CPA</p><p class="font-semibold">{{ $drawerCpa !== null ? $money($drawerCpa) : '—' }}</p></div>
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Günlük bütçe' : 'Daily budget' }}</p><p class="font-semibold">{{ $money($selectedCampaign['budget'] ?? null) }}</p></div>
        </div>
        <button type="button" wire:click="showCampaignAdGroups('{{ $selectedCampaign['id'] }}')" class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white">{{ $isTr ? 'Bu kampanyanın reklam grupları' : 'View this campaign’s ad groups' }}</button>
    </x-demo.gads-drawer>
@endif