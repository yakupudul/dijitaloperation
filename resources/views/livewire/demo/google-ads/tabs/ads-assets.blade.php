@php
    $isTr = app()->getLocale() === 'tr';
    $ads = $data['ads'] ?? [];
    $rows = collect($ads['rows'] ?? []);
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Reklamlar & kreatifler' : 'Ads & creatives' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google Ads reklam envanterini, reklam türünü, durumunu, hedef URL’yi ve sağlayıcının Ad Strength bilgisini inceleyin.' : 'Inspect Google Ads inventory, ad type, state, final URL and provider Ad Strength.' }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ta.metric-card :label="$isTr ? 'Reklam envanteri' : 'Ad inventory'" :value="$rows->count() ? (string)$rows->count() : '—'" />
        <x-ta.metric-card :label="$isTr ? 'Aktif / enabled' : 'Enabled'" :value="$rows->count() ? (string)$rows->filter(fn($r) => strtoupper((string)($r['state'] ?? '')) === 'ENABLED')->count() : '—'" />
        <x-ta.metric-card :label="$isTr ? 'Final URL bulunan' : 'With final URL'" :value="$rows->count() ? (string)$rows->filter(fn($r) => filled($r['final_url'] ?? null))->count() : '—'" />
        <x-ta.metric-card label="Ad Strength" :value="$rows->count() ? (string)$rows->filter(fn($r) => filled($r['google_strength'] ?? null) && ($r['google_strength'] ?? null) !== 'Unavailable')->count() : '—'" :delta="$isTr ? 'Performans skoru değildir' : 'Not a performance score'" />
    </div>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam envanteri' : 'Ad inventory' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Policy approval ve mesaj/landing-page uyumu yalnız gerçek provider/cross-asset veri olduğunda gösterilir; burada tahmin edilmez.' : 'Policy approval and message/landing-page alignment are shown only when backed by provider/cross-asset data; they are not guessed here.' }}</p></div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
                    <th class="px-4 py-2.5 text-left">Ad</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Tür' : 'Type' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'State' }}</th>
                    <th class="px-3 py-2.5 text-left">Final URL</th>
                    <th class="px-3 py-2.5 text-left">Ad Strength</th>
                    <th class="px-4 py-2.5"></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row['name'] ?? ('Ad '.($row['id'] ?? '')) }}</td>
                            <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['type'] ?? '—' }}</td>
                            <td class="px-3 py-2.5"><x-ta.badge :color="strtoupper((string)($row['state'] ?? '')) === 'ENABLED' ? 'success' : 'light'" size="sm">{{ $row['state'] ?? '—' }}</x-ta.badge></td>
                            <td class="max-w-[360px] truncate px-3 py-2.5 text-xs text-gray-500" title="{{ $row['final_url'] ?? '' }}">{{ $row['final_url'] ?: '—' }}</td>
                            <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ ($row['google_strength'] ?? null) && $row['google_strength'] !== 'Unavailable' ? $row['google_strength'] : '—' }}</td>
                            <td class="px-4 py-2.5 text-right"><button type="button" wire:click="openAd('{{ $row['id'] }}')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Detay' : 'Inspect' }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Reklam snapshot verisi henüz kullanılabilir değil. Aşağıdaki günlük reklam performansı tablosu mevcutsa provider performans verisini göstermeye devam eder.' : 'Ad snapshot data is not yet usable. The daily ad-performance table below can still show provider performance when available.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

@if ($selectedAd)
    <x-demo.gads-drawer :title="$selectedAd['name']" :subtitle="$selectedAd['type'] ?? 'Google Ads'">
        <div><p class="text-xs text-gray-400">{{ $isTr ? 'Durum' : 'State' }}</p><p class="font-medium">{{ $selectedAd['state'] ?? '—' }}</p></div>
        <div><p class="text-xs text-gray-400">Final URL</p><p class="break-all font-medium">{{ $selectedAd['final_url'] ?: '—' }}</p></div>
        <div><p class="text-xs text-gray-400">Google Ad Strength</p><p>{{ ($selectedAd['google_strength'] ?? null) && $selectedAd['google_strength'] !== 'Unavailable' ? $selectedAd['google_strength'] : '—' }}</p><p class="mt-1 text-[11px] text-gray-400">{{ $isTr ? 'Google sağlayıcı kreatif-tamlık sinyalidir; performans skoru veya MOXDOP yargısı değildir.' : 'A Google provider creative-completeness signal; not a performance score or MOXDOP judgement.' }}</p></div>
        @if (! empty($selectedAd['headlines']))
            <div><p class="text-xs text-gray-400">Headlines</p><ul class="mt-1 list-disc pl-4 text-sm">@foreach ($selectedAd['headlines'] as $h)<li>{{ $h }}</li>@endforeach</ul></div>
        @endif
    </x-demo.gads-drawer>
@endif
