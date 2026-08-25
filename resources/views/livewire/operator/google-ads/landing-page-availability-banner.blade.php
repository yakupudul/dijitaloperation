@php
    $isTr = app()->getLocale() === 'tr';
    $state = (string) ($availability['state'] ?? 'unavailable');
    $lastDate = $availability['last_date'] ?? null;
    $suggestedStart = $availability['suggested_start'] ?? null;
    $suggestedEnd = $availability['suggested_end'] ?? null;
    $suggestedUrl = $suggestedStart && $suggestedEnd
        ? request()->fullUrlWithQuery([
            'tab' => 'landing_pages',
            'period' => 'custom',
            'from' => $suggestedStart,
            'to' => $suggestedEnd,
        ])
        : null;
    $refreshClass = match ($refreshTone ?? 'info') {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200',
        default => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-200',
    };
@endphp

@if($refreshMessage)
    <div class="rounded-xl border px-4 py-3 text-xs font-medium {{ $refreshClass }}">
        {{ $refreshMessage }}
    </div>
@endif

@if($state === 'selected_period_empty')
    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 dark:border-amber-500/20 dark:bg-amber-500/10">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                        {{ $isTr ? 'Seçili dönemde trafik yok' : 'No traffic in selected period' }}
                    </span>
                    @if($lastDate)
                        <span class="text-xs font-medium text-amber-800/80 dark:text-amber-200/80">
                            {{ $isTr ? 'Son gerçek açılış sayfası verisi' : 'Latest real landing-page data' }} · {{ $lastDate }}
                        </span>
                    @endif
                </div>
                <p class="mt-2 text-sm font-semibold text-amber-950 dark:text-amber-100">
                    {{ $isTr
                        ? 'Google Ads bağlantısı çalışıyor; ancak seçili tarih aralığında landing_page_view satırı bulunmuyor.'
                        : 'The Google Ads connection is working, but there are no landing_page_view rows in the selected date range.' }}
                </p>
                <p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-200/80">
                    {{ $isTr
                        ? 'Bu durum veri kaybı anlamına gelmez. Hesapta daha eski gerçek açılış sayfası verileri bulundu; aşağıdaki aktif döneme geçerek URL, harcama, tıklama ve dönüşüm metriklerini görüntüleyebilirsiniz.'
                        : 'This does not mean data was lost. Older canonical landing-page rows exist for this account; open the active period to inspect URL, spend, clicks and conversion metrics.' }}
                </p>
            </div>

            @if($suggestedUrl)
                <a href="{{ $suggestedUrl }}"
                   class="inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-700 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-amber-800 dark:bg-amber-500 dark:text-gray-950 dark:hover:bg-amber-400">
                    {{ $isTr ? 'Son aktif dönemi göster' : 'Show latest active period' }}
                    <span class="ml-1.5">{{ $suggestedStart }} → {{ $suggestedEnd }}</span>
                </a>
            @endif
        </div>
    </div>
@elseif($state === 'no_stored_rows')
    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 dark:border-rose-500/20 dark:bg-rose-500/10">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-sm font-semibold text-rose-900 dark:text-rose-100">
                    {{ $isTr ? 'Açılış sayfası veri kümesinde henüz gerçek satır yok.' : 'The landing-page dataset has no stored provider rows yet.' }}
                </p>
                <p class="mt-1 text-xs leading-5 text-rose-700 dark:text-rose-200/80">
                    {{ $isTr
                        ? 'Google Ads hesabı bağlı olsa da google_ads_landing_page_daily tablosuna bu hesap için URL satırı yazılmamış. Yenileme işlemi landing-page ailesini de yeniden toplar; toplama tamamlandıktan sonra bu ekran otomatik olarak gerçek veriye geçer.'
                        : 'The Google Ads account is connected, but no URL rows have been written to google_ads_landing_page_daily for this account. Refresh recollects the landing-page family; the workspace switches to real rows after collection completes.' }}
                </p>
            </div>
            <button type="button"
                    wire:click="refreshLandingData"
                    wire:loading.attr="disabled"
                    wire:target="refreshLandingData"
                    class="inline-flex shrink-0 items-center justify-center rounded-lg bg-rose-700 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-rose-800 disabled:cursor-wait disabled:opacity-60 dark:bg-rose-500 dark:text-gray-950 dark:hover:bg-rose-400">
                <span wire:loading.remove wire:target="refreshLandingData">{{ $isTr ? 'Landing-page verisini yenile' : 'Refresh landing-page data' }}</span>
                <span wire:loading wire:target="refreshLandingData">{{ $isTr ? 'Toplama başlatılıyor…' : 'Starting collection…' }}</span>
            </button>
        </div>
    </div>
@elseif($state === 'dataset_unavailable')
    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200">
        {{ $isTr ? 'google_ads_landing_page_daily veri kümesi bu kurulumda mevcut değil.' : 'The google_ads_landing_page_daily dataset is not available in this installation.' }}
    </div>
@endif
