@php
    use Illuminate\Support\Str;

    $groups = [
        'website' => 'Web sitesi',
        'search_console' => 'Search Console',
        'ga4' => 'Google Analytics 4',
        'google_business_profile' => 'Google İşletme Profili',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
    ];
    $items = collect($proposal?->itemRows() ?? []);
    $services = $proposal?->serviceRows() ?? [];
    $statusBadge = fn (string $status): array => match ($status) {
        'already' => ['Zaten bağlı', 'ok'],
        'bound_elsewhere' => ['Başka varlığa bağlı', 'look'],
        default => ['Öneri', 'info'],
    };
@endphp
<div class="space-y-10" @if($proposal?->isPending() && ! $proposal->isStuck()) wire:poll.3s @endif>
    <div>
        <a wire:navigate href="{{ route('operator.brand', ['brand' => $brand->id]) }}" class="text-sm text-gray-500 hover:text-gray-900 dark:hover:text-white">{{ $brand->name }}</a>
        <h1 class="mt-2 text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Otomatik kur</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500" title="Web sitesi adresinden yola çıkarak entegrasyonlardaki Search Console, GA4, İşletme Profili, Google Ads ve Meta hesaplarını bulur, sitedeki hizmetleri çıkarır.">Hiçbir şey sen onaylamadan kaydedilmez. Sosyal hesaplar ve rehber kayıtları için <a href="{{ route('operator.public-discovery', ['q' => $brand->name]) }}" wire:navigate class="link-neutral">Açık Web Keşfi</a>.</p>
    </div>

    @if ($message !== '')
        <p role="status" @class(['rounded-lg p-3 text-sm', 'tone-ok' => $messageTone === 'success', 'tone-critical' => $messageTone === 'error'])>{{ $message }}</p>
    @endif

    <form wire:submit="start" class="flex flex-wrap items-end gap-3">
        <label class="min-w-0 flex-1 text-sm">
            <span class="block text-xs text-gray-500">Markanın web sitesi</span>
            <input wire:model="websiteUrl" type="text" placeholder="ornek.com.tr" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
            @error('websiteUrl')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
        </label>
        <button type="submit" wire:loading.attr="disabled" @disabled($proposal?->isPending() && ! $proposal->isStuck())
            @if ($proposal && in_array($proposal->status, ['ready', 'applied'], true)) wire:confirm="Hazır öneriler silinmez ama yeni bir tarama başlar (hesaplar, sayfalar ve AI yeniden çalışır). Devam edilsin mi?" @endif
            @class(['inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50', 'bg-brand-500 text-white hover:bg-brand-600' => $proposal === null, 'text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-600 dark:hover:bg-white/5' => $proposal !== null])>
            <svg wire:loading wire:target="start" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            {{ $proposal?->isPending() && ! $proposal->isStuck() ? 'Hazırlanıyor…' : ($proposal ? 'Yeniden tara' : 'Önerileri hazırla') }}
        </button>
    </form>

    @if ($warnings !== [])
        <div class="tone-look rounded-lg p-4" data-setup-warnings>
            <h2 class="font-semibold">Otomatik kur şu an doğru çalışamaz</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="start(true)" wire:loading.attr="disabled" class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-white dark:ring-gray-600">Yine de getir</button>
                <button type="button" wire:click="$set('warnings', [])" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 hover:underline dark:text-gray-300">Vazgeç</button>
            </div>
        </div>
    @endif

    @if ($proposal?->isStuck())
        <p class="tone-look rounded-lg p-4 text-sm">Hazırlık 15 dakikadır ilerlemiyor (arka plan işi durmuş olabilir). "Yeniden tara" ile tekrar başlatın.</p>
    @elseif ($proposal?->isPending())
        @php
            $steps = \App\Services\BrandSetup\BrandSetupAssistant::STEPS;
            $current = $progress['step'] ?? 'queued';
            $order = array_keys($steps);
            $currentIndex = array_search($current, $order, true);
        @endphp
        <section class="text-sm text-gray-900 dark:text-gray-200" data-setup-progress="{{ $current }}"
            x-data="{ started: {{ $proposal->created_at->getTimestamp() }}, now: Math.floor(Date.now() / 1000) }" x-init="setInterval(() => now = Math.floor(Date.now() / 1000), 1000)">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 font-medium">
                    <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-gray-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-gray-500"></span></span>
                    @if ($proposal->waitsForClaude())
                        Hizmet önerisi Claude'da bekliyor (kuyruk iş saatlerinde 2 saatte bir çalışır) — bu sayfadan çıkabilirsin; yanıt gelince öneriler hazırlanır.
                    @else
                        Öneriler hazırlanıyor — bu sayfadan çıkabilirsin, iş arka planda sürer; geri döndüğünde aynı yerden takip edilir.
                    @endif
                </p>
                <span class="tabular-nums text-xs" x-text="(() => { const s = Math.max(0, now - started); return Math.floor(s / 60) + ' dk ' + (s % 60) + ' sn'; })()"></span>
            </div>
            <ol class="mt-3 space-y-1.5">
                @foreach ($steps as $key => $label)
                    @php $index = array_search($key, $order, true); @endphp
                    <li class="flex items-center gap-2 text-xs">
                        @if ($index < $currentIndex)
                            <span class="flex h-4 w-4 items-center justify-center text-success-600" aria-hidden="true">✓</span><span class="text-gray-500">{{ $label }}</span>
                        @elseif ($index === $currentIndex)
                            <svg class="h-4 w-4 animate-spin text-gray-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg><span class="font-medium">{{ $label }}</span>
                        @else
                            <span class="flex h-4 w-4 items-center justify-center" aria-hidden="true"><span class="h-1.5 w-1.5 rounded-full bg-gray-300"></span></span><span class="text-gray-500">{{ $label }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
            <p class="mt-3 text-xs text-gray-500">En uzun adım AI'ın sayfaları ve sorguları okumasıdır (genelde 1–3 dakika).</p>
        </section>
    @elseif ($proposal?->status === 'failed')
        <p class="tone-critical rounded-lg p-4 text-sm">Öneriler hazırlanamadı: {{ $proposal->error_summary }}</p>
    @endif

    @if ($proposal && in_array($proposal->status, ['ready', 'applied'], true))
        @if (! empty(data_get($proposal->summary, 'brand_summary')))
            <p class="text-sm text-gray-700 dark:text-gray-300"><strong>AI özeti:</strong> {{ data_get($proposal->summary, 'brand_summary') }}@if (data_get($proposal->summary, 'sector_label')). Sektör önerisi: <strong>{{ data_get($proposal->summary, 'sector_label') }}</strong>@endif</p>
        @endif
        @if ($sectorName !== null && data_get($proposal->summary, 'sector_label') !== null && data_get($proposal->summary, 'sector_label') !== $sectorName)
            <p class="tone-look rounded-lg p-3 text-xs" data-sector-mismatch>Markada «{{ $sectorName }}» sektörü seçili, AI siteden «{{ data_get($proposal->summary, 'sector_label') }}» öneriyor. Sektör kendiliğinden değiştirilmez; doğruysa <a wire:navigate href="{{ route('operator.brand.edit', ['brandId' => $brand->id]) }}" class="font-semibold underline">markayı düzenle</a>.</p>
        @endif
        @php $businessContext = data_get($proposal->summary, 'business_context'); @endphp
        @if (is_array($businessContext))
            <section>
                <label class="section-title flex items-center gap-2">
                    <input type="checkbox" wire:model="applyContext" @disabled($proposal->status !== 'ready') class="rounded border-gray-300"> İş bağlamı (siteden)
                </label>
                <p class="mt-1 text-xs text-gray-500">Onaylarsanız markanın İş bağlamı sekmesindeki boş alanlar bununla doldurulur; sizin yazdığınız alanlara dokunulmaz.</p>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    @foreach (['business_summary' => 'Özet', 'business_model' => 'İş modeli', 'positioning' => 'Konumlanma', 'target_audiences' => 'Hedef kitle', 'differentiators' => 'Farklılıklar'] as $field => $fieldLabel)
                        @if (! empty($businessContext[$field]))
                            <div><dt class="text-xs text-gray-500">{{ $fieldLabel }}</dt><dd class="text-gray-800 dark:text-gray-200">{{ is_array($businessContext[$field]) ? implode(', ', array_filter(\Illuminate\Support\Arr::flatten($businessContext[$field]), 'is_scalar')) : (is_scalar($businessContext[$field]) ? $businessContext[$field] : '') }}</dd></div>
                        @endif
                    @endforeach
                </dl>
            </section>
        @endif

        <section>
            <div class="flex items-center justify-between">
                <h2 class="section-title">Varlıklar ve hesap bağlantıları</h2>
                @if ($proposal->status === 'ready')
                    <div class="flex gap-2 text-xs"><button type="button" wire:click="selectAll(true)" class="link-neutral">Hepsini seç</button><button type="button" wire:click="selectAll(false)" class="text-gray-500">Temizle</button></div>
                @endif
            </div>
            <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @forelse ($items as $item)
                    @php [$badge, $color] = $statusBadge($item['status']); @endphp
                    <li wire:key="item-{{ $item['key'] }}" class="flex items-start gap-3 py-2.5">
                        <input type="checkbox" wire:model="selectedItems.{{ $item['key'] }}" @disabled($item['status'] !== 'proposed' || $proposal->status !== 'ready') class="mt-1 rounded border-gray-300" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $groups[$item['group']] ?? $item['group'] }}: {{ $item['label'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $item['reason'] }}@if (($item['target'] ?? '') !== '' && str_starts_with($item['target'] ?? '', 'new:')). Yeni {{ $groups[substr($item['target'], 4)] ?? '' }} varlığı açılır @endif</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-xs text-gray-500">{{ (int) round(($item['confidence'] ?? 0) * 100) }}%</span>
                            <x-ta.pill :tone="$color">{{ $badge }}</x-ta.pill>
                        </div>
                    </li>
                @empty
                    <li class="py-2.5 text-sm text-gray-500">Eşleşen hesap bulunamadı. Entegrasyonlarda hesapların keşfedildiğinden emin ol.</li>
                @endforelse
            </ul>
        </section>

        @php $locations = data_get($proposal->summary, 'locations'); @endphp
        @if (is_array($locations) && (! empty($locations['out_of_area']) || ! empty($locations['mentioned'])))
            <section class="tone-look rounded-lg p-4">
                @if (! empty($locations['has_areas']) && is_array($locations['areas'] ?? null) && is_array($locations['out_of_area'] ?? null))
                    <h2 class="text-sm font-semibold">Hizmet bölgesi dışındaki aramalar</h2>
                    <p class="mt-1 text-xs">Markanın hizmet verdiği yerler: <strong>{{ implode(', ', $locations['areas']) }}</strong>. Site aşağıdaki konumlarla yapılan aramalarda da görünüyor. Hizmet ve anahtar kelimelere konum yazılmaz; SEO planı bu konumlar için içerik önermez.</p>
                    <ul class="mt-2 space-y-1 text-xs text-warning-900 dark:text-warning-200">
                        @foreach ($locations['out_of_area'] as $row)
                            <li><strong>{{ $row['name'] }}</strong>: {{ number_format($row['impressions']) }} gösterim, ör. {{ implode(', ', $row['queries']) }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs">Bu bölgelere de hizmet veriyorsan <a wire:navigate href="{{ route('operator.brand.edit', ['brandId' => $brandId]) }}" class="font-semibold underline">markanın hizmet verdiği yerlere</a> ekle; bölge sayfası önerileri ona göre üretilir.</p>
                @else
                    <h2 class="text-sm font-semibold">Markanın hizmet verdiği yerler tanımlı değil</h2>
                    <p class="mt-1 text-xs">Aramalarda en çok geçen konumlar aşağıda. Doğru olanları <a wire:navigate href="{{ route('operator.brand.edit', ['brandId' => $brandId]) }}" class="font-semibold underline">markanın hizmet verdiği yerlere</a> ekle; bölge dışı aramalar ancak o zaman ayrılabilir.</p>
                    <ul class="mt-2 space-y-1 text-xs text-warning-900 dark:text-warning-200">
                        @foreach ((array) ($locations['mentioned'] ?? []) as $row)
                            <li><strong>{{ $row['name'] }}</strong>: {{ number_format($row['impressions']) }} gösterim</li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @php $areaRows = array_values((array) data_get($proposal->summary, 'areas', [])); @endphp
        @if ($areaRows !== [])
            <section data-setup-areas>
                <div>
                    <h2 class="section-title">Hizmet bölgeleri</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Hedef sorgular, yerel raporlar ve rakip aramaları bu bölgelere göre yapılır. İşletme Profili adresi şube olarak eklenir.</p>
                </div>
                <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($areaRows as $index => $area)
                        <li wire:key="area-{{ $index }}" class="flex items-start gap-3 py-2.5">
                            <input type="checkbox" wire:model="selectedAreas.{{ $index }}" @disabled($proposal->status !== 'ready') class="mt-1 rounded border-gray-300" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $area['label'] }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $area['evidence'] }}</p>
                            </div>
                            <x-ta.pill :tone="$area['physical'] ? 'ok' : 'info'">{{ $area['physical'] ? 'Şube' : 'Hizmet bölgesi' }}</x-ta.pill>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section>
            <div>
                <h2 class="section-title">Hizmetler</h2>
                <p class="mt-0.5 text-xs text-gray-500">Katalogda olan hizmetler yeniden açılmaz; yeni olanlar önerilen sektörle kataloğa eklenir. ★ = SEO planında öncelikli.</p>
            </div>
            @if ($proposal->services_status === 'waiting_for_site')
                <p class="py-2.5 text-sm text-gray-600 dark:text-gray-300">Site henüz taranmadı ve Search Console verisi yok. Onaylayınca site taraması başlar; tarama bitince "Yeniden tara" ile hizmetler önerilir.</p>
            @elseif ($services === [])
                @php $aiSummary = $proposal->summary ?? []; @endphp
                <div class="py-2.5 text-sm text-gray-600 dark:text-gray-300">
                    @if (($aiSummary['ai_skipped_reason'] ?? null) === 'llm_error')
                        <p>Hizmet önerilemedi: AI çağrısı hata verdi. "Yeniden tara" ile tekrar dene; sürerse aşağıdaki hatayı ilet.</p>
                        @if (! empty($aiSummary['ai_error']))
                            <p class="mt-2 break-words rounded-lg bg-gray-50 p-2 font-mono text-xs text-error-700 dark:bg-gray-800">{{ $aiSummary['ai_error'] }}</p>
                        @endif
                    @elseif (($aiSummary['ai_skipped_reason'] ?? null) === 'no_eligible_provider')
                        <p>Hizmet önerilemedi: "Brand Setup Assistant" işi için çalışabilir sağlayıcı yok (AI Kontrol Paneli).</p>
                    @else
                        <p>Hizmet önerilemedi.</p>
                    @endif
                </div>
            @else
                <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($services as $index => $service)
                        <li wire:key="service-{{ $index }}" class="flex items-start gap-3 py-2.5">
                            <input type="checkbox" wire:model="selectedServices.{{ $index }}" @disabled($proposal->status !== 'ready') class="mt-1 rounded border-gray-300" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">@if ($service['is_core'])★ @endif{{ $service['name'] }}@if (! empty($service['aliases']))<span class="font-normal text-gray-500">, {{ implode(', ', $service['aliases']) }}</span>@endif</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $service['evidence'] }}</p>
                                @if (! empty($service['matching_phrases']))
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><span class="font-medium">Eşleştirme ifadeleri:</span> {{ implode(', ', $service['matching_phrases']) }}</p>
                                @endif
                                @if (! empty($service['keywords']))
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><span class="font-medium">Search Console'da {{ count($service['keywords']) }} sorgu</span> (hesap bağlanınca Bekleyenler'e gelir, otomatik pilot hizmete atar): {{ implode(', ', array_slice(array_column($service['keywords'], 'query'), 0, 6)) }}@if (count($service['keywords']) > 6)…@endif</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1 text-xs">
                                @if ($service['status'] === 'already')
                                    <x-ta.pill tone="ok">Markada var</x-ta.pill>
                                    <span class="text-gray-500">seçilirse yalnızca eksik ifadeler eklenir</span>
                                @elseif ($service['is_new'])
                                    <x-ta.pill tone="look">Yeni, {{ $service['sector_label'] ?? 'sektör yok' }}</x-ta.pill>
                                @else
                                    <x-ta.pill tone="info">Katalogda var</x-ta.pill>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($proposal->status === 'ready')
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="approve" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                    <svg wire:loading wire:target="approve" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <span wire:loading.remove wire:target="approve">Seçilenleri onayla</span><span wire:loading wire:target="approve">Uygulanıyor…</span>
                </button>
                <p class="text-xs text-gray-500">Onaydan sonra hesaplar bağlanır, hizmetler markaya eklenir; Search Console bağlandıysa ilk SEO planı kuyruğa alınır. Dış platformlarda hiçbir değişiklik yapılmaz.</p>
            </div>
        @endif

        @if ($proposal->status === 'applied')
            <section>
                <h2 class="section-title">Uygulandı <span class="text-sm font-normal text-gray-500">{{ $proposal->applied_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span></h2>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($proposal->apply_result ?? [] as $row)
                        <li @class(['text-success-700 dark:text-success-400' => $row['ok'], 'text-error-600' => ! $row['ok']])>{{ $row['ok'] ? '✓' : '✗' }} {{ $row['label'] }} — {{ $row['message'] }}</li>
                    @endforeach
                </ul>
                @if ($websiteId !== null)
                    <x-operator.cluster-readiness :asset-id="$websiteId" what="Web sitesi küme görünümleri" class="mt-3" />
                    <p class="mt-2 text-xs text-gray-500">Web sitesi ekranı hazırlanıyor: sayfa kategorileri, hizmet ↔ sayfa eşleşmesi, küme satırları ve hedef sorgular arka planda güncelleniyor.</p>
                @endif
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Bağlanan hesapların verisi birkaç dakika içinde kendiliğinden çekilmeye başlar. Kalan adımlar (tarama, WordPress eklentisi, eksik reklam hesapları) marka sayfasındaki "Kurulum durumu"nda.</p>
                <a wire:navigate href="{{ route('operator.brand', ['brand' => $brand->id]) }}" class="mt-2 inline-flex rounded-lg px-3 py-1.5 text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-600">Kurulum durumuna git</a>
            </section>
        @endif
    @endif
</div>
