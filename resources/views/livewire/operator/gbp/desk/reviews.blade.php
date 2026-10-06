<div class="space-y-5 dark:text-gray-200" data-gbp-reviews>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme profilleri</h1>
            <p class="mt-1 max-w-3xl text-xs text-gray-500">Yorum sayısı, yorumların tazeliği ve yanıtlanması yerel sıralamada en güçlü sinyallerden. Bütün profillerin yanıtsız yorumları tek listede: yapay zekâ taslak yazar, sen okuyup gönderirsin (gönderilen yanıt geri alınabilir). “Yorum isteme” bölümünde her şube için Google yorum bağlantısı, QR kod, müşteriye gönderilecek mesaj ve yazdırılabilir kart var.</p>
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-reviews', 'brandFilter' => $brand])
    @include('livewire.operator.gbp.partials.desk-message')

    <section class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Yanıtsız yorum</p><p class="mt-1 text-2xl font-semibold tabular-nums {{ $totals['unanswered'] > 0 ? 'text-rose-600' : 'text-gray-900 dark:text-white' }}">{{ $totals['unanswered'] }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">{{ \App\Services\Gbp\GbpDailyWorkspace::REPLY_SLA_HOURS }} saatten uzun bekleyen</p><p class="mt-1 text-2xl font-semibold tabular-nums {{ $totals['late'] > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">{{ $totals['late'] }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Son {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} günde gelen yorum</p><p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $totals['recent'] }}</p></div>
    </section>

    <div class="inline-flex rounded-lg bg-gray-100 p-1 text-sm dark:bg-white/[0.06]">
        <button type="button" wire:click="setSection('yanit')" @class(['rounded-md px-3 py-1 font-medium', 'bg-white shadow-sm text-gray-900 dark:bg-gray-800 dark:text-white' => $section === 'yanit', 'text-gray-500' => $section !== 'yanit'])>Yanıt bekleyenler ({{ $totals['unanswered'] }})</button>
        <button type="button" wire:click="setSection('iste')" @class(['rounded-md px-3 py-1 font-medium', 'bg-white shadow-sm text-gray-900 dark:bg-gray-800 dark:text-white' => $section === 'iste', 'text-gray-500' => $section !== 'iste'])>Yorum isteme</button>
    </div>

    @if ($section === 'yanit')
        <section class="flex flex-wrap items-center gap-2 text-xs">
            @foreach (['' => 'Tümü', 'low' => '1–2 yıldız', 'mid' => '3 yıldız', 'high' => '4–5 yıldız'] as $key => $label)
                <button type="button" wire:click="setRating('{{ $key }}')" @class(['rounded-full px-3 py-1 font-medium ring-1 ring-inset', 'bg-brand-500 text-white ring-brand-500' => $rating === $key, 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $rating !== $key])>{{ $label }}</button>
            @endforeach
            @if ($canWrite)
                <span class="ml-auto flex flex-wrap gap-2">
                    @if ($undrafted > 0)<button type="button" wire:click="draftAll" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">Taslakları yaz ({{ min($undrafted, \App\Services\Gbp\Desk\ReviewDesk::DRAFT_BATCH) }})</button>@endif
                    @if ($drafted > 0)<button type="button" wire:click="sendDrafts" wire:confirm="Taslağı hazır {{ $drafted }} yanıt (düzenlediklerin dahil) Google’a gönderilsin mi?" class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600">Taslağı hazır olanları gönder ({{ $drafted }})</button>@endif
                </span>
            @endif
        </section>

        <section class="space-y-3" @if ($drafting) wire:poll.10s @endif>
            @forelse ($reviews as $review)
                @php
                    $action = $review['action'];
                    $busy = $action !== null && in_array($action['status'], ['queued', 'running', 'succeeded'], true);
                    $failedDraft = is_string($review['draft_state']) && str_starts_with($review['draft_state'], 'failed');
                @endphp
                <article wire:key="rv-{{ $review['id'] }}" class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $names[$review['asset_id']] ?? '—' }}</span>
                        <span @class(['font-semibold', 'text-rose-600' => ($review['rating'] ?? 5) <= 2, 'text-amber-600' => ($review['rating'] ?? 5) === 3, 'text-emerald-600' => ($review['rating'] ?? 0) >= 4]) aria-label="{{ $review['rating'] }} yıldız">{{ $review['rating'] !== null ? str_repeat('★', $review['rating']).str_repeat('☆', 5 - $review['rating']) : '—' }}</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $review['reviewer'] }}</span>
                        <span @class(['ml-auto', 'font-medium text-amber-600' => $review['late'], 'text-gray-500' => ! $review['late']])>{{ $review['waiting'] }} bekliyor</span>
                    </div>
                    <p class="mt-2 whitespace-pre-line text-sm text-gray-800 dark:text-gray-200">{{ $review['comment'] !== '' ? $review['comment'] : 'Yorum metni yok (yalnız puan).' }}</p>

                    @if ($action !== null)
                        <p @class(['mt-2 text-xs', 'text-rose-600' => $action['status'] === 'failed', 'text-gray-500' => $action['status'] !== 'failed']) @if (in_array($action['status'], ['queued', 'running'], true)) wire:poll.5s @endif>Yanıt: {{ $action['label'] }}@if ($action['status'] === 'failed' && $action['error']) · {{ \Illuminate\Support\Str::limit((string) $action['error'], 200) }}@endif</p>
                    @endif

                    @if ($canWrite && ! $busy)
                        <div class="mt-3">
                            @if ($review['draft'] !== null || array_key_exists('r'.$review['id'], $replies))
                                <textarea wire:model="replies.r{{ $review['id'] }}" rows="3" maxlength="4000" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="Yanıt"></textarea>
                                <div class="mt-1 flex flex-wrap gap-3 text-xs font-medium">
                                    <button type="button" wire:click="send({{ $review['id'] }})" wire:confirm="Bu yanıt Google’da yayınlansın mı? (Geri alınabilir.)" class="text-success-600 hover:underline">Yanıtı gönder</button>
                                </div>
                            @elseif ($review['draft_state'] === 'running')
                                <p class="text-xs text-gray-500">Taslak yazılıyor…</p>
                            @else
                                @if ($failedDraft)<p class="text-xs text-rose-600">{{ \Illuminate\Support\Str::after((string) $review['draft_state'], 'failed: ') }}</p>@endif
                                <div class="flex flex-wrap gap-3 text-xs font-medium">
                                    <button type="button" wire:click="$set('replies.r{{ $review['id'] }}', '')" class="text-brand-600 hover:underline">Kendim yazayım</button>
                                </div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <p class="rounded-xl bg-white px-4 py-5 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">{{ $rating !== '' ? 'Bu puanda yanıtsız yorum yok.' : 'Yanıtsız yorum yok.' }}</p>
            @endforelse
        </section>
    @else
        <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
            @forelse ($groups as $brandName => $locations)
                <div class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.03]">{{ $brandName }} · {{ $locations->count() }}</div>
                @foreach ($locations as $location)
                    @php
                        $s = $stats[$location->id] ?? null;
                        $kit = $kits[$location->id] ?? ['link' => null, 'qr' => null];
                        $text = $kit['link'] ? \App\Services\Gbp\Desk\ReviewDesk::requestMessage((string) ($location->brand?->name ?: $names[$location->id]), $kit['link']) : null;
                    @endphp
                    <div wire:key="kit-{{ $location->id }}" class="flex flex-wrap gap-4 px-4 py-4" x-data="{ copied: '' }">
                        <div class="h-28 w-28 shrink-0 rounded-lg bg-white p-1 ring-1 ring-inset ring-gray-200 [&_svg]:h-full [&_svg]:w-full">
                            @if ($kit['qr']){!! $kit['qr'] !!}@else<span class="flex h-full items-center justify-center text-center text-[11px] text-gray-400">QR yok</span>@endif
                        </div>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ $names[$location->id] }}</span>
                                @if ($s)
                                    <span class="text-xs text-gray-500">90 günde {{ $s['recent'] }} yorum @if ($s['average'] !== null)· ort. {{ number_format($s['average'], 1, ',', '') }} ★@endif @if ($s['reply_rate'] !== null)· yanıt oranı %{{ $s['reply_rate'] }}@endif</span>
                                    @if ($s['recent'] < 3)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Yorum az geliyor</span>@endif
                                @endif
                            </div>
                            @if ($kit['link'])
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <input type="text" readonly value="{{ $kit['link'] }}" class="min-w-0 flex-1 rounded-lg border-gray-300 bg-gray-50 py-1 text-xs dark:border-gray-700 dark:bg-gray-900">
                                    <button type="button" x-on:click="navigator.clipboard.writeText(@js($kit['link'])); copied = 'link'" class="font-medium text-brand-600 hover:underline"><span x-text="copied === 'link' ? 'Kopyalandı' : 'Bağlantıyı kopyala'"></span></button>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-2 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                                    <p>{{ $text }}</p>
                                    <div class="mt-1 flex flex-wrap gap-3 font-medium">
                                        <button type="button" x-on:click="navigator.clipboard.writeText(@js($text)); copied = 'text'" class="text-brand-600 hover:underline"><span x-text="copied === 'text' ? 'Kopyalandı' : 'Mesajı kopyala'"></span></button>
                                        <a href="https://wa.me/?text={{ rawurlencode((string) $text) }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">WhatsApp’ta aç ↗</a>
                                        <a href="{{ route('operator.gbp-review-card', ['assetId' => $location->id]) }}" target="_blank" class="text-brand-600 hover:underline">Yazdırılabilir kart ↗</a>
                                    </div>
                                </div>
                            @else
                                <p class="text-xs text-gray-500">Google yer kimliği (place id) henüz toplanmadı; profil verisi bir sonraki toplamada gelince yorum bağlantısı burada görünür.</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            @empty
                <p class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok.</p>
            @endforelse
        </section>
    @endif
</div>
