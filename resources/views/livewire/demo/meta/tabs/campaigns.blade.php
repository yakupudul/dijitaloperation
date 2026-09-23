@php
    $isTr = app()->getLocale() === 'tr';
    $campaigns = $professional['campaigns'] ?? [];
    $adsets = $professional['adsets'] ?? [];
    $ads = $professional['ads'] ?? [];
    $level = in_array($campaign_level ?? 'campaigns', ['campaigns', 'adsets', 'ads'], true) ? ($campaign_level ?? 'campaigns') : 'campaigns';
    $sortableColumns = [
        'spend' => $isTr ? 'Harcama' : 'Spend',
        'impressions' => $isTr ? 'Gösterim' : 'Impressions',
        'cpm' => 'CPM',
        'ctr' => 'CTR',
        'results' => __('operator_meta.explorer.results'),
        'cost_per_result' => __('operator_meta.explorer.cost_per_result'),
    ];
    $inventory = $professional['campaign_inventory'] ?? ['total' => count($campaigns), 'with_period_activity' => count($campaigns), 'without_period_activity' => 0];

    $statusLabel = static function (?string $status) use ($isTr): string {
        $value = strtoupper(trim((string) $status));
        if (! $isTr) return $value !== '' ? str_replace('_', ' ', $value) : '—';

        return match ($value) {
            'ACTIVE' => 'Aktif',
            'PAUSED' => 'Durduruldu',
            'PENDING_REVIEW' => 'İncelemede',
            'IN_PROCESS' => 'İşleniyor',
            'WITH_ISSUES' => 'Sorun Var',
            'ARCHIVED' => 'Arşivlendi',
            'DELETED' => 'Silindi',
            'DISAPPROVED' => 'Onaylanmadı',
            'PREAPPROVED' => 'Ön Onaylı',
            'CAMPAIGN_PAUSED' => 'Kampanya Durduruldu',
            'ADSET_PAUSED' => 'Reklam Seti Durduruldu',
            'UNKNOWN', '' => 'Bilinmiyor',
            default => str_replace('_', ' ', $value),
        };
    };

    $objectiveLabel = static function (?string $objective) use ($isTr): string {
        $value = strtoupper(trim((string) $objective));
        if (! $isTr) return $value !== '' ? str_replace('_', ' ', $value) : '—';

        return match ($value) {
            'OUTCOME_LEADS', 'LEAD_GENERATION' => 'Lead Toplama',
            'OUTCOME_SALES', 'CONVERSIONS' => 'Satış / Dönüşüm',
            'OUTCOME_TRAFFIC', 'LINK_CLICKS' => 'Trafik',
            'OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT' => 'Etkileşim',
            'OUTCOME_AWARENESS', 'BRAND_AWARENESS', 'REACH' => 'Bilinirlik',
            'OUTCOME_APP_PROMOTION', 'APP_INSTALLS' => 'Uygulama Tanıtımı',
            'MESSAGES' => 'Mesajlaşma',
            '', 'UNKNOWN' => '—',
            default => str_replace('_', ' ', $value),
        };
    };

    $optimizationLabel = static function (?string $value) use ($isTr): string {
        if (! filled($value)) return '—';
        if (! $isTr) return str_replace('_', ' ', (string) $value);

        return match (strtoupper((string) $value)) {
            'OFFSITE_CONVERSIONS', 'CONVERSIONS' => 'Dönüşümler',
            'LANDING_PAGE_VIEWS' => 'Açılış Sayfası Görüntülemeleri',
            'LINK_CLICKS' => 'Bağlantı Tıklamaları',
            'CONVERSATIONS' => 'Mesajlaşmalar',
            'LEAD_GENERATION', 'LEADS' => 'Lead Toplama',
            'PROFILE_VISIT', 'PROFILE_VISITS' => 'Profil Ziyaretleri',
            'REACH' => 'Erişim',
            'IMPRESSIONS' => 'Gösterimler',
            'POST_ENGAGEMENT' => 'Gönderi Etkileşimi',
            'VIDEO_VIEWS' => 'Video İzlemeleri',
            default => str_replace('_', ' ', (string) $value),
        };
    };

    $destinationLabel = static function (?string $value) use ($isTr): string {
        if (! filled($value)) return '—';
        if (! $isTr) return str_replace('_', ' ', (string) $value);

        return match (strtoupper((string) $value)) {
            'WEBSITE' => 'Web Sitesi',
            'MESSAGING_WHATSAPP', 'WHATSAPP' => 'WhatsApp',
            'MESSAGING_MESSENGER', 'MESSENGER' => 'Messenger',
            'MESSAGING_INSTAGRAM_DIRECT', 'INSTAGRAM_DIRECT' => 'Instagram Mesajları',
            'ON_AD', 'ON_FACEBOOK' => 'Meta Üzerinde',
            'APP' => 'Uygulama',
            default => str_replace('_', ' ', (string) $value),
        };
    };

    $explorerView = $explorer ?? ['level' => $level, 'campaign' => null, 'adset' => null, 'rows' => [], 'counts' => ['campaigns' => count($campaigns), 'adsets' => count($adsets), 'ads' => count($ads)], 'sort' => 'spend', 'direction' => 'desc'];
    $level = $explorerView['level'];
    $currentRows = $explorerView['rows'];
    $crumbCampaign = $explorerView['campaign'];
    $crumbAdset = $explorerView['adset'];
    $sortKey = $explorerView['sort'];
    $sortDirection = $explorerView['direction'];
    $money = static fn ($amount, ?string $currencyCode): string => $amount !== null ? trim(($currencyCode ?? '').' '.number_format((float) $amount, 2)) : '—';

    $budgetLabel = static function (array $row) use ($isTr): string {
        $currencyCode = (string) ($row['currency'] ?? '');
        $format = static fn ($amount): string => trim($currencyCode.' '.number_format((float) $amount, 2));
        if (is_numeric($row['daily_budget'] ?? null) && (float) $row['daily_budget'] > 0) {
            return $format($row['daily_budget']).($isTr ? ' / gün' : ' / day');
        }
        if (is_numeric($row['lifetime_budget'] ?? null) && (float) $row['lifetime_budget'] > 0) {
            return $format($row['lifetime_budget']).($isTr ? ' toplam' : ' lifetime');
        }

        return '—';
    };

    $currentTitle = match ($level) {
        'adsets' => $isTr ? 'Reklam Setleri' : 'Ad Sets',
        'ads' => $isTr ? 'Reklamlar' : 'Ads',
        default => $isTr ? 'Kampanyalar' : 'Campaigns',
    };
