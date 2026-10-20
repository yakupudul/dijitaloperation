{{-- One work row inside its card: what, why, and the buttons; the card already says brand, site, type and rule. --}}
@php
    $overlap = $row['overlap'] ?? null;
    // Per-row actions are outline (the screen's one main action is the primary button); the row's first action reads a little stronger.
    $primary = 'h-8 shrink-0 rounded-lg bg-white px-3 text-xs font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-transparent dark:text-white dark:ring-gray-500 dark:hover:bg-white/5';
    $ghost = 'h-8 shrink-0 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5';
    $hasOwnButtons = $row['can_approve'] || $row['actions'] !== [] || $row['can_done'] || $row['can_reopen'];
@endphp
<li class="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-start sm:gap-4" wire:key="{{ $row['kind'] }}-{{ $row['id'] }}" data-work-row="{{ $row['kind'] }}-{{ $row['id'] }}">
    <div class="min-w-0 sm:flex-1">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
            @if($row['rank'] <= 1)<x-ta.pill tone="critical">Acil</x-ta.pill>@endif
            @if($overlap !== null)
                @if($overlap['recommendation'] === \App\Services\Site\ClusterOverlaps::REDIRECT)
                    <x-ta.pill tone="look" data-recommendation="redirect">301 öneriliyor</x-ta.pill>
                @elseif($overlap['recommendation'] === \App\Services\Site\ClusterOverlaps::REVIEW)
                    <x-ta.pill tone="info" data-recommendation="review">Ana sayfayı gözden geçir</x-ta.pill>
                @else
                    <x-ta.pill tone="info" data-recommendation="differentiate">Ayrıştır</x-ta.pill>
                @endif
            @endif
            @if($row['stage'])<x-ta.pill tone="look">{{ $row['stage'] }}</x-ta.pill>@endif
            @if($row['verification'] === 'pending')<x-ta.pill tone="look" data-verification="pending">Sistem kontrol edecek</x-ta.pill>
            @elseif($row['verification'] === 'confirmed')<x-ta.pill tone="ok" data-verification="confirmed">Sistem doğruladı</x-ta.pill>
            @elseif($row['verification'] === 'still_seen')<x-ta.pill tone="critical" data-verification="still_seen">Sistem hâlâ görüyor</x-ta.pill>
            @elseif($row['verification'] === 'auto')<x-ta.pill tone="ok" data-verification="auto">Sistem kendisi fark etti</x-ta.pill>
            @endif
        </div>
        @if($overlap !== null && $overlap['cluster'] !== null)
            <p class="mt-1 break-all font-medium leading-snug text-gray-900 dark:text-white">
                @if($view === 'acik' && in_array('merge', $row['actions'], true))
                    <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" class="mr-1 rounded border-gray-300 align-middle text-brand-600 dark:border-gray-700" aria-label="301 için seç" data-merge-pick="{{ $row['id'] }}">
                @endif
                @if($overlap['url'])<a href="{{ $overlap['url'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $overlap['path'] }}</a>@else{{ $overlap['path'] }}@endif
            </p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $overlap['why'] }}</p>
        @else
            <p class="mt-1 font-medium leading-snug text-gray-900 dark:text-white">{{ $row['title'] }}</p>
            @if($row['reason'] !== '')<p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $row['reason'] }}</p>@endif
        @endif
        @if($group['who'] === null && $overlap === null && $row['who'] !== '')<p class="mt-0.5 text-xs text-gray-500">{{ $row['who'] }}</p>@endif
        @if($row['applied_at'])<p class="mt-0.5 text-xs text-gray-500" data-applied-at>Yapıldı {{ $row['applied_at']->timezone('Europe/Istanbul')->format('d.m H:i') }}</p>@endif
    </div>
    <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
        @if($row['can_approve'])
            <button type="button" wire:click="approve({{ $row['id'] }})" class="{{ $primary }}">Onayla</button>
        @endif
        @foreach($row['actions'] as $do)
            <button type="button" wire:click="run({{ $row['id'] }}, '{{ $do }}')" @if(in_array($do, ['merge', 'make_main', 'send_draft', 'audit_fix'], true)) wire:confirm="{{ \App\Services\Work\WorkDesk::ACTIONS[$do] }}: emin misiniz?" @endif class="{{ $loop->first && ! $row['can_approve'] ? $primary : $ghost }}" data-work-action="{{ $do }}">{{ \App\Services\Work\WorkDesk::ACTIONS[$do] }}</button>
        @endforeach
        @if($row['can_done'])
            <button type="button" wire:click="done({{ $row['id'] }})" class="{{ $primary }}">Yaptım</button>
        @endif
        @if($row['can_reopen'])
            <button type="button" wire:click="reopen({{ $row['id'] }})" wire:confirm="İş yeniden açılsın mı?" class="{{ $ghost }}">Geri al</button>
        @endif
        @if($group['url'] === null && $row['url'])
            <a href="{{ $row['url'] }}" @if($row['external']) target="_blank" rel="noopener" @else wire:navigate @endif class="link-neutral px-1">{{ $row['url_label'] }}</a>
        @endif
        @if($view === 'acik')
            <div class="relative" x-data="{ more: false }" x-on:click.outside="more = false" x-on:keydown.escape.window="more = false">
                <button type="button" x-on:click="more = ! more" class="{{ $ghost }} {{ $hasOwnButtons ? 'px-2' : '' }}" aria-label="Diğer" :aria-expanded="more.toString()" data-row-more>{{ $hasOwnButtons ? '⋯' : ($row['kind'] === 'suggestion' ? 'Ertele / Reddet' : 'Ertele') }}</button>
                <div x-cloak x-show="more" x-transition.opacity class="surface absolute left-0 z-20 mt-1 w-64 space-y-2 p-3 text-left shadow-lg sm:left-auto sm:right-0">
                    @if($row['kind'] === 'suggestion')
                        <input type="text" wire:model="notes.{{ $row['id'] }}" placeholder="Not (isteğe bağlı)" aria-label="Not" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                    @endif
                    <button type="button" wire:click="snooze('{{ $row['kind'] }}', {{ $row['id'] }})" x-on:click="more = false" class="block w-full rounded-lg px-2 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">7 gün ertele</button>
                    @if($row['kind'] === 'suggestion')
                        <button type="button" wire:click="dismiss({{ $row['id'] }})" x-on:click="more = false" class="block w-full rounded-lg px-2 py-1.5 text-left text-error-700 hover:bg-error-50 dark:text-error-300 dark:hover:bg-error-500/10">Reddet</button>
                    @endif
                </div>
            </div>
        @endif
    </div>
</li>
