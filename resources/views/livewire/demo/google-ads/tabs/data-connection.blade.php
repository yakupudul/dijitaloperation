@php
    $isTr = app()->getLocale() === 'tr';
    $history = $professional['history'] ?? [];
    $health = collect($professional['data_health'] ?? []);
    $currency = $professional['currency'] ?? '';
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Veri & bağlantı' : 'Data & connection' }}</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google Ads hesabının veri kapsamını, tarihsel aktivitesini ve dataset sağlığını denetleyin.' : 'Audit Google Ads history coverage, collection health and dataset availability.' }}</p></div>
        <a href="{{ route('operator.integrations.google-ads.connector') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-800 dark:hover:bg-brand-500/10">{{ $isTr ? 'Connector’ı aç' : 'Open connector' }}</a>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'İlk reklam aktivitesi' : 'First ad activity' }}</p><p class="mt-1 text-lg font-semibold">{{ data_get($history,'first_activity_month','—') }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Son reklam aktivitesi' : 'Last ad activity' }}</p><p class="mt-1 text-lg font-semibold">{{ data_get($history,'last_activity_month','—') }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Aktif reklam ayı' : 'Active advertising months' }}</p><p class="mt-1 text-lg font-semibold">{{ data_get($history,'active_months','—') }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">Lifetime spend</p><p class="mt-1 text-lg font-semibold">{{ is_numeric(data_get($history,'lifetime_spend')) ? number_format((float)data_get($history,'lifetime_spend'),2,',','.').' '.$currency : '—' }}</p></div>
    </div>

    @if (! empty($history['months']))
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Hesap aktivite zaman çizelgesi' : 'Account activity timeline' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Aylık lifetime keşif verisi. Yeşil aylar gerçek reklam aktivitesi bulunan dönemlerdir.' : 'Monthly lifetime discovery. Green months contain actual advertising activity.' }}</p></div></div>
            <div class="mt-4 flex flex-wrap gap-1.5">
                @foreach ($history['months'] as $month)
                    <div title="{{ $month['month'] }} · {{ number_format((float)$month['spend'],2,',','.') }} {{ $currency }}" @class(['h-6 w-6 rounded-md ring-1 ring-inset','bg-emerald-500 ring-emerald-500' => $month['active'],'bg-gray-100 ring-gray-200 dark:bg-white/5 dark:ring-gray-800' => ! $month['active']])></div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">Dataset health</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Teknik dataset isimleri burada yalnız veri güvenilirliğini denetlemek için gösterilir; uzman navigasyonunun kendisi dataset tabanlı değildir.' : 'Technical dataset names are shown here only for data trust/audit; specialist navigation itself is not dataset-driven.' }}</p></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2 text-left">Dataset</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Coverage</th><th class="px-3 py-2 text-right">Rows</th><th class="px-4 py-2 text-left">{{ $isTr ? 'Son toplama' : 'Last collection' }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($health as $row)
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['dataset'] }}</td><td class="px-3 py-2"><span @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold','bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => !($row['partial'] ?? false),'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ($row['partial'] ?? false)])>{{ $row['status'] }}</span></td><td class="px-3 py-2 text-xs text-gray-500">{{ $row['coverage_start'] ?? '—' }} → {{ $row['coverage_end'] ?? '—' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((int)($row['rows'] ?? 0),0,',','.') }}</td><td class="px-4 py-2 text-xs text-gray-500">{{ $row['last_collected_at'] ?? '—' }}</td></tr>
            @empty <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Bu asset için Data Pool materialization kaydı yok.' : 'No Data Pool materialization records for this asset.' }}</td></tr> @endforelse
        </tbody></table></div>
    </section>
</div>
