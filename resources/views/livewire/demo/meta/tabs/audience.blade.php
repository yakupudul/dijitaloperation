@php
    $isTr = app()->getLocale() === 'tr';
    $breakdowns = $professional['breakdowns'] ?? [];
    $targeting = $professional['targeting'] ?? [];
    $sections = [
        ['key' => 'age', 'title' => $isTr ? 'Yaş Grupları' : 'Age Groups'],
        ['key' => 'gender', 'title' => $isTr ? 'Cinsiyet' : 'Gender'],
        ['key' => 'country', 'title' => $isTr ? 'Ülkeler' : 'Countries'],
        ['key' => 'publisher_platform', 'title' => $isTr ? 'Facebook / Instagram Dağılımı' : 'Platform Distribution'],
        ['key' => 'platform_position', 'title' => $isTr ? 'Reklam Konumu' : 'Ad Placement'],
        ['key' => 'device', 'title' => $isTr ? 'Cihazlar' : 'Devices'],
    ];
    $translateTargetingChip = static function (string $chip) use ($isTr): string {
        if (!$isTr) return $chip;
        return str_replace(
            ['Age ', 'Countries: ', 'Platforms: ', ' custom audiences', ' custom audience', ' interests', ' interest'],
            ['Yaş ', 'Ülkeler: ', 'Platformlar: ', ' özel hedef kitle', ' özel hedef kitle', ' ilgi alanı', ' ilgi alanı'],
            $chip,
        );
    };
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kitle & Dağıtım' : 'Audience & Delivery' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam bütçesi gerçekte kimlere ve nerelere gitti?' : 'Who and where actually received the ad budget?' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Üst bölüm reklamların gerçekte kimlere ve hangi alanlarda gösterildiğini anlatır. Alt bölüm ise reklam setlerinde sizin tanımladığınız hedefleme ayarlarını gösterir. Böylece “hedeflediğimiz kitle” ile “reklamı gerçekten gören kitle” birbirine karışmaz.' : 'The top shows observed delivery; the lower section shows configured targeting, keeping intended audience separate from actual delivery.' }}</p>
    </div>

    <div class="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
        @foreach ($sections as $section)
            @php $rows = array_slice($breakdowns[$section['key']] ?? [], 0, 10); @endphp
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3"><h3 class="font-bold text-gray-900 dark:text-white">{{ $section['title'] }}</h3><span class="text-xs font-medium text-gray-400">{{ count($breakdowns[$section['key']] ?? []) }} {{ $isTr ? 'kırılım' : 'values' }}</span></div>
                <div class="mt-5 space-y-4">
                    @forelse ($rows as $row)
                        <div>
                            <div class="flex items-center justify-between gap-3 text-sm"><span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $row['label'] }}</span><span class="shrink-0 text-xs font-semibold tabular-nums text-gray-500">{{ number_format((float) $row['share'], 1) }}% {{ $isTr ? 'harcama payı' : 'spend share' }} · {{ $isTr ? 'Tıklama oranı' : 'Click rate' }} {{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</span></div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.05]"><div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, max(0, (float) $row['share'])) }}%"></div></div>
                            <div class="mt-1 flex justify-between text-[10px] text-gray-400"><span>{{ $professional['currency'] ?? '' }} {{ number_format((float) $row['spend'], 2) }} {{ $isTr ? 'harcama' : 'spend' }}</span><span>{{ number_format((int) $row['impressions']) }} {{ $isTr ? 'gösterim' : 'impressions' }}</span></div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Bu kırılım için yeterli veri yok.' : 'No usable data for this breakdown.' }}</div>
                    @endforelse
                </div>
            </article>
        @endforeach
    </div>

    <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Tanımlanan Hedef Kitle ve Dağıtım Ayarları' : 'Configured Targeting and Delivery Settings' }}</h3><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bunlar Meta’ya reklam seti seviyesinde verdiğiniz hedefleme ve optimizasyon talimatlarıdır; reklamın gerçekte kimlere gösterildiğini değil, nasıl çalışmasını istediğinizi anlatır.' : 'These are Ad Set targeting and optimization instructions, not observed delivery.' }}</p></div>
        <div class="overflow-x-auto"><table class="min-w-full text-left"><thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-5 py-3">{{ $isTr ? 'Reklam Seti' : 'Ad Set' }}</th><th class="px-4 py-3">{{ $isTr ? 'Neye Göre Optimize Ediliyor?' : 'Optimization' }}</th><th class="px-4 py-3">{{ $isTr ? 'Teklif Stratejisi' : 'Bidding' }}</th><th class="px-5 py-3">{{ $isTr ? 'Hedef Kitle Özeti' : 'Targeting Summary' }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse (array_slice($targeting, 0, 100) as $row)
                <tr><td class="max-w-xs px-5 py-3.5"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['adset_name'] }}</p><p class="text-[11px] text-gray-400">ID {{ $row['adset_id'] }}</p></td><td class="px-4 py-3.5 text-sm text-gray-600 dark:text-gray-300">{{ $row['optimization_goal'] ?? '—' }}@if ($row['billing_event'])<p class="mt-0.5 text-[11px] text-gray-400">{{ $isTr ? 'Faturalandırma: ' : 'Billing: ' }}{{ $row['billing_event'] }}</p>@endif</td><td class="px-4 py-3.5 text-sm text-gray-600 dark:text-gray-300">{{ $row['bid_strategy'] ?? '—' }}</td><td class="max-w-xl px-5 py-3.5"><div class="flex flex-wrap gap-1.5">@forelse ($row['summary'] as $chip)<span class="rounded-full bg-gray-100 px-2 py-1 text-[11px] text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $translateTargetingChip($chip) }}</span>@empty<span class="text-xs text-gray-400">{{ $isTr ? 'Özetlenebilir hedefleme bilgisi yok' : 'No summarized targeting fields' }}</span>@endforelse</div></td></tr>
            @empty<tr><td colspan="4" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Tanımlanan hedefleme ayarları henüz alınmamış.' : 'Configured targeting data is not ready.' }}</td></tr>@endforelse
        </tbody></table></div>
    </article>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">{{ $isTr ? 'Buradaki yüzdeler kitle büyüklüğü değildir; reklam harcamasının hangi gruba veya alana ne oranda dağıldığını gösterir. Dönüşüm verisi yaş/ülke/reklam konumu kırılımında toplanmadığı için bu ekranda dönüşüm tahmini yapılmaz.' : 'Percentages are spend share, not audience size. Conversions are not inferred because action data is not collected at these breakdown grains.' }}</div>
</section>
