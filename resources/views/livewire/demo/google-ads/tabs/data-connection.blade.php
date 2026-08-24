@php
    $isTr = app()->getLocale() === 'tr';
    $history = $professional['history'] ?? [];
    $health = collect($professional['data_health'] ?? []);
    $currency = $professional['currency'] ?? '';
    $healthy = $health->where('partial', false)->count();
    $partial = $health->where('partial', true)->count();
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Veri & bağlantı' : 'Data & connection' }}</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google Ads hesabının tarihsel kapsamını, veri güncelliğini ve dataset sağlığını denetleyin. Teknik datasetler yalnız güven/audit amacıyla burada gösterilir.' : 'Audit Google Ads historical coverage, freshness and dataset health. Technical datasets live here for trust/audit only.' }}</p></div>
        <a href="{{ route('operator.integrations.google-ads.connector') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-800 dark:hover:bg-brand-500/10">{{ $isTr ? 'Google Ads connector’ını aç' : 'Open Google Ads connector' }}</a>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'İlk reklam aktivitesi' : 'First ad activity' }}</p><p class="mt-1 text-lg font-semibold">{{ data_get($history,'first_activity_month','—') }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Son reklam aktivitesi' : 'Last ad activity' }}</p><p class="mt-1 text-lg font-semibold">{{ data_get($history,'last_activity_month','—') }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Aktif reklam ayı' : 'Active advertising months' }}</p><p class="mt-1 text-lg font-semibold">{{ data_get($history,'active_months','—') }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Lifetime harcama' : 'Lifetime spend' }}</p><p class="mt-1 text-lg font-semibold">{{ is_numeric(data_get($history,'lifetime_spend')) ? number_format((float)data_get($history,'lifetime_spend'),2,',','.').' '.$currency : '—' }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Dataset sağlığı' : 'Dataset health' }}</p><p class="mt-1 text-lg font-semibold">{{ $health->count() ? $healthy.'/'.$health->count() : '—' }}</p><p class="mt-1 text-xs {{ $partial ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500' }}">{{ $health->count() ? $partial.' '.($isTr ? 'kısmi/eksik' : 'partial/incomplete') : ($isTr ? 'Kayıt bekleniyor' : 'Waiting for data') }}</p></div>
    </div>

    @if (! empty($history['months']))
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Hesap aktivite zaman çizelgesi' : 'Account activity timeline' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Aylık lifetime keşif verisi. İşaretli aylar Google Ads’te gerçek reklam aktivitesi bulunan dönemlerdir; uzun boşluklar detay backfill için gereksiz yere taranmaz.' : 'Monthly lifetime discovery. Marked months contain actual ad activity; long inactive gaps are not needlessly scanned by detailed backfill.' }}</p></div>
            <div class="mt-4 flex flex-wrap gap-1.5">
                @foreach ($history['months'] as $month)
                    <div title="{{ $month['month'] }} · {{ number_format((float)$month['spend'],2,',','.') }} {{ $currency }}" @class(['h-6 w-6 rounded-md ring-1 ring-inset','bg-emerald-500 ring-emerald-500' => $month['active'],'bg-gray-100 ring-gray-200 dark:bg-white/5 dark:ring-gray-800' => ! $month['active']])></div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        <strong>{{ $isTr ? 'Toplama politikası:' : 'Collection policy:' }}</strong>
        {{ $isTr ? 'İlk bağlantıda lifetime aktivite aylık seviyede keşfedilir; Google’ın granular pencere sınırı içindeki aktif dönemler detaylı backfill edilir. Sonraki normal güncellemelerde son 30 gün yeniden doğrulanır. Change History Google sınırı nedeniyle yakın dönemle sınırlıdır.' : 'At first connection, lifetime activity is discovered monthly; active periods inside Google’s granular lookback are backfilled in detail. Normal updates restate the latest 30 days. Change History remains limited by Google’s recent-history window.' }}
    </div>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Dataset sağlığı' : 'Dataset health' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Uzman navigasyonu dataset tabanlı değildir. Bu tablo yalnız veri kapsamı, güncellik ve eksikleri teşhis etmek içindir.' : 'Specialist navigation is not dataset-driven. This table exists only to diagnose coverage, freshness and gaps.' }}</p></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2 text-left">Dataset</th><th class="px-3 py-2 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th><th class="px-3 py-2 text-left">{{ $isTr ? 'Kapsam' : 'Coverage' }}</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Satır' : 'Rows' }}</th><th class="px-4 py-2 text-left">{{ $isTr ? 'Son toplama' : 'Last collection' }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($health as $row)
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['dataset'] }}</td><td class="px-3 py-2"><span @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold','bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => !($row['partial'] ?? false),'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ($row['partial'] ?? false)])>{{ $row['status'] }}</span></td><td class="px-3 py-2 text-xs text-gray-500">{{ $row['coverage_start'] ?? '—' }} → {{ $row['coverage_end'] ?? '—' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ array_key_exists('rows',$row) && is_numeric($row['rows']) ? number_format((int)$row['rows'],0,',','.') : '—' }}</td><td class="px-4 py-2 text-xs text-gray-500">{{ $row['last_collected_at'] ?? '—' }}</td></tr>
            @empty <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Bu Google Ads varlığı için Data Pool materialization kaydı henüz yok.' : 'No Data Pool materialization records are available for this Google Ads asset yet.' }}</td></tr> @endforelse
        </tbody></table></div>
    </section>
</div>
