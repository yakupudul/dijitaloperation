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
    $items = collect($proposal?->items ?? []);
    $services = $proposal?->services ?? [];
    $statusBadge = fn (string $status): array => match ($status) {
        'already' => ['Zaten bağlı', 'success'],
        'bound_elsewhere' => ['Başka varlığa bağlı', 'warning'],
        default => ['Öneri', 'info'],
    };
@endphp
<div class="space-y-6" @if($proposal?->isPending()) wire:poll.3s @endif>
    <div>
        <a wire:navigate href="{{ route('operator.brand', ['brand' => $brand->id]) }}" class="text-sm text-gray-500 hover:text-brand-600">← {{ $brand->name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">Otomatik kur</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Web sitesi adresinden yola çıkarak entegrasyonlardaki Search Console, GA4, İşletme Profili, Google Ads ve Meta hesaplarını bulur, sitedeki hizmetleri çıkarır. Hiçbir şey sen onaylamadan kaydedilmez.</p>
    </div>

    @if ($message !== '')
        <p role="status" @class(['rounded-lg p-3 text-sm', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $messageTone === 'success', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' => $messageTone === 'error'])>{{ $message }}</p>
    @endif

    <form wire:submit="start" class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <label class="min-w-0 flex-1 text-sm">
            <span class="block text-xs text-gray-500">Markanın web sitesi</span>
            <input wire:model="websiteUrl" type="text" placeholder="ornek.com.tr" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
            @error('websiteUrl')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
        </label>
        <button type="submit" wire:loading.attr="disabled" @disabled($proposal?->isPending()) class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">{{ $proposal ? 'Yeniden tara' : 'Önerileri hazırla' }}</button>
    </form>

    @if ($proposal?->isPending())
        <p class="rounded-lg bg-blue-50 p-4 text-sm text-blue-800 dark:bg-blue-500/10 dark:text-blue-300">Hazırlanıyor… Hesaplar alan adıyla eşleştiriliyor, GA4 veri akışları okunuyor, hizmetler çıkarılıyor.</p>
    @elseif ($proposal?->status === 'failed')
        <p class="rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">Öneriler hazırlanamadı: {{ $proposal->error_summary }}</p>
    @endif

    @if ($proposal && in_array($proposal->status, ['ready', 'applied'], true))
        @if (! empty(data_get($proposal->summary, 'brand_summary')))
            <p class="text-sm text-gray-700 dark:text-gray-300"><strong>AI özeti:</strong> {{ data_get($proposal->summary, 'brand_summary') }}@if (data_get($proposal->summary, 'sector_label')) · Sektör önerisi: <strong>{{ data_get($proposal->summary, 'sector_label') }}</strong>@endif</p>
        @endif

        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Varlıklar ve hesap bağlantıları</h2>
                @if ($proposal->status === 'ready')
                    <div class="flex gap-2 text-xs"><button type="button" wire:click="selectAll(true)" class="text-brand-600">Hepsini seç</button><button type="button" wire:click="selectAll(false)" class="text-gray-500">Temizle</button></div>
                @endif
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($items as $item)
                    @php [$badge, $color] = $statusBadge($item['status']); @endphp
                    <li wire:key="item-{{ $item['key'] }}" class="flex items-start gap-3 px-5 py-3">
                        <input type="checkbox" wire:model="selectedItems.{{ $item['key'] }}" @disabled($item['status'] !== 'proposed' || $proposal->status !== 'ready') class="mt-1 rounded border-gray-300" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $groups[$item['group']] ?? $item['group'] }} · {{ $item['label'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $item['reason'] }}@if (($item['target'] ?? '') !== '' && str_starts_with($item['target'] ?? '', 'new:')) · yeni {{ $groups[substr($item['target'], 4)] ?? '' }} varlığı açılır @endif</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-xs text-gray-400">{{ (int) round(($item['confidence'] ?? 0) * 100) }}%</span>
                            <x-ta.badge :color="$color" size="sm">{{ $badge }}</x-ta.badge>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-4 text-sm text-gray-500">Eşleşen hesap bulunamadı. Entegrasyonlarda hesapların keşfedildiğinden emin ol.</li>
                @endforelse
            </ul>
        </section>

        @php $locations = data_get($proposal->summary, 'locations'); @endphp
        @if (is_array($locations) && (! empty($locations['out_of_area']) || ! empty($locations['mentioned'])))
            <section class="rounded-xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-500/20 dark:bg-warning-500/10">
                @if ($locations['has_areas'])
                    <h2 class="text-sm font-semibold text-warning-900 dark:text-warning-200">Hizmet bölgesi dışındaki aramalar</h2>
                    <p class="mt-1 text-xs text-warning-800 dark:text-warning-300">Markanın hizmet verdiği yerler: <strong>{{ implode(' · ', $locations['areas']) }}</strong>. Site aşağıdaki konumlarla yapılan aramalarda da görünüyor. Hizmet ve anahtar kelimelere konum yazılmaz; SEO planı bu konumlar için içerik önermez.</p>
                    <ul class="mt-2 space-y-1 text-xs text-warning-900 dark:text-warning-200">
                        @foreach ($locations['out_of_area'] as $row)
                            <li><strong>{{ $row['name'] }}</strong> · {{ number_format($row['impressions']) }} gösterim · ör. {{ implode(', ', $row['queries']) }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-warning-800 dark:text-warning-300">Bu bölgelere de hizmet veriyorsan <a wire:navigate href="{{ route('operator.brand.edit', ['brandId' => $brandId]) }}" class="font-semibold underline">markanın hizmet verdiği yerlere</a> ekle; bölge sayfası önerileri ona göre üretilir.</p>
                @else
                    <h2 class="text-sm font-semibold text-warning-900 dark:text-warning-200">Markanın hizmet verdiği yerler tanımlı değil</h2>
                    <p class="mt-1 text-xs text-warning-800 dark:text-warning-300">Aramalarda en çok geçen konumlar aşağıda. Doğru olanları <a wire:navigate href="{{ route('operator.brand.edit', ['brandId' => $brandId]) }}" class="font-semibold underline">markanın hizmet verdiği yerlere</a> ekle; bölge dışı aramalar ancak o zaman ayrılabilir.</p>
                    <ul class="mt-2 space-y-1 text-xs text-warning-900 dark:text-warning-200">
                        @foreach ($locations['mentioned'] as $row)
                            <li><strong>{{ $row['name'] }}</strong> · {{ number_format($row['impressions']) }} gösterim</li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Hizmetler</h2>
                <p class="mt-0.5 text-xs text-gray-500">Katalogda olan hizmetler yeniden açılmaz; yeni olanlar önerilen sektörle kataloğa eklenir. ★ = SEO planında öncelikli.</p>
            </div>
            @if ($proposal->services_status === 'waiting_for_site')
                <p class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300">Site henüz taranmadı ve Search Console verisi yok. Onaylayınca site taraması başlar; tarama bitince "Yeniden tara" ile hizmetler önerilir.</p>
            @elseif ($services === [])
                @php $aiSummary = $proposal->summary ?? []; @endphp
                <div class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300">
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
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($services as $index => $service)
                        <li wire:key="service-{{ $index }}" class="flex items-start gap-3 px-5 py-3">
                            <input type="checkbox" wire:model="selectedServices.{{ $index }}" @disabled($proposal->status !== 'ready') class="mt-1 rounded border-gray-300" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">@if ($service['is_core'])★ @endif{{ $service['name'] }}@if (! empty($service['aliases']))<span class="font-normal text-gray-500"> · {{ implode(', ', $service['aliases']) }}</span>@endif</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $service['evidence'] }}</p>
                                @if (! empty($service['matching_phrases']))
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><span class="font-medium">Eşleştirme ifadeleri:</span> {{ implode(', ', $service['matching_phrases']) }}</p>
                                @endif
                                @if (! empty($service['keywords']))
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><span class="font-medium">{{ count($service['keywords']) }} anahtar kelime</span> (konumsuz, sorgu kütüphanesine eklenir): {{ implode(', ', array_slice(array_column($service['keywords'], 'query'), 0, 6)) }}@if (count($service['keywords']) > 6)…@endif</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1 text-xs">
                                @if ($service['status'] === 'already')
                                    <x-ta.badge color="success" size="sm">Markada var</x-ta.badge>
                                    <span class="text-gray-400">seçilirse yalnızca eksik ifadeler eklenir</span>
                                @elseif ($service['is_new'])
                                    <x-ta.badge color="warning" size="sm">Yeni · {{ $service['sector_label'] ?? 'sektör yok' }}</x-ta.badge>
                                @else
                                    <x-ta.badge color="info" size="sm">Katalogda var</x-ta.badge>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($proposal->status === 'ready')
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="approve" wire:loading.attr="disabled" class="rounded-lg bg-success-500 px-5 py-3 text-sm font-semibold text-white hover:bg-success-600 disabled:opacity-50">Seçilenleri onayla</button>
                <p class="text-xs text-gray-500">Onaydan sonra hesaplar bağlanır, hizmetler markaya eklenir; Search Console bağlandıysa ilk SEO planı kuyruğa alınır. Dış platformlarda hiçbir değişiklik yapılmaz.</p>
            </div>
        @endif

        @if ($proposal->status === 'applied')
            <section class="rounded-xl bg-gray-50 p-5 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Uygulandı · {{ $proposal->applied_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</h2>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($proposal->apply_result ?? [] as $row)
                        <li @class(['text-success-700 dark:text-success-400' => $row['ok'], 'text-error-600' => ! $row['ok']])>{{ $row['ok'] ? '✓' : '✗' }} {{ $row['label'] }} — {{ $row['message'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
