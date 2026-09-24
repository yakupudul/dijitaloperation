@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $fieldLabels = ['title' => 'Başlık', 'h1' => 'H1', 'description' => 'Açıklama'];
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Rakip izleme</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Markanın ve çevredeki rakiplerin Google yorumları (puan, hız, yanıt oranı, olumsuz yorumlarda tekrar eden konular), onaylı rakiplerin sitesindeki haftalık değişiklikler ve Meta Reklam Kütüphanesi bağlantıları. Ajans içi görünüm; yorum yazanların adı saklanmaz.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif

    <label class="block max-w-sm text-sm"><span class="text-xs text-gray-500">Marka</span>
        <select wire:model.live="brand" class="{{ $input }}">@foreach ($brands as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select>
    </label>

    <nav class="flex gap-1 border-b border-gray-200 dark:border-gray-800">
        @foreach (['reviews' => 'Yorumlar', 'sites' => 'Siteler', 'ads' => 'Meta reklamları', 'auction' => 'Google Ads açık artırma'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-500 text-brand-600' => $tab === $key, 'border-transparent text-gray-500' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($settings !== null && $tab === 'reviews' && ($themesInsight ?? null))
        <x-operator.ai-insight :insight="$themesInsight" />
    @endif
    @if ($settings !== null && $tab === 'reviews')
        <div class="flex flex-wrap items-center gap-2">
            @if ($isAdmin)
                <x-ta.button type="button" wire:click="refreshReviews" size="sm">Yorumları oku (≈ {{ number_format($estimate, 3) }} USD)</x-ta.button>
                <x-ta.button type="button" wire:click="toggleReviews" size="sm" variant="outline">{{ $settings->reviews_enabled ? 'Otomatik okuma açık' : 'Otomatik okuma kapalı' }}</x-ta.button>
            @endif
            <span class="text-xs text-gray-500">Bu ay {{ number_format($spent, 2) }} / {{ number_format($settings->monthly_usd, 2) }} USD · son okuma {{ $settings->reviews_refreshed_at?->format('d.m.Y') ?? '—' }}. Rakipler son harita taramasında öne çıkanlardan seçilir.</span>
        </div>
        <section class="{{ $card }}">
            @if ($comparison === [])
                <p class="text-sm text-gray-500">Henüz profil yok. "Yorumları oku" markanın profilini ve son harita taramasındaki rakipleri ekler.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">İşletme</th><th>Puan</th><th>Yorum</th><th>Son 30 / 90 gün</th><th>90 gün ort.</th><th>Yanıt oranı</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($comparison as $row)
                            <tr wire:key="rp-{{ $row['id'] }}" @class(['font-semibold' => $row['is_own']])>
                                <td class="py-1.5"><button type="button" wire:click="$set('profile', {{ $row['id'] }})" class="text-left text-brand-600 hover:underline">{{ $row['title'] }}{{ $row['is_own'] ? ' (marka)' : '' }}</button></td>
                                <td>{{ $row['rating'] ?? '—' }}@if ($row['rating_change'] !== null) <span @class(['text-xs', 'text-emerald-600' => $row['rating_change'] > 0, 'text-rose-600' => $row['rating_change'] < 0])>({{ $row['rating_change'] > 0 ? '+' : '' }}{{ $row['rating_change'] }})</span>@endif</td>
                                <td>{{ $row['reviews_count'] ?? '—' }}</td>
                                <td>{{ $row['last30'] }} / {{ $row['last90'] }}</td>
                                <td>{{ $row['avg90'] ?? '—' }}</td>
                                <td>{{ $row['response_rate'] !== null ? '%'.$row['response_rate'] : '—' }}</td>
                                <td>@if ($isAdmin && ! $row['is_own'])<button type="button" wire:click="toggleProfile({{ $row['id'] }})" class="text-xs text-gray-500 hover:underline">izlemeyi bırak</button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">"Son 30 / 90 gün" ve yanıt oranı okunan son {{ config('moxdop-intel.reviews.depth') }} yoruma göredir.</p>
            @endif
        </section>

        @if ($selectedProfile !== null)
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $selectedProfile['title'] }} — olumsuz yorumlarda tekrar edenler</h2>
                @if ($themes === [])
                    <p class="mt-2 text-sm text-gray-500">Tekrar eden bir konu yok (en az iki olumsuz yorumda geçmesi gerekir).</p>
                @else
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($themes as $theme)<span class="rounded-full bg-rose-50 px-2.5 py-1 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-300">{{ $theme['phrase'] }} · {{ $theme['count'] }}</span>@endforeach
                    </div>
                @endif
                @foreach ($recentNegative as $review)
                    <blockquote class="mt-3 border-l-2 border-rose-300 pl-3 text-sm text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($review->text, 280) }} <span class="text-xs text-gray-500">— {{ $review->rating }}★ {{ $review->published_at ? \Illuminate\Support\Carbon::parse($review->published_at)->format('d.m.Y') : '' }}</span></blockquote>
                @endforeach
            </section>
        @endif

        @if ($isAdmin)
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Rakip profili ekle</h2>
                <div class="mt-2 flex flex-wrap items-end gap-2">
                    <label class="text-sm"><span class="text-xs text-gray-500">Ad</span><input type="text" wire:model="manualTitle" class="{{ $input }}"></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">CID (Haritalar bağlantısındaki cid=…)</span><input type="text" wire:model="manualCid" class="{{ $input }}"></label>
                    <x-ta.button type="button" wire:click="addProfile" size="sm" variant="outline">Ekle</x-ta.button>
                </div>
            </section>
        @endif
    @endif

    @if ($settings !== null && $tab === 'sites')
        <div class="flex flex-wrap items-center gap-2">
            @if ($isAdmin)<x-ta.button type="button" wire:click="watchNow" size="sm" variant="outline">Şimdi oku</x-ta.button>@endif
            <span class="text-xs text-gray-500">Onaylı rakiplerin ana sayfası ve site haritası her pazartesi okunur (ücretsiz). {{ $competitors->count() }} onaylı rakip.</span>
        </div>
        @forelse ($sites as $domain => $rows)
            @php
                $now = $rows->first();
                $newUrls = (array) json_decode((string) $now->new_urls, true);
                $changes = (array) json_decode((string) $now->changes, true);
            @endphp
            <section class="{{ $card }}" wire:key="site-{{ md5($domain) }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $domain }}</h2>
                    <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($now->observed_on)->format('d.m.Y') }} · {{ $now->status === 'ok' ? 'okundu' : 'okunamadı' }} · {{ $now->sitemap_urls_count !== null ? $now->sitemap_urls_count.' URL' : 'site haritası yok' }}</span>
                </div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $now->title ?? '—' }}@if ($now->h1) <span class="text-gray-400">·</span> {{ $now->h1 }}@endif</p>
                @foreach ($changes as $field => $change)
                    <p class="mt-1 text-xs text-amber-700">{{ $fieldLabels[$field] ?? $field }} değişti: "{{ \Illuminate\Support\Str::limit((string) $change['before'], 90) }}" → "{{ \Illuminate\Support\Str::limit((string) $change['after'], 90) }}"</p>
                @endforeach
                @if ($newUrls !== [] || $now->removed_urls_count > 0)
                    <p class="mt-2 text-xs text-gray-500">{{ count($newUrls) }} yeni sayfa · {{ $now->removed_urls_count }} kaldırılan</p>
                    <ul class="mt-1 space-y-0.5 text-xs">
                        @foreach (array_slice($newUrls, 0, 15) as $url)<li class="truncate"><a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">{{ $url }}</a></li>@endforeach
                    </ul>
                @elseif ($rows->count() === 1)
                    <p class="mt-2 text-xs text-gray-500">İlk okuma; değişiklikler gelecek haftadan itibaren görünür.</p>
                @else
                    <p class="mt-2 text-xs text-gray-500">Geçen haftadan beri değişiklik yok.</p>
                @endif
            </section>
        @empty
            <section class="{{ $card }} text-sm text-gray-500">Henüz okuma yok. Rakipler Kütüphane › Rakipler'de onaylanınca izlenir.</section>
        @endforelse
    @endif

    @if ($settings !== null && $tab === 'ads')
        <section class="{{ $card }}">
            <p class="text-sm text-gray-600 dark:text-gray-300">Meta'nın Reklam Kütüphanesi API'si Türkiye'de yalnız siyasi reklamları verir; ticari reklamlar için kütüphane bağlantısı açılır. Sayfa kimliği girilirse doğrudan o sayfanın aktif reklamları görünür, yoksa adla arama yapılır.</p>
            <table class="mt-3 w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">İşletme</th><th>Facebook sayfa kimliği</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <tr>
                        <td class="py-1.5 font-semibold">{{ $brands->firstWhere('id', $brand)?->name }} (marka)</td>
                        <td><input type="text" wire:model="pageIds.brand" class="w-40 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"></td>
                        <td><a href="{{ \App\Services\Intel\CompetitorSiteWatch::adLibraryUrl($settings->facebook_page_id, (string) $brands->firstWhere('id', $brand)?->name) }}" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 hover:underline">Reklam Kütüphanesi →</a></td>
                    </tr>
                    @foreach ($competitors as $competitor)
                        <tr wire:key="ad-{{ $competitor->id }}">
                            <td class="py-1.5">{{ $competitor->display_name ?: $competitor->normalized_domain }}</td>
                            <td><input type="text" wire:model="pageIds.{{ $competitor->id }}" class="w-40 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"></td>
                            <td><a href="{{ \App\Services\Intel\CompetitorSiteWatch::adLibraryUrl($competitor->facebook_page_id, (string) ($competitor->display_name ?: $competitor->normalized_domain)) }}" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 hover:underline">Reklam Kütüphanesi →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-ta.button type="button" wire:click="savePageIds" size="sm" variant="outline" class="mt-3">Kimlikleri kaydet</x-ta.button>
        </section>
    @endif

    @if ($settings !== null && $tab === 'auction')
        @forelse ($auction as $account)
            <section class="{{ $card }}" wire:key="auction-{{ $account['asset_id'] }}">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ $account['asset_name'] }}</h2>
                    <a href="{{ route('operator.google-ads.overview', ['assetId' => $account['asset_id'], 'tab' => 'auction_insights']) }}" wire:navigate class="text-xs text-brand-600 hover:underline">Rapor yükle →</a>
                </div>
                <div class="mt-2">@include('livewire.operator.google-ads.partials.auction-insights-table', ['latest' => $account['latest']])</div>
            </section>
        @empty
            <section class="{{ $card }} text-sm text-gray-500">Bu markada Google Ads hesabı yok.</section>
        @endforelse
    @endif
</div>
