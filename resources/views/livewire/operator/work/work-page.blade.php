<div class="space-y-5 dark:text-gray-200" data-work-desk>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Genel işler</h1>
            <p class="mt-1 text-xs text-gray-500">Bütün markaların açık işleri, en acili üstte. Onayla, yaptım de ya da reddet. Sistemin kapattığı işlerde (kurulum, çakışma) kendi düğmesini kullan; İşletme Profili yazmaları "Aç" ile varlık ekranında yapılır.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="brand" aria-label="Marka" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                <option value="">Tüm markalar</option>
                @foreach($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
            </select>
            @include('livewire.operator.work.partials.push-toggle', ['devices' => $pushDevices])
        </div>
    </header>

    @if($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif

    <nav class="flex gap-5 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Sekmeler" role="tablist">
        @foreach($tabs as $code => $label)
            <button type="button" role="tab" wire:click="setTab('{{ $code }}')" @class(['-mb-px h-11 shrink-0 border-b-2 whitespace-nowrap', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $tab === $code, 'border-transparent text-gray-500 hover:text-gray-800' => $tab !== $code]) aria-selected="{{ $tab === $code ? 'true' : 'false' }}">
                {{ $label }}
                @if($urgent[$code] > 0)<span class="ml-1 rounded-full bg-rose-100 px-1.5 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" title="Acil">{{ $urgent[$code] }}</span>@endif
                @if($counts[$code] > 0)<span class="ml-1 rounded-full bg-gray-100 px-1.5 text-xs text-gray-600 dark:bg-gray-800">{{ $counts[$code] }}</span>@endif
            </button>
        @endforeach
    </nav>

    <div class="flex items-center gap-2 text-xs">
        <button type="button" wire:click="setView('acik')" @class(['rounded-full px-3 py-1 ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $view === 'acik', 'ring-gray-300 dark:ring-gray-700' => $view !== 'acik'])>Açık</button>
        <button type="button" wire:click="setView('yapildi')" @class(['rounded-full px-3 py-1 ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $view === 'yapildi', 'ring-gray-300 dark:ring-gray-700' => $view !== 'yapildi'])>Yapıldı (30 gün)</button>
        <span class="text-gray-400">{{ $total }} iş</span>
    </div>

    <div class="space-y-2">
        @forelse($rows as $row)
            <article wire:key="{{ $row['kind'] }}-{{ $row['id'] }}" class="rounded-xl bg-white p-4 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-work-row="{{ $row['kind'] }}-{{ $row['id'] }}">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    @if($row['rank'] <= 1)
                        <span class="rounded-full bg-rose-50 px-2 py-0.5 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Acil</span>
                    @elseif($row['rank'] >= 3)
                        <span class="rounded-full bg-gray-50 px-2 py-0.5 text-gray-500 dark:bg-gray-800">Düşük</span>
                    @endif
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800">{{ $row['type'] }}</span>
                    @if($row['stage'])<span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $row['stage'] }}</span>@endif
                    @if($row['verification'] === 'pending')<span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300" data-verification="pending">Sistem kontrol edecek</span>
                    @elseif($row['verification'] === 'confirmed')<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" data-verification="confirmed">Sistem doğruladı</span>
                    @elseif($row['verification'] === 'still_seen')<span class="rounded-full bg-rose-50 px-2 py-0.5 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" data-verification="still_seen">Sistem hâlâ görüyor</span>
                    @elseif($row['verification'] === 'auto')<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" data-verification="auto">Sistem kendisi fark etti</span>
                    @endif
                    <span class="text-gray-500">{{ $row['brand'] ?? '—' }}@if($row['asset']) · {{ $row['asset'] }}@endif</span>
                </div>
                <h2 class="mt-1.5 font-semibold text-gray-900 dark:text-white">{{ $row['title'] }}</h2>
                @if($row['reason'] !== '')<p class="mt-0.5 text-gray-600 dark:text-gray-400">{{ $row['reason'] }}</p>@endif
                <p class="mt-1 text-xs text-gray-400">{{ $row['who'] }}@if($row['applied_at']) · yapıldı {{ $row['applied_at']->timezone('Europe/Istanbul')->format('d.m H:i') }}@endif</p>
                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    @if($row['kind'] === 'suggestion' && ($row['can_done'] || $row['can_approve'] || $row['actions'] !== []))
                        <input type="text" wire:model="notes.{{ $row['id'] }}" placeholder="Not (isteğe bağlı)" aria-label="Not" class="min-w-40 flex-1 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                    @endif
                    @if($row['can_approve'])
                        <button type="button" wire:click="approve({{ $row['id'] }})" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white">Onayla</button>
                    @endif
                    @foreach($row['actions'] as $do)
                        <button type="button" wire:click="run({{ $row['id'] }}, '{{ $do }}')" @if(in_array($do, ['merge', 'send_draft', 'audit_fix'], true)) wire:confirm="{{ \App\Services\Work\WorkDesk::ACTIONS[$do] }}: emin misiniz?" @endif @class(['rounded-lg px-3 py-1.5 text-xs font-semibold', 'bg-brand-500 text-white' => $loop->first, 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => ! $loop->first]) data-work-action="{{ $do }}">{{ \App\Services\Work\WorkDesk::ACTIONS[$do] }}</button>
                    @endforeach
                    @if($row['can_done'])
                        <button type="button" wire:click="done({{ $row['id'] }})" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Yaptım</button>
                    @endif
                    @if($row['can_reopen'])
                        <button type="button" wire:click="reopen({{ $row['id'] }})" wire:confirm="İş yeniden açılsın mı?" class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Geri al</button>
                    @endif
                    @if($view === 'acik')
                        <button type="button" wire:click="snooze('{{ $row['kind'] }}', {{ $row['id'] }})" class="rounded-lg px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-300 dark:ring-gray-700">7 gün ertele</button>
                        @if($row['kind'] === 'suggestion')
                            <button type="button" wire:click="dismiss({{ $row['id'] }})" class="rounded-lg px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Reddet</button>
                        @endif
                    @endif
                    @if($row['url'])
                        <a href="{{ $row['url'] }}" @if($row['external']) target="_blank" rel="noopener" @else wire:navigate @endif class="ml-auto text-xs font-semibold text-brand-600 hover:underline">{{ $row['url_label'] }}</a>
                    @endif
                </div>
            </article>
        @empty
            <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">{{ $view === 'acik' ? 'Bu sekmede açık iş yok.' : 'Son 30 günde yapılan iş yok.' }}</p>
        @endforelse
        @if($total > $rows->count())
            <button type="button" wire:click="more" class="w-full rounded-lg py-2 text-xs font-semibold text-gray-600 ring-1 ring-inset ring-gray-200 dark:ring-gray-800">Daha fazla göster ({{ $total - $rows->count() }})</button>
        @endif
    </div>
</div>
