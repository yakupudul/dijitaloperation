{{-- Bugün: today's posts in time order, what waits for the operator, and how many days of ready content each line has. --}}
@php
    $out = $today->filter(fn ($p) => in_array($p->status, ['published', 'partial'], true))->count();
    $next = $today->first(fn ($p) => $p->publish_at->gt($now) && in_array($p->status, ['draft', 'approved'], true));
    $nowShown = false;
    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $maxDays = max(30, collect($stock)->max('days'));
@endphp
<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
    <div class="min-w-0 space-y-6">
        <section class="card" data-social-today>
            <div class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3.5">
                <h2 class="section-title">Bugün, {{ $now->translatedFormat('j F l') }}</h2>
                <p class="text-[13px] text-gray-500">{{ $today->count() }} paylaşım{{ $today->count() > 0 ? ': '.$out.' çıktı, '.($today->count() - $out).' sırada' : '' }}</p>
            </div>
            @forelse ($today as $post)
                @if (! $nowShown && $post->publish_at->gt($now))
                    @php $nowShown = true; @endphp
                    <div class="flex items-center gap-3 border-t border-gray-200 px-4 py-1.5 text-xs text-error-600 dark:border-gray-800" data-social-now><span class="shrink-0 tabular-nums">Şimdi {{ $now->format('H:i') }}</span><span class="h-px flex-1 bg-error-300 dark:bg-error-500/40"></span></div>
                @endif
                @include('livewire.operator.social.partials.row', ['post' => $post, 'when' => 'time'])
            @empty
                <div class="border-t border-gray-200 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-800">Bugün için planlı paylaşım yok. <button type="button" wire:click="setTab('takvim')" class="link-neutral">Takvime bak</button></div>
            @endforelse
        </section>

        <section data-social-quick>
            <div class="section-head justify-between"><h2 class="section-title">Son yedi günün sonucu</h2><button type="button" wire:click="setTab('sonuclar')" class="link-neutral text-[13px]">Sonuçlara git</button></div>
            <div class="metric-strip grid-cols-2 sm:grid-cols-4">
                <div class="bg-white p-4 dark:bg-gray-900"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($quick['instagram']['reach']) }}</p><p class="mt-1 text-[13px] text-gray-500">Instagram erişimi</p></div>
                <div class="bg-white p-4 dark:bg-gray-900"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($quick['instagram']['views']) }}</p><p class="mt-1 text-[13px] text-gray-500">görüntülenme</p></div>
                <div class="bg-white p-4 dark:bg-gray-900"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $quick['instagram']['watch'] !== null ? str_replace('.', ',', (string) $quick['instagram']['watch']).' sn' : '—' }}</p><p class="mt-1 text-[13px] text-gray-500">ortalama video izlenme</p></div>
                <div class="bg-white p-4 dark:bg-gray-900"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n(collect($quick['channels'])->sum('published')) }}</p><p class="mt-1 text-[13px] text-gray-500">paylaşım (bütün kanallar)</p></div>
            </div>
        </section>
    </div>

    <div class="min-w-0 space-y-6">
        <section class="card" data-social-waiting>
            <div class="flex items-baseline justify-between gap-2 px-4 py-3.5">
                <h2 class="section-title">Sizi bekleyen</h2>
                <p class="text-[13px] text-gray-500">{{ $waitingTotal > 0 ? $waitingTotal.' iş' : 'iş yok' }}</p>
            </div>
            @foreach ($waiting as $item)
                <div class="card-row items-start" data-waiting="{{ $item['key'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $item['text'] }}</p>
                    </div>
                    <button type="button" class="btn" wire:click="jump('{{ $item['tab'] }}', '{{ $item['params']['status'] ?? '' }}', {{ (int) ($item['params']['open'] ?? 0) }}, '{{ $item['params']['section'] ?? '' }}')">{{ $item['cta'] }}</button>
                </div>
            @endforeach
            @foreach ($manual as $target)
                {{-- A LinkedIn page without an API connection: the text is copied and posted by hand. --}}
                <div class="card-row flex-col items-stretch gap-2" x-data="{ copied: false }" data-manual>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">LinkedIn’de paylaşmanız bekleniyor</p>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $target->post?->title ?? 'Gönderi' }} · {{ $target->account?->displayName() }}</p>
                    </div>
                    <textarea x-ref="text" readonly rows="3" class="w-full rounded-lg border border-gray-200 bg-gray-50 p-2.5 text-[13px] dark:border-gray-800 dark:bg-gray-900">{{ $target->post?->linkedin_text }}</textarea>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn" x-on:click="navigator.clipboard.writeText($refs.text.value); copied = true"><span x-text="copied ? 'Kopyalandı' : 'Metni kopyala'"></span></button>
                        @foreach ($target->post?->mediaItems() ?? [] as $i => $item)
                            <a class="btn" href="{{ $item['url'] }}" download>{{ $item['type'] === 'video' ? 'Videoyu' : 'Görseli' }} indir{{ count($target->post->mediaItems()) > 1 ? ' '.($i + 1) : '' }}</a>
                        @endforeach
                        <a class="btn" href="{{ $target->account?->profileUrl() ?? 'https://www.linkedin.com/' }}" target="_blank" rel="noopener">LinkedIn’i aç ↗</a>
                        @if ($canWrite)<button type="button" class="btn-primary" wire:click="markDone({{ $target->id }})">Paylaştım</button>@endif
                    </div>
                </div>
            @endforeach
            @if ($waitingTotal === 0)
                <p class="border-t border-gray-200 px-4 py-6 text-sm text-gray-500 dark:border-gray-800">Sizden beklenen bir şey yok. Onaylı gönderiler saatinde kendiliğinden çıkar{{ $next ? '; sıradaki '.$next->publish_at->timezone($tz)->format('H:i').'’de' : '' }}.</p>
            @endif
        </section>

        <section class="card" data-social-stock>
            <div class="flex items-baseline justify-between gap-2 px-4 py-3.5">
                <h2 class="section-title">Hazır içerik kaç gün yeter</h2>
                <p class="text-[13px] text-gray-500">{{ \App\Services\Social\SocialScreen::STOCK_LOW }} günün altı kırmızı</p>
            </div>
            <div class="space-y-3 border-t border-gray-200 px-4 py-4 dark:border-gray-800">
                @foreach ($stock as $row)
                    <div class="flex items-center gap-3 text-[13px]" data-stock="{{ $row['label'] }}" title="{{ $row['count'] }} hazır paylaşım">
                        <span class="w-[124px] shrink-0 text-gray-700 dark:text-gray-300">{{ $row['label'] }}</span>
                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"><span class="block h-full rounded-full {{ $row['low'] ? 'bg-error-500' : 'bg-second' }}" style="width: {{ $row['days'] === 0 ? 2 : min(100, round($row['days'] / $maxDays * 100)) }}%"></span></span>
                        <span class="w-[52px] shrink-0 text-right font-medium tabular-nums {{ $row['low'] ? 'text-error-600' : 'text-gray-900 dark:text-white' }}">{{ $row['days'] }} gün</span>
                    </div>
                @endforeach
            </div>
            <p class="border-t border-gray-200 px-4 py-3 text-[13px] text-gray-500 dark:border-gray-800">Gün sayısı, o kanalda hazır bekleyen son paylaşımın tarihine kadar olan süredir.</p>
        </section>
    </div>
</div>