@endphp

<section class="space-y-5">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kampanyalar ve Reklamlar' : 'Campaigns and Ads' }}</p>
            <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Hangi kampanya ne kadar harcadı ve ne üretti?' : 'What did each campaign spend and produce?' }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Hesaptaki tüm kampanyalar envanterde tutulur. Seçili dönemde çalışmayan kampanyalar da kaybolmaz; performans değerleri yalnızca gerçek aktivite varsa gösterilir.' : 'All campaigns remain in inventory, including campaigns without activity in the selected period.' }}</p>
            <p class="mt-2 text-xs font-medium text-gray-400">{{ $isTr ? 'Toplam '.number_format((int)$inventory['total']).' kampanya · '.number_format((int)$inventory['with_period_activity']).' tanesinde seçili dönemde aktivite var' : number_format((int)$inventory['total']).' campaigns total · '.number_format((int)$inventory['with_period_activity']).' active in period' }}</p>
        </div>

        <div class="inline-flex w-fit rounded-xl bg-gray-100 p-1 dark:bg-white/[0.05]">
            @foreach ([
                'campaigns' => [$isTr ? 'Kampanyalar' : 'Campaigns', $explorerView['counts']['campaigns']],
                'adsets' => [$isTr ? 'Reklam Setleri' : 'Ad Sets', $explorerView['counts']['adsets']],
                'ads' => [$isTr ? 'Reklamlar' : 'Ads', $explorerView['counts']['ads']],
            ] as $key => [$label, $count])
                <button type="button" wire:click="setCampaignLevel('{{ $key }}')"
                    class="rounded-lg px-3 py-2 text-sm font-semibold {{ $level === $key ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $label }} <span class="ml-1 text-xs opacity-60">{{ $count }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-col gap-2 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <nav aria-label="{{ __('operator_meta.explorer.breadcrumb') }}" class="mb-1 flex flex-wrap items-center gap-1 text-xs font-medium text-gray-400">
                    @if ($crumbCampaign || $crumbAdset)
                        <button type="button" wire:click="drillUp('')" class="text-brand-600 hover:underline dark:text-brand-400">{{ __('operator_meta.explorer.all_campaigns') }}</button>
                    @else
                        <span>{{ __('operator_meta.explorer.all_campaigns') }}</span>
                    @endif
                    @if ($crumbCampaign)
                        <span aria-hidden="true">›</span>
                        @if ($crumbAdset)
                            <button type="button" wire:click="drillUp('campaign')" class="max-w-[16rem] truncate text-brand-600 hover:underline dark:text-brand-400">{{ __('operator_meta.explorer.campaign_crumb', ['name' => $crumbCampaign['name']]) }}</button>
                        @else
                            <span class="max-w-[16rem] truncate text-gray-600 dark:text-gray-300">{{ __('operator_meta.explorer.campaign_crumb', ['name' => $crumbCampaign['name']]) }}</span>
                        @endif
                    @endif
                    @if ($crumbAdset)
                        <span aria-hidden="true">›</span>
                        <span class="max-w-[16rem] truncate text-gray-600 dark:text-gray-300">{{ __('operator_meta.explorer.adset_crumb', ['name' => $crumbAdset['name']]) }}</span>
                    @endif
                </nav>
                <h3 class="font-bold text-gray-900 dark:text-white">{{ $currentTitle }}</h3>
                <p class="mt-0.5 text-xs text-gray-400">{{ $level === 'ads' ? __('operator_meta.explorer.hint_ads') : __('operator_meta.explorer.hint_drill') }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <span class="text-xs font-medium text-gray-400">{{ $professional['period_start'] ?? '—' }} → {{ $professional['period_end'] ?? '—' }}</span>
                <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" @disabled($currentRows === []) class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/[0.04]">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3v10m0 0 4-4m-4 4-4-4M4 16h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    {{ __('operator_meta.explorer.export_csv') }}
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-left dark:divide-gray-800">
                <thead class="bg-gray-50/80 dark:bg-white/[0.02]">
                    <tr class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                        <th class="px-5 py-3">{{ $isTr ? 'Ad' : 'Name' }}</th>
                        <th class="px-4 py-3">{{ $isTr ? 'Bağlam' : 'Context' }}</th>
                        <th class="px-4 py-3">{{ $isTr ? 'Durum' : 'Status' }}</th>
                        @if ($level === 'campaigns')<th class="px-4 py-3 text-right">{{ $isTr ? 'Bütçe' : 'Budget' }}</th>@endif
                        @foreach (['spend', 'impressions', 'cpm', 'ctr'] as $column)
                            <th class="px-4 py-3 text-right"><button type="button" wire:click="sortCampaignsBy('{{ $column }}')" class="inline-flex items-center gap-1 uppercase {{ $sortKey === $column ? 'text-gray-700 dark:text-gray-200' : '' }}">{{ $sortableColumns[$column] }}@if ($sortKey === $column)<span aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif</button></th>
                        @endforeach
                        <th class="px-4 py-3 text-right">CPC</th>
                        @foreach (['results', 'cost_per_result'] as $column)
                            <th class="whitespace-nowrap px-4 py-3 text-right"><button type="button" wire:click="sortCampaignsBy('{{ $column }}')" class="inline-flex items-center gap-1 uppercase {{ $sortKey === $column ? 'text-gray-700 dark:text-gray-200' : '' }}">{{ $sortableColumns[$column] }}@if ($sortKey === $column)<span aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif</button></th>
                        @endforeach
                        <th class="min-w-[220px] px-5 py-3">{{ $isTr ? 'Öne Çıkan Sonuçlar' : 'Headline Outcomes' }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($currentRows as $row)
                        @php
                            $summaryActions = $row['summary_actions'] ?? [];
                            $contextPrimary = '—';
                            $contextSecondary = null;

                            if ($level === 'campaigns') {
                                $contextPrimary = $objectiveLabel($row['objective'] ?? null);
                            } elseif ($level === 'adsets') {
                                $contextPrimary = $row['campaign_name'] ?? '—';
                                $optimization = $optimizationLabel($row['optimization_goal'] ?? null);
                                $destination = $destinationLabel($row['destination_type'] ?? null);
                                $contextSecondary = $optimization.($destination !== '—' ? ' · '.$destination : '');
                            } else {
                                $contextPrimary = $row['campaign_name'] ?? '—';
                                $contextSecondary = $row['adset_name'] ?? null;
                            }
                        @endphp

                        <tr class="hover:bg-gray-50/70 dark:hover:bg-white/[0.02] {{ $level !== 'ads' ? 'cursor-pointer' : '' }}"
                            @if ($level === 'campaigns') wire:click="drillCampaign({{ \Illuminate\Support\Js::from((string) $row['id']) }})" wire:key="meta-row-c-{{ $row['id'] }}"
                            @elseif ($level === 'adsets') wire:click="drillAdset({{ \Illuminate\Support\Js::from((string) $row['id']) }}, {{ \Illuminate\Support\Js::from((string) ($row['campaign_id'] ?? '')) }})" wire:key="meta-row-s-{{ $row['id'] }}"
                            @else wire:key="meta-row-a-{{ $row['id'] }}" @endif>
                            <td class="max-w-xs px-5 py-3.5">
                                <p class="truncate text-sm font-semibold {{ $level !== 'ads' ? 'text-brand-700 dark:text-brand-400' : 'text-gray-800 dark:text-gray-200' }}">{{ $row['name'] }}@if ($level !== 'ads')<span class="ml-1 text-gray-300" aria-hidden="true">›</span>@endif</p>
                                <p class="mt-0.5 text-[11px] text-gray-400">
                                    ID {{ $row['id'] }}
                                    @if ($level === 'campaigns' && !($row['has_period_activity'] ?? true)) · {{ $isTr ? 'Bu dönemde aktivite yok' : 'No activity in period' }} @endif
                                    @if ($level === 'ads' && !empty($row['creative_id'])) · {{ $isTr ? 'Kreatif' : 'Creative' }} {{ $row['creative_id'] }} @endif
                                </p>
                            </td>
                            <td class="max-w-xs px-4 py-3.5">
                                <p class="truncate text-xs font-medium text-gray-600 dark:text-gray-300">{{ $contextPrimary }}</p>
                                @if (filled($contextSecondary))<p class="mt-0.5 truncate text-[11px] text-gray-400">{{ $contextSecondary }}</p>@endif
                            </td>
                            <td class="px-4 py-3.5"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $statusLabel($row['effective_status'] ?? $row['status'] ?? null) }}</span></td>
                            @if ($level === 'campaigns')<td class="whitespace-nowrap px-4 py-3.5 text-right text-xs tabular-nums text-gray-600 dark:text-gray-300">{{ $budgetLabel($row) }}</td>@endif
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-sm font-semibold tabular-nums">{{ $row['spend_display'] ?? $money($row['spend'] ?? null, $row['currency'] ?? null) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ number_format((int) ($row['impressions'] ?? 0)) }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-sm tabular-nums">{{ $money($row['cpm'] ?? null, $row['currency'] ?? null) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ ($row['ctr'] ?? null) !== null ? number_format($row['ctr'], 2).'%' : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-sm tabular-nums">{{ $money($row['cpc'] ?? null, $row['currency'] ?? null) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums" @if (filled($row['result_label'] ?? null)) title="{{ $row['result_label'] }}" @endif>
                                @if (($row['results'] ?? null) !== null)
                                    <span class="font-semibold text-gray-900 dark:text-white">{{ number_format(round((float) $row['results'])) }}</span>
                                    @if (filled($row['result_label'] ?? null))<p class="max-w-[9rem] truncate text-[10px] text-gray-400">{{ $isTr ? $row['result_label'] : ($row['result_type'] ?? '') }}</p>@endif
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-sm tabular-nums">{{ $money($row['cost_per_result'] ?? null, $row['currency'] ?? null) }}</td>
                            <td class="px-5 py-3.5">
                                @forelse (array_slice($summaryActions, 0, 2) as $action)
                                    <div class="flex justify-between gap-3 text-xs"><span class="truncate text-gray-600 dark:text-gray-300">{{ $isTr ? $action['label_tr'] : $action['label_en'] }}</span><strong class="tabular-nums text-gray-900 dark:text-white">{{ number_format(round((float) $action['value'])) }}</strong></div>
                                @empty
                                    <span class="text-xs text-gray-300">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $level === 'campaigns' ? 12 : 11 }}" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Bu seviyede kullanılabilir veri yok.' : 'No usable data at this level.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">{{ $isTr ? 'CTR: tıklama oranı · CPC: tıklama başına maliyet · CPM: bin gösterim başına maliyet. Bütçe, kampanya düzeyinde tanımlıysa gösterilir; reklam seti düzeyinde bütçe kullanan kampanyalarda “—” görünür.' : 'CTR: click-through rate · CPC: cost per click · CPM: cost per thousand impressions. Budget is shown when it is set at campaign level; campaigns budgeted at ad set level show “—”.' }} {{ __('operator_meta.explorer.results_note') }}</div>
</section>
