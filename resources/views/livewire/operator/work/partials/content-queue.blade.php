{{-- Web site SEO içerikler: the operator's steps across every site, each grouped by site. --}}
@php
    $btn = 'h-8 shrink-0 rounded-lg bg-brand-500 px-3 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'h-8 shrink-0 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
    $chip = 'rounded px-1.5 py-0.5 text-[10px] font-semibold';
    $stepLabels = ['yazilacak' => 'Onay bekleyen başlıklar', 'okunacak' => 'Okunacak yazılar', 'gonderildi' => 'Gönderildi (30 gün)'];
@endphp
<section class="space-y-4" data-content-queue>
    <div>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">İçerik fikirleri</h2>
        <p class="mt-0.5 text-xs text-gray-500">Onayla: Claude yazar. Oku: yazıyı okursun. Gönder: WordPress'e taslak gider (geri alınabilir). Bir siteye tek bakmak için sitenin İçerik sekmesi.</p>
    </div>

    <div role="tablist" class="grid grid-cols-3 gap-2 sm:max-w-2xl">
        @foreach ($stepLabels as $code => $label)
            <button type="button" role="tab" wire:click="setStep('{{ $code }}')" aria-selected="{{ $step === $code ? 'true' : 'false' }}"
                    @class(['rounded-xl bg-white px-3 py-2.5 text-left ring-inset dark:bg-gray-900', 'ring-2 ring-gray-900 dark:ring-white' => $step === $code, 'ring-1 ring-gray-200 dark:ring-gray-800' => $step !== $code])
                    data-step="{{ $code }}">
                <span class="block text-xs text-gray-500">{{ $label }}</span>
                <span @class(['block text-xl font-semibold tabular-nums', 'text-amber-700 dark:text-amber-300' => $code === 'okunacak' && $queue['counts'][$code] > 0, 'text-emerald-700 dark:text-emerald-400' => $code === 'gonderildi' && $queue['counts'][$code] > 0, 'text-gray-900 dark:text-white' => $code === 'yazilacak'])>{{ $queue['counts'][$code] }}</span>
            </button>
        @endforeach
    </div>

    @forelse ($queue['groups'][$step] as $group)
        @php $site = $group['site']; @endphp
        <article wire:key="queue-{{ $step }}-{{ $site->id }}" class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-queue-site="{{ $site->id }}">
            <header class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-800">
                <div class="min-w-0">
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $group['brand'] ?? '—' }}</span>
                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $site->domain ?: $site->name }} <span class="font-normal text-gray-500">· {{ $group['total'] }}</span></h3>
                </div>
                <div class="flex flex-wrap gap-1" data-site-languages>
                    @foreach ($group['languages'] as $code => $pages)
                        <span class="{{ $chip }} {{ $loop->first ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' }}" title="{{ ($langs[$code] ?? $code).($pages > 0 ? ' · '.$pages.' sayfa' : '') }}">{{ strtoupper($code) }}</span>
                    @endforeach
                </div>
                <div class="ml-auto flex items-center gap-2 text-xs">
                    @if ($step === 'yazilacak' && $group['waiting'] > 1)
                        <button type="button" wire:click="writeAll({{ $site->id }})" wire:confirm="{{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }} başlık onaylanıp yazdırılsın mı?" class="{{ $btn }}" data-write-all="{{ $site->id }}">Hepsini onayla ve yazdır ({{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }})</button>
                    @endif
                    <a href="{{ $group['url'] }}" wire:navigate class="font-medium text-brand-600 hover:underline">Site →</a>
                </div>
            </header>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($group['items'] as $item)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5" wire:key="queue-item-{{ $item['id'] }}" data-content-item="{{ $item['id'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if ($step === 'yazilacak' && $item['score'] > 0)<span class="{{ $chip }} tabular-nums {{ $item['score'] >= 70 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($item['score'] >= 45 ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300') }}" title="Öncelik puanı: {{ $item['score_line'] }}" data-score="{{ $item['score'] }}">Puan {{ $item['score'] }}</span>@endif
                                @if ($item['rank'] <= 1 && $step === 'yazilacak')<span class="{{ $chip }} bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Acil</span>@endif
                                <span class="{{ $chip }} {{ $item['kind'] === 'Güncelleme' ? 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ $item['kind'] }}</span>
                                <span class="{{ $chip }} bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ strtoupper($item['language']) }}@foreach ($item['translations'] as $t) + {{ strtoupper($t) }}@endforeach</span>
                                @if ($item['blocked'])<span class="{{ $chip }} bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Kurala takıldı</span>@endif
                                @if ($item['writing'])<span class="{{ $chip }} bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300" data-writing>{{ $step === 'yazilacak' ? 'yazılıyor…' : 'çeviri yazılıyor…' }}</span>@endif
                            </div>
                            <p class="mt-1 font-medium leading-snug text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                            @if ($item['line'] && ! $item['writing'])<p class="mt-0.5 text-xs text-rose-600">Son deneme: {{ $item['line'] }}</p>@endif
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            @if ($step === 'yazilacak')
                                @if (! $item['writing'])
                                    @if ($item['can_pick_language'])
                                        <select wire:model="languages.{{ $item['id'] }}" aria-label="Dil" class="h-8 rounded-lg border-gray-300 py-0 text-xs dark:border-gray-700 dark:bg-gray-950">
                                            @foreach (array_keys($group['languages']) as $codeLang)<option value="{{ $codeLang }}" @selected($codeLang === $item['language'])>{{ $langs[$codeLang] ?? strtoupper($codeLang) }}</option>@endforeach
                                        </select>
                                    @endif
                                    <button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $btn }}" data-write="{{ $item['id'] }}">{{ $item['approved'] ? 'Yeniden yaz' : 'Onayla ve yazdır' }}</button>
                                    @if (! $item['approved'])<button type="button" wire:click="dismiss({{ $item['id'] }})" class="{{ $ghost }}">Reddet</button>@endif
                                @endif
                            @elseif ($step === 'okunacak')
                                @if ($item['blocked'])<button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $ghost }}" data-rewrite="{{ $item['id'] }}">Yeniden yaz</button>@endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $btn }}" data-read="{{ $item['id'] }}">Oku</button>
                            @else
                                <span class="text-emerald-700 dark:text-emerald-400">Taslak gönderildi{{ $item['sent_at'] ? ' · '.$item['sent_at']->timezone('Europe/Istanbul')->format('d.m') : '' }}</span>
                                @if ($item['unsent'] !== [])
                                    <button type="button" wire:click="sendContent({{ $item['id'] }})" wire:confirm="Yeni dil WordPress'e taslak olarak gönderilsin mi?" class="{{ $btn }}">{{ implode(', ', array_map('strtoupper', $item['unsent'])) }} gönder</button>
                                @endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $ghost }}">Oku</button>
                            @endif
                        </div>
                    </li>
                @endforeach
                @if ($group['total'] > count($group['items']))
                    <li class="px-4 py-2 text-center text-xs text-gray-500">+{{ $group['total'] - count($group['items']) }} daha · <a href="{{ $group['url'] }}" wire:navigate class="text-brand-600 hover:underline">sitenin İçerik sekmesinde</a></li>
                @endif
            </ul>
        </article>
    @empty
        <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">{{ $step === 'yazilacak' ? 'Onay bekleyen başlık yok.' : ($step === 'okunacak' ? 'Okunacak yazı yok.' : 'Son 30 günde gönderilen yazı yok.') }}</p>
    @endforelse
</section>

@include('livewire.operator.work.partials.content-reader')
