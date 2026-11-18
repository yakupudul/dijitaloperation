{{-- Sonuçlar: what went out and what it brought, then the watched accounts' posts that are doing well (Trendler). --}}
@php
    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $i = $results['instagram'];
@endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <h2 class="section-title">Son {{ $results['days'] }} gün</h2>
    <span class="inline-flex gap-1">
        <button type="button" wire:click="$set('period', 7)" @class(['btn', 'border-second bg-second-soft text-second' => $period === 7])>7 gün</button>
        <button type="button" wire:click="$set('period', 28)" @class(['btn', 'border-second bg-second-soft text-second' => $period === 28])>28 gün</button>
    </span>
</div>
<div class="metric-strip grid-cols-2 lg:grid-cols-5" data-social-results>
    @foreach ($results['channels'] as $c)
        <div class="bg-white p-4 dark:bg-gray-900">
            <p class="text-[13px] text-gray-500">{{ $c['label'] }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($c['published']) }} <span class="text-sm font-normal text-gray-500">paylaşım</span></p>
            <p class="mt-1 text-xs text-gray-500">{{ $c['measured'] ? ($c['reach'] > 0 ? $n($c['reach']).' erişim · ' : '').$n($c['views']).' görüntülenme · '.$n($c['likes']).' beğeni · '.$n($c['comments']).' yorum' : ($c['key'] === 'linkedin_me' ? 'LinkedIn kişisel gönderi sayılarını vermiyor' : ($c['key'] === 'facebook' ? 'Ölçüm bu kanalda henüz okunmuyor' : ($c['published'] > 0 ? 'Sayılar henüz okunmadı' : 'Bu dönemde paylaşım yok'))) }}</p>
        </div>
    @endforeach
</div>
<div class="grid gap-6 xl:grid-cols-2">
    <section class="card" data-social-instagram-numbers>
        <div class="flex items-baseline justify-between gap-2 px-4 py-3.5"><h2 class="section-title">Instagram</h2><p class="text-[13px] text-gray-500">{{ $i['measured'] }} paylaşım ölçüldü</p></div>
        <dl class="grid grid-cols-2 gap-px border-t border-gray-200 bg-gray-200 sm:grid-cols-3 dark:border-gray-800 dark:bg-gray-800">
            @foreach (['Erişim' => $n($i['reach']), 'Görüntülenme' => $n($i['views']), 'Ortalama video izlenme' => $i['watch'] !== null ? str_replace('.', ',', (string) $i['watch']).' sn' : '—', 'Beğeni' => $n($i['likes']), 'Yorum' => $n($i['comments']), 'Kaydetme ve gönderme' => $n($i['saved'] + $i['shares'])] as $label => $value)
                <div class="bg-white p-4 dark:bg-gray-900"><dt class="text-[13px] text-gray-500">{{ $label }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>
    <section class="card" data-social-top>
        <div class="px-4 py-3.5"><h2 class="section-title">En çok erişen paylaşımlar</h2></div>
        @forelse ($results['top'] as $target)
            <button type="button" wire:click="openPost({{ $target->post->id }})" class="card-row w-full text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                @include('livewire.operator.social.partials.thumb', ['post' => $target->post, 'size' => 'h-11 w-9'])
                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $target->post->title ?: mb_strimwidth((string) $target->post->caption, 0, 80, '…') }}</span><span class="block truncate text-xs text-gray-500">{{ $kindName($target->post) }} · {{ $target->published_at?->timezone($tz)->translatedFormat('j F') }} · {{ \App\Services\Social\SocialInsights::line($target) }}</span></span>
                <span class="shrink-0 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n((int) $target->insights['reach']) }}</span>
            </button>
        @empty
            <p class="border-t border-gray-200 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-800">Bu dönemde ölçülen paylaşım yok.</p>
        @endforelse
    </section>
</div>

{{-- Trendler (yakup 2026-11-13): posts of watched accounts that did far better than that account's usual. --}}
<section class="card space-y-4 p-5" data-social-trends>
    <div>
        <h2 class="section-title">Şu an tutan gönderiler</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">İzlediğiniz hesapların son üç haftadaki gönderileri okunur; hesabın kendi ortalamasının en az iki katı beğeni ve yorum alan gönderi burada görünür. Yalnızca işletme ve içerik üretici hesapları okunabilir.</p>
    </div>
    @if ($canWrite)
        <details @if (($trends['read_at'] ?? null) === null) open @endif>
            <summary class="cursor-pointer text-sm text-gray-600 dark:text-gray-300">İzlenen hesaplar</summary>
            <label class="mt-2 block text-sm font-medium">Her satıra bir kullanıcı adı
                <textarea wire:model="watchlist" rows="5" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="kullaniciadi"></textarea></label>
            <button type="button" wire:click="saveWatchlist" class="btn mt-2" wire:loading.attr="disabled">Listeyi kaydet</button>
        </details>
        <button type="button" wire:click="readTrends" class="btn-primary" wire:loading.attr="disabled" wire:target="readTrends"><span wire:loading.remove wire:target="readTrends">Şimdi oku</span><span wire:loading wire:target="readTrends">Okunuyor…</span></button>
    @endif
    @if (($trends['read_at'] ?? null) !== null)
        <p class="text-xs text-gray-500">Son okuma: {{ \Illuminate\Support\Carbon::parse($trends['read_at'])->timezone($tz)->translatedFormat('j F H:i') }} · {{ count($trends['accounts']) }} hesap okundu{{ $trends['errors'] !== [] ? ', '.count($trends['errors']).' hesap okunamadı ('.implode(', ', array_filter(array_keys($trends['errors']))).')' : '' }}.</p>
    @endif
</section>
@if (($trends['hits'] ?? []) !== [])
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-trend-hits>
        @foreach ($trends['hits'] as $hit)
            <a href="{{ $hit['permalink'] }}" target="_blank" rel="noopener" class="card block space-y-2 p-4 hover:border-brand-500">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="tone-ok rounded-full px-2 py-0.5">ortalamasının {{ str_replace('.', ',', (string) $hit['times']) }} katı</span>
                    <span class="text-gray-500">{{ $hit['type'] === 'reels' ? 'Reels' : ($hit['type'] === 'carousel_album' ? 'Kaydırmalı' : 'Görsel') }} · {{ \Illuminate\Support\Carbon::parse($hit['at'])->timezone($tz)->translatedFormat('j F') }}</span>
                </div>
                <p class="text-sm font-semibold">{{ '@'.$hit['account'] }} <span class="font-normal text-gray-500">· {{ number_format($hit['followers'], 0, ',', '.') }} takipçi</span></p>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $hit['caption'] !== '' ? $hit['caption'] : 'Açıklama yok.' }}</p>
                <p class="text-xs text-gray-500">{{ number_format($hit['likes'], 0, ',', '.') }} beğeni · {{ number_format($hit['comments'], 0, ',', '.') }} yorum · Instagram’da aç ↗</p>
            </a>
        @endforeach
    </div>
@elseif (($trends['read_at'] ?? null) !== null)
    <x-ta.empty-state size="sm" title="Bu okumada ortalamasının iki katını geçen gönderi yok." />
@endif
