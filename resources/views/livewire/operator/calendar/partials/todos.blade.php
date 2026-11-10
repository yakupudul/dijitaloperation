{{-- Yapılacaklar (yakup 2026-11-10): one box for everything, then today, the late ones, the ones without a date, notes. --}}
@php
    $host = fn (?string $url): ?string => $url ? preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) : null;
@endphp
<aside class="card p-4" data-calendar-todos>
    <div class="flex items-center justify-between gap-2">
        <h2 class="section-title">Yapılacaklar @if ($board && $board['count'] > 0)<span class="section-count">{{ $board['count'] }}</span>@endif</h2>
        <button type="button" wire:click="showExtension" class="link-neutral text-xs" title="Tarayıcıda hangi sayfada olursan ol + ikonuyla not al">+ eklentisi</button>
    </div>

    <form wire:submit="quickAdd" class="mt-3" x-data x-init="@if ($focusQuick) $nextTick(() => $refs.q.focus()) @endif">
        <textarea x-ref="q" wire:model="quick" rows="2" data-calendar-quick
            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.quickAdd() }"
            placeholder="Yaz, Enter'a bas. Örnek: yarın 10'da Şaban Bey'i ara · not: …"
            class="w-full resize-none rounded-lg border border-gray-200 bg-white px-3 py-2 text-[13px] placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white"></textarea>
        @error('quick')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
        <p class="mt-1 text-[11.5px] leading-snug text-gray-500" title="Tarih ya da saat yazarsan o an telefona hatırlatılır. Yazmazsan tikleyene kadar burada durur. Shift+Enter yeni satır; ilk satır başlık, gerisi not olur.">Tarih yoksa tikleyene kadar burada durur. <span class="text-gray-400">"not:" ile başlarsa not, "toplantı:" ile başlarsa toplantı.</span></p>
    </form>

    @if ($board === null)
        <p class="mt-3 text-[13px] text-error-600">Liste şu an okunamadı; hata kaydedildi.</p>
    @else
        @php
            $groups = [
                ['key' => 'today', 'label' => 'Bugün', 'items' => $board['today']],
                ['key' => 'late', 'label' => 'Geciken', 'items' => $board['late']],
                ['key' => 'open', 'label' => 'Tarihsiz', 'items' => $board['open']],
            ];
        @endphp
        @foreach ($groups as $group)
            @if ($group['items'] !== [])
                <h3 class="mt-4 text-xs font-medium {{ $group['key'] === 'late' ? 'text-error-600' : 'text-gray-500' }}">{{ $group['label'] }}</h3>
                <ul class="mt-1.5 space-y-1.5">
                    @foreach ($group['items'] as $t)
                        <li class="group flex items-start gap-2 text-[13px]" wire:key="todo-{{ $group['key'] }}-{{ $t['id'] }}" data-calendar-todo>
                            <button type="button" wire:click="toggle({{ $t['id'] }})" class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border {{ $t['done'] ? 'border-success-500 bg-success-50 text-success-600' : 'border-gray-300 hover:border-gray-500 dark:border-gray-600' }}" aria-label="{{ $t['done'] ? 'Yapılmadı yap' : 'Yapıldı' }}">@if ($t['done'])✓@endif</button>
                            <div class="min-w-0 flex-1">
                                <p class="break-words {{ $t['done'] ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-gray-200' }}">{{ $t['time'] ? $t['time'].' · ' : '' }}{{ $t['kind'] === 'meeting' ? 'Toplantı: ' : '' }}{{ $t['title'] }}</p>
                                @php $meta = array_filter([$t['late'], $t['brand'], $t['notes'] ? mb_strimwidth($t['notes'], 0, 80, '…') : null]); @endphp
                                @if ($meta !== [] || $t['url'])
                                    <p class="text-xs text-gray-500">{{ implode(' · ', $meta) }}@if ($t['url']){{ $meta !== [] ? ' · ' : '' }}<a href="{{ $t['url'] }}" target="_blank" rel="noopener" class="link-neutral">{{ $host($t['url']) }} ↗</a>@endif</p>
                                @endif
                            </div>
                            <button type="button" wire:click="remove({{ $t['id'] }})" wire:confirm="Silinsin mi?" class="text-xs text-gray-300 opacity-0 hover:text-error-600 group-hover:opacity-100 focus:opacity-100" aria-label="Sil">Sil</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
        @if ($board['today'] === [] && $board['late'] === [] && $board['open'] === [])
            <p class="mt-4 text-[13px] text-gray-400">Açık iş yok.</p>
        @endif

        <h3 class="mt-5 flex items-center justify-between text-xs font-medium text-gray-500">
            <span>Notlar</span>
            @if (count($board['notes']) >= 6 || $allNotes)
                <button type="button" wire:click="$toggle('allNotes')" class="link-neutral font-normal">{{ $allNotes ? 'Son notlar' : 'Tümü' }}</button>
            @endif
        </h3>
        <ul class="mt-1.5 space-y-2">
            @forelse ($board['notes'] as $n)
                <li x-data="{ o: false }" class="group text-[13px]" wire:key="note-{{ $n['id'] }}" data-calendar-note-item>
                    <div class="flex items-start gap-2">
                        <button type="button" x-on:click="o = ! o" class="min-w-0 flex-1 text-left">
                            <span class="block break-words text-gray-800 dark:text-gray-200">{{ $n['title'] }}</span>
                            <span class="block text-xs text-gray-500">{{ implode(' · ', array_filter([$n['created'], $n['brand']])) }}</span>
                        </button>
                        <button type="button" wire:click="remove({{ $n['id'] }})" wire:confirm="Not silinsin mi?" class="text-xs text-gray-300 opacity-0 hover:text-error-600 group-hover:opacity-100 focus:opacity-100" aria-label="Sil">Sil</button>
                    </div>
                    <div x-show="o" x-cloak class="mt-1 rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-gray-700 dark:bg-white/[0.04] dark:text-gray-300">
                        @if ($n['notes'] && $n['notes'] !== $n['title'])<p class="whitespace-pre-line">{{ $n['notes'] }}</p>@endif
                        @if ($n['url'])<a href="{{ $n['url'] }}" target="_blank" rel="noopener" class="link-neutral">{{ $host($n['url']) }} ↗</a>@endif
                    </div>
                </li>
            @empty
                <li class="text-[13px] text-gray-400">Not yok. "not:" ile başlayan her şey buraya düşer.</li>
            @endforelse
        </ul>
    @endif
</aside>
