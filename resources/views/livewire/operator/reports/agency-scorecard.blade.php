@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $t = $data['totals'];
    $writeLabels = ['google_ads|negative_list_add' => 'Google Ads negatif kelime eklendi', 'wordpress|draft_create' => 'WordPress taslak sayfa', 'wordpress|site_fix' => 'WordPress SEO/teknik düzeltme',
        'gbp|review_reply' => 'İşletme Profili yorum yanıtı', 'gbp|local_post' => 'İşletme Profili gönderisi', 'google_ads|campaign_status' => 'Google Ads kampanya durdur/başlat', 'google_ads|campaign_budget' => 'Google Ads bütçe değişikliği'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Ajans karnesi</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Seçilen ayda tüm markalarda yapılan işler, sistemden uygulanan değişiklikler, gönderilen raporlar ve daha önce yapılan işlerin ölçülen etkisi.</p>
        </div>
        <label class="text-sm"><span class="block text-xs text-gray-500">Ay</span>
            <select wire:model.live="month" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($months as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
    </div>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([['SEO işi bitti', $t['seo']], ['Danışman önerisi uygulandı', $t['advisor']], ['Beyin önerisi uygulandı', $t['brain']], ['Sistemden yapılan değişiklik', $t['writes']], ['Gönderilen rapor', $t['reports']], ['Harcanan süre', round($t['minutes'] / 60, 1).' sa']] as [$label, $value])
            <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">{{ $label }}</div><div class="text-xl font-semibold">{{ $value }}</div></div>
        @endforeach
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="{{ $card }} p-5">
            <h2 class="font-semibold text-gray-800 dark:text-white">Ölçülen kazanımlar</h2>
            <p class="mt-1 text-xs text-gray-500">Bu ay ölçümü tamamlanan (28/56 gün önce-sonra) ve doğru yönde değişen işler.</p>
            @forelse ($data['wins'] as $win)
                <div class="mt-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
                    <div class="font-medium">{{ $win['title'] }}</div>
                    <div class="text-xs text-gray-500">{{ $win['brand'] }} · {{ $win['metric'] }}: {{ $win['before'] }} → {{ $win['after'] }} <span class="font-semibold {{ $win['change_pct'] > 0 ? 'text-success-600' : 'text-success-600' }}">({{ $win['change_pct'] > 0 ? '+' : '' }}{{ $win['change_pct'] }}%)</span> · {{ $win['days'] }} gün</div>
                </div>
            @empty
                <p class="mt-3 text-sm text-gray-500">Bu ay ölçümü tamamlanan olumlu sonuç yok.</p>
            @endforelse
        </section>
        <section class="{{ $card }} p-5">
            <h2 class="font-semibold text-gray-800 dark:text-white">Sistemden uygulanan değişiklikler</h2>
            @forelse ($data['writes'] as $key => $count)
                <div class="mt-2 flex justify-between text-sm"><span>{{ $writeLabels[$key] ?? $key }}</span><span class="font-semibold">{{ $count }}</span></div>
            @empty
                <p class="mt-3 text-sm text-gray-500">Bu ay sistemden değişiklik uygulanmadı.</p>
            @endforelse
        </section>
    </div>

    <section class="{{ $card }} overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs text-gray-500"><tr><th class="px-4 py-3">Marka</th><th class="py-3 text-right">Biten iş</th><th class="py-3 text-right">Uygulanan değişiklik</th><th class="py-3 text-right">Süre</th><th class="px-4 py-3">Rapor</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($data['brands'] as $row)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ route('operator.brand', ['brand' => $row['brand_id']]) }}" wire:navigate class="hover:text-brand-600">{{ $row['brand'] }}</a></td>
                        <td class="py-2 text-right tabular-nums">{{ $row['done'] }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $row['writes'] }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $row['hours'] }} sa</td>
                        <td class="px-4 py-2 text-xs">{{ $row['report'] ? 'Gönderildi' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-5 text-sm text-gray-500">Bu ay kayıtlı iş yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
