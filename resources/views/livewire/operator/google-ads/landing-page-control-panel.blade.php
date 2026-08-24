@php
    $isTr = app()->getLocale() === 'tr';
    $summary = $control['summary'] ?? [];
    $rows = collect($control['rows'] ?? []);
    $decisions = collect($control['decision_inbox'] ?? []);
    $map = $control['opportunity_map'] ?? [];
    $readiness = $control['readiness'] ?? [];
    $currency = $control['currency'] ?? '';
    $number = fn ($v, $d = 0) => is_numeric($v) ? number_format((float) $v, $d, ',', '.') : '—';
    $money = fn ($v) => is_numeric($v) ? number_format((float) $v, 2, ',', '.').' '.$currency : '—';
    $pct = fn ($v, $d = 1) => is_numeric($v) ? '%'.number_format((float) $v, $d, ',', '.') : '—';
    $decisionLabel = function (string $code) use ($isTr): string {
        return match ($code) {
            'zero_conversion_high_exposure' => $isTr ? 'Yüksek harcama, dönüşüm yok' : 'High exposure with no conversions',
            'zero_conversion' => $isTr ? 'Harcama var, dönüşüm yok' : 'Spend with no conversions',
            'cpa_above_target' => $isTr ? 'CPA hedefin belirgin üzerinde' : 'CPA materially above target',
            'cpa_high_relative' => $isTr ? 'CPA hesap dağılımına göre yüksek' : 'CPA high versus account distribution',
            'low_cvr' => $isTr ? 'Dönüşüm oranı zayıf' : 'Low conversion rate',
            'slow_provider_speed' => $isTr ? 'Google hız skoru zayıf' : 'Weak Google speed score',
            'medium_provider_speed' => $isTr ? 'Google hız skoru orta' : 'Moderate Google speed score',
            'mobile_friendly_low' => $isTr ? 'Mobil uyumluluk sinyali zayıf' : 'Weak mobile-friendly signal',
            'strong_page' => $isTr ? 'Güçlü açılış sayfası' : 'Strong landing page',
            default => $isTr ? 'İzle' : 'Monitor',
        };
    };
    $decisionMessage = function (array $decision) use ($isTr, $money, $pct): string {
        $url = $decision['url'] ?? '';
        return match ($decision['code'] ?? '') {
            'zero_conversion_high_exposure' => $isTr
                ? $url.' seçili dönemde '.$money($decision['spend'] ?? null).' harcama aldı ancak Google Ads dönüşümü üretmedi. Yüksek harcama maruziyeti nedeniyle öncelikli inceleme adayıdır.'
                : $url.' spent '.$money($decision['spend'] ?? null).' with no Google Ads conversions and deserves priority review.',
            'zero_conversion' => $isTr
                ? $url.' harcama aldı ancak seçili dönemde Google Ads dönüşümü üretmedi. Trafik niyeti ve sayfa deneyimi birlikte incelenmeli.'
                : $url.' has spend but no provider conversion in the selected period.',
            'cpa_above_target' => $isTr
                ? 'Sayfa CPA’sı '.$money($decision['cpa'] ?? null).'; tanımlı hedef CPA '.$money($decision['target_cpa'] ?? null).'. Trafik kalitesi ve CRO birlikte incelenmeli.'
                : 'Page CPA is materially above the configured target CPA.',
            'cpa_high_relative' => $isTr
                ? 'Sayfa CPA’sı '.$money($decision['cpa'] ?? null).' ve hesap içindeki açılış sayfası dağılımının yüksek tarafında.'
                : 'Page CPA is high relative to the landing-page distribution.',
            'low_cvr' => $isTr
                ? 'Dönüşüm oranı '.$pct($decision['cvr'] ?? null).' ve benzer harcama alan sayfalara göre zayıf. CRO incelemesine aday.'
                : 'Conversion rate is weak relative to comparable landing pages.',
            'slow_provider_speed' => $isTr ? 'Google Ads’in kendi hız sinyali 1–10 ölçeğinde zayıf.' : 'Google Ads provider speed signal is weak.',
            'medium_provider_speed' => $isTr ? 'Google Ads’in kendi hız sinyali orta seviyede; mobil deneyim kontrol edilmeli.' : 'Google Ads provider speed signal is moderate.',
            'mobile_friendly_low' => $isTr ? 'Mobil uyumlu tıklama yüzdesi düşük görünüyor; mobil deneyim kontrol edilmeli.' : 'Mobile-friendly click percentage is low.',
            'strong_page' => $isTr
                ? 'Sayfa anlamlı harcama hacminde güçlü CPA ve dönüşüm oranı sinyali üretiyor. Bütçe artışı kararı yine kampanya ve arama niyetiyle birlikte verilmelidir.'
                : 'The page combines meaningful spend with strong CPA and conversion-rate signals.',
            default => $isTr ? 'Belirgin bir risk veya güçlü fırsat sinyali yok; izlemeye devam edin.' : 'No material risk or strong opportunity signal detected.',
        };
    };
    $groupLabel = fn (string $group) => match ($group) {
        'strong' => $isTr ? 'Güçlü' : 'Strong',
        'cro' => $isTr ? 'CRO fırsatı' : 'CRO opportunity',
        'risk' => $isTr ? 'Riskli harcama' : 'Spend at risk',
        default => $isTr ? 'İzle' : 'Monitor',
    };
    $groupClasses = fn (string $group) => match ($group) {
        'strong' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'cro' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'risk' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
    };
    $readinessNote = function (string $key, array $item) use ($isTr): string {
        if (! $isTr) {
            return (string) ($item['note'] ?? '');
        }
        $state = $item['state'] ?? 'unavailable';
        return match ($key) {
            'google_landing_health' => $state === 'partial'
                ? 'Google Ads’in mobil uyumluluk ve hız sinyalleri gözlenen URL’lerin bir bölümünde mevcut.'
                : 'Google Ads mobil uyumluluk ve hız alanları mevcut kanonik açılış sayfası satırlarında henüz bulunmuyor.',
            'website' => $state === 'asset_available'
                ? 'Bu markaya ait Web Sitesi varlığı mevcut; ancak teknik performans ve CRO verisi henüz URL bazında Google Ads ile kanonik olarak birleştirilmedi.'
                : 'Teknik performans ve CRO çapraz analizi için kullanılabilir Web Sitesi varlığı bulunmuyor.',
            'ga4' => $state === 'asset_available'
                ? 'Bu markaya ait GA4 varlığı mevcut; ancak oturum, etkileşim ve CTA olayları henüz açılış sayfası URL’si bazında bu ekrana bağlanmadı.'
                : 'Davranış analizi için kullanılabilir GA4 varlığı bulunmuyor.',
            'search_console' => $state === 'asset_available'
                ? 'Bu markaya ait Search Console varlığı mevcut; ancak organik sayfa kanıtları henüz bu ücretli trafik görünümüne URL bazında bağlanmadı.'
                : 'Sayfa düzeyinde çapraz analiz için kullanılabilir Search Console varlığı bulunmuyor.',
            'intent_page_match' => 'Arama terimi veya anahtar kelime ile açılış sayfası arasındaki bağ mevcut kanonik veri havuzunda henüz kurulmadığı için MOXDOP niyet ya da mesaj uyumu tahmin etmiyor.',
            'expanded_url' => 'Genişletilmiş final URL ve sayfanın reklamveren mi Google tarafından otomatik mi seçildiği bilgisi mevcut açılış sayfası veri kümesinde henüz kanonik değil.',
            'business_outcomes' => 'Nitelikli potansiyel müşteri, satış ve doğrulanmış gelir sonuçları henüz açılış sayfası URL’lerine kanonik olarak bağlanmadı.',
            default => '',
        };
    };
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600 dark:text-violet-300">{{ $isTr ? 'Açılış Sayfası & CRO Kontrol Merkezi' : 'Landing Page & CRO Control Center' }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam sonrası dönüşüm performansı' : 'Post-click conversion performance' }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bütçenin hangi URL’lere gittiğini, hangi sayfaların dönüşüm ürettiğini, hangi harcamanın inceleme gerektirdiğini ve CRO fırsatlarını tek yerde görün.' : 'See where paid budget lands, which URLs convert, where spend deserves review and where CRO opportunities exist.' }}</p>
        </div>
        @if(is_numeric($summary['target_cpa'] ?? null))
            <span class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">{{ $isTr ? 'Hedef CPA' : 'Target CPA' }} · {{ $money($summary['target_cpa']) }}</span>
        @endif
    </div>

    @if(!($control['connected'] ?? false))
        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-10 text-center dark:border-gray-700 dark:bg-white/[0.02]">
            <p class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Açılış sayfası verisi henüz kullanılabilir değil.' : 'Landing-page data is not available yet.' }}</p>
            <p class="mt-2 text-sm text-gray-500">{{ $isTr ? 'Google Ads açılış sayfası veri kümesi toplandığında bu kontrol merkezi otomatik olarak gerçek veriye geçer.' : 'This control center activates automatically when the Google Ads landing-page dataset becomes available.' }}</p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 2xl:grid-cols-8">
            <x-ta.metric-card :label="$isTr ? 'Açılış sayfası' : 'Landing pages'" :value="(string)($summary['urls'] ?? 0)" />
            <x-ta.metric-card :label="$isTr ? 'Gözlenen harcama' : 'Observed spend'" :value="$money($summary['spend'] ?? null)" />
            <x-ta.metric-card :label="$isTr ? 'Tıklama' : 'Clicks'" :value="$number($summary['clicks'] ?? null)" />
            <x-ta.metric-card :label="$isTr ? 'Google dönüşümü' : 'Google conversions'" :value="$number($summary['conversions'] ?? null, 2)" />
            <x-ta.metric-card :label="$isTr ? 'Dönüşüm oranı' : 'Conversion rate'" :value="$pct($summary['cvr'] ?? null)" />
            <x-ta.metric-card label="CPA" :value="$money($summary['cpa'] ?? null)" />
            <x-ta.metric-card :label="$isTr ? 'Riskli harcama' : 'Spend at risk'" :value="$money($summary['risk_spend'] ?? null)" :tone="($summary['risk_pages'] ?? 0) > 0 ? 'negative' : 'neutral'" />
            <x-ta.metric-card :label="$isTr ? 'Güçlü sayfa' : 'Strong pages'" :value="(string)($summary['strong_pages'] ?? 0)" :tone="($summary['strong_pages'] ?? 0) > 0 ? 'positive' : 'neutral'" />
        </div>

        <div class="grid gap-4 xl:grid-cols-[1.45fr_1fr]">
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Açılış Sayfası Karar Kutusu' : 'Landing Page Decision Inbox' }}</h3>
                            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Önce incelenmesi gereken sayfalar ve nedenleri.' : 'Pages that deserve operator attention first and why.' }}</p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $decisions->count() }}</span>
                    </div>
                </div>
                <div class="max-h-[560px] divide-y divide-gray-100 overflow-auto dark:divide-gray-800">
                    @forelse($decisions as $decision)
                        @php
                            $severity = $decision['severity'] ?? 'info';
                            $severityLabel = match($severity) { 'critical' => $isTr ? 'Kritik' : 'Critical', 'review' => $isTr ? 'İncele' : 'Review', 'positive' => $isTr ? 'Güçlü' : 'Strong', default => $isTr ? 'Bilgi' : 'Info' };
                            $sevClass = match($severity) { 'critical' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300', 'review' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300', 'positive' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' };
                        @endphp
                        <div class="flex gap-3 px-4 py-3">
                            <span class="mt-0.5 h-fit rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $sevClass }}">{{ $severityLabel }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $decisionLabel($decision['code'] ?? '') }}</p>
                                <p class="mt-1 break-words text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $decisionMessage($decision) }}</p>
                                <button type="button" wire:click="openLanding('{{ $decision['row_id'] }}')" class="mt-2 text-xs font-semibold text-violet-600 hover:text-violet-700 dark:text-violet-300">{{ $isTr ? 'Sayfayı incele →' : 'Inspect page →' }}</button>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönemde belirgin açılış sayfası kararı oluşmadı.' : 'No material landing-page decision in the selected period.' }}</div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Fırsat Haritası' : 'Opportunity Map' }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Sayfalar göreli performans ve harcama dağılımına göre dört operasyon bölgesine ayrılır.' : 'Pages are grouped into four operating zones using relative performance and spend distribution.' }}</p>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    @foreach(['strong','cro','risk','monitor'] as $group)
                        @php $groupRows = collect($map[$group] ?? []); @endphp
                        <div class="rounded-xl p-3 {{ $groupClasses($group) }}">
                            <div class="flex items-center justify-between gap-2"><span class="text-sm font-semibold">{{ $groupLabel($group) }}</span><strong>{{ $groupRows->count() }}</strong></div>
                            <p class="mt-2 text-[11px] opacity-80">{{ $isTr ? 'Harcama' : 'Spend' }}: {{ $money($groupRows->sum('spend')) }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 rounded-xl bg-gray-50 p-3 text-xs text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                    <p><strong class="text-gray-700 dark:text-gray-200">{{ $isTr ? 'İlk 3 sayfanın harcama payı' : 'Top-3 spend share' }}:</strong> {{ $pct($summary['top3_spend_share'] ?? null) }}</p>
                    <p class="mt-1">{{ $isTr ? 'Kararlar mutlak “iyi/kötü” puanı değildir; hesabın kendi açılış sayfası dağılımı ve varsa hedef CPA ile karşılaştırılır.' : 'Decisions are relative to this account’s landing-page distribution and configured target CPA when available.' }}</p>
                </div>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Açılış sayfası performansı' : 'Landing-page performance' }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google Ads gerçek performansı + MOXDOP karar sınıflaması. Riskli harcama, kanıtlanmış israf anlamına gelmez.' : 'Google Ads provider performance plus MOXDOP decision classification.' }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-[1350px] w-full text-sm">
                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr>
                        <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Açılış sayfası' : 'Landing page' }}</th>
                        <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
                        <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th>
                        <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Gösterim' : 'Impressions' }}</th>
                        <th class="px-3 py-2.5 text-right">CTR</th>
                        <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th>
                        <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm oranı' : 'CVR' }}</th>
                        <th class="px-3 py-2.5 text-right">CPA</th>
                        <th class="px-3 py-2.5 text-center">{{ $isTr ? 'Google hız' : 'Google speed' }}</th>
                        <th class="px-3 py-2.5 text-center">{{ $isTr ? 'Mobil uyum' : 'Mobile friendly' }}</th>
                        <th class="px-4 py-2.5 text-left">{{ $isTr ? 'MOXDOP kararı' : 'MOXDOP decision' }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($rows as $row)
                            <tr class="align-top">
                                <td class="max-w-[420px] px-4 py-3"><button type="button" wire:click="openLanding('{{ $row['id'] }}')" class="max-w-full text-left"><p class="truncate font-semibold text-gray-900 hover:text-violet-600 dark:text-white dark:hover:text-violet-300" title="{{ $row['url'] }}">{{ $row['path'] ?: $row['url'] }}</p><p class="mt-1 truncate text-[11px] text-gray-400">{{ $row['host'] }}</p></button></td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $money($row['spend']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $number($row['clicks']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $number($row['impressions']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $pct($row['ctr']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $number($row['conversions'], 2) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $pct($row['cvr']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $money($row['cpa']) }}</td>
                                <td class="px-3 py-3 text-center tabular-nums">{{ is_numeric($row['speed_score']) ? $number($row['speed_score'], 0).'/10' : '—' }}</td>
                                <td class="px-3 py-3 text-center tabular-nums">{{ $pct($row['mobile_friendly_clicks_pct']) }}</td>
                                <td class="px-4 py-3"><span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $groupClasses($row['decision_group']) }}">{{ $groupLabel($row['decision_group']) }}</span><p class="mt-1 text-[11px] text-gray-500">{{ $decisionLabel($row['decision']) }}</p></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Çapraz Varlık Hazırlığı' : 'Cross-Asset Readiness' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google Ads dışındaki veriler kanonik olarak URL’ye bağlanmadıkça MOXDOP hız, davranış, SEO veya satış sonucu uydurmaz.' : 'MOXDOP does not infer technical, behavioral, SEO or business-outcome facts until they are canonically joined by URL.' }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach([
                    'google_landing_health' => $isTr ? 'Google mobil & hız sinyali' : 'Google mobile & speed',
                    'website' => $isTr ? 'Web Sitesi teknik/CRO' : 'Website technical/CRO',
                    'ga4' => $isTr ? 'GA4 davranış & CTA' : 'GA4 behavior & CTA',
                    'search_console' => $isTr ? 'Search Console sayfa verisi' : 'Search Console page data',
                    'intent_page_match' => $isTr ? 'Arama niyeti → sayfa' : 'Search intent → page',
                    'expanded_url' => $isTr ? 'Final URL / otomatik URL' : 'Final / automatic URL',
                    'business_outcomes' => $isTr ? 'Potansiyel müşteri → satış → gelir' : 'Lead → sale → revenue',
                ] as $key => $title)
                    @php
                        $item = $readiness[$key] ?? ['state' => 'unavailable', 'note' => ''];
                        $state = $item['state'] ?? 'unavailable';
                        $stateLabel = match($state) { 'partial' => $isTr ? 'Kısmi' : 'Partial', 'asset_available' => $isTr ? 'Varlık mevcut' : 'Asset exists', default => $isTr ? 'Henüz bağlı değil' : 'Not joined yet' };
                        $stateClass = $state === 'partial' ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : ($state === 'asset_available' ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400');
                    @endphp
                    <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800"><div class="flex items-start justify-between gap-2"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</p><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $stateClass }}">{{ $stateLabel }}</span></div><p class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $readinessNote($key, $item) }}</p></div>
                @endforeach
            </div>
        </section>

        <div class="rounded-xl bg-violet-50 px-4 py-3 text-sm text-violet-800 ring-1 ring-inset ring-violet-100 dark:bg-violet-500/10 dark:text-violet-200 dark:ring-violet-500/20">
            <strong>{{ $isTr ? 'Karar sınırı:' : 'Decision boundary:' }}</strong>
            {{ $isTr ? 'Harcama, tıklama, gösterim ve Google dönüşümü Google Ads gerçeğidir. “Riskli”, “CRO fırsatı” ve “Güçlü” sınıfları MOXDOP’un göreli karar katmanıdır; otomatik URL, teklif veya kampanya değişikliği yapılmaz.' : 'Spend, clicks, impressions and Google conversions are provider facts. Risk/CRO/Strong are MOXDOP relative decision classifications; no URL, bid or campaign change is made automatically.' }}
        </div>
    @endif

    @if($selectedLanding)
        <x-demo.gads-drawer :title="$selectedLanding['path'] ?: $selectedLanding['url']" :subtitle="$selectedLanding['host']" :severity="$selectedLanding['decision_group'] === 'risk' ? 'High' : null">
            <div class="rounded-xl p-3 {{ $groupClasses($selectedLanding['decision_group']) }}">
                <p class="text-xs font-semibold uppercase tracking-wide">{{ $groupLabel($selectedLanding['decision_group']) }}</p>
                <p class="mt-1 text-sm font-semibold">{{ $decisionLabel($selectedLanding['decision']) }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><p class="text-xs text-gray-400">{{ $isTr ? 'Harcama' : 'Spend' }}</p><p class="font-semibold">{{ $money($selectedLanding['spend']) }}</p></div>
                <div><p class="text-xs text-gray-400">{{ $isTr ? 'Tıklama' : 'Clicks' }}</p><p class="font-semibold">{{ $number($selectedLanding['clicks']) }}</p></div>
                <div><p class="text-xs text-gray-400">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</p><p class="font-semibold">{{ $number($selectedLanding['conversions'], 2) }}</p></div>
                <div><p class="text-xs text-gray-400">CPA</p><p class="font-semibold">{{ $money($selectedLanding['cpa']) }}</p></div>
                <div><p class="text-xs text-gray-400">{{ $isTr ? 'Dönüşüm oranı' : 'CVR' }}</p><p class="font-semibold">{{ $pct($selectedLanding['cvr']) }}</p></div>
                <div><p class="text-xs text-gray-400">CTR</p><p class="font-semibold">{{ $pct($selectedLanding['ctr']) }}</p></div>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 text-xs text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $isTr ? 'Google açılış sayfası sinyalleri' : 'Google landing-page signals' }}</p>
                <p class="mt-2">{{ $isTr ? 'Hız skoru' : 'Speed score' }}: <strong>{{ is_numeric($selectedLanding['speed_score']) ? $number($selectedLanding['speed_score']).'/10' : '—' }}</strong></p>
                <p class="mt-1">{{ $isTr ? 'Mobil uyumlu tıklama' : 'Mobile-friendly clicks' }}: <strong>{{ $pct($selectedLanding['mobile_friendly_clicks_pct']) }}</strong></p>
            </div>
            <div class="rounded-xl bg-blue-50 p-3 text-xs text-blue-800 dark:bg-blue-500/10 dark:text-blue-200">
                <strong>{{ $isTr ? 'Tam URL:' : 'Full URL:' }}</strong>
                <p class="mt-1 break-all">{{ $selectedLanding['url'] }}</p>
            </div>
            <p class="text-xs leading-5 text-gray-500">{{ $isTr ? 'Arama terimi eşleşmesi, gerçek sayfa hızı/Core Web Vitals, GA4 davranışı ve satış sonucu bu URL’ye kanonik olarak bağlanmadıkça bu detay paneli bunlar hakkında iddia üretmez.' : 'Search-term fit, real web vitals, GA4 behavior and sales outcomes are not claimed until canonically joined to this URL.' }}</p>
        </x-demo.gads-drawer>
    @endif
</div>
