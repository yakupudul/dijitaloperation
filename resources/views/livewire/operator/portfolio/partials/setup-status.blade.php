{{-- Kurulum durumu: per channel the steps from integration to daily use, with a one-click action for the next step. --}}
@php
    $stateMark = ['done' => ['✓', 'text-success-600'], 'next' => ['•', 'text-gray-900 dark:text-white'], 'waiting' => ['○', 'text-gray-300 dark:text-gray-600'], 'optional' => ['○', 'text-gray-400']];
    $stateText = ['done' => 'Tamam', 'next' => 'Sıradaki adım', 'waiting' => 'Önce önceki adım', 'optional' => 'İsteğe bağlı'];
@endphp
<section data-setup-status aria-labelledby="brand-setup-status-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="brand-setup-status-heading" class="section-title" title="Her kanalda sıradaki adımın düğmesine basın; bağlanan her hesabın verisi birkaç dakika içinde kendiliğinden çekilmeye başlar.">Kurulum durumu <span class="text-sm font-normal text-gray-500">{{ $setup['done'] }}/{{ $setup['total'] }} kanal hazır</span></h2>
        </div>
        <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Otomatik kur</a>
    </div>
    <div class="mt-2 grid gap-x-8 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($setup['channels'] as $channel)
            <div class="border-t border-gray-200 py-4 dark:border-gray-800" data-setup-channel="{{ $channel['key'] }}">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $channel['label'] }}</h3>
                    @if ($channel['complete'])
                        <x-ta.pill tone="ok">Hazır</x-ta.pill>
                    @elseif ($channel['unused'])
                        <x-ta.pill tone="info">Kullanılmıyor</x-ta.pill>
                    @else
                        <x-ta.pill tone="look">{{ $channel['done'] }}/{{ $channel['total'] }}</x-ta.pill>
                    @endif
                </div>
                @if ($channel['unused'])
                    <p class="mt-2 text-xs text-gray-500">Markanın {{ $channel['label'] }} hesabı yoksa bu adımları atlayın. Varsa aşağıdaki adımlarla bağlayın.</p>
                @endif
                <ol class="mt-3 space-y-2">
                    @foreach ($channel['steps'] as $step)
                        <li class="flex items-start gap-2" data-setup-step="{{ $channel['key'] }}:{{ $step['key'] }}" data-state="{{ $step['state'] }}">
                            <span class="mt-0.5 w-4 shrink-0 text-center text-sm {{ $stateMark[$step['state']][1] }}" title="{{ $stateText[$step['state']] }}">{{ $stateMark[$step['state']][0] }}</span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block text-sm', 'font-semibold text-gray-900 dark:text-white' => $step['state'] === 'next', 'text-gray-800 dark:text-white/90' => $step['state'] !== 'next'])>{{ $step['label'] }}@if ($step['optional']) <span class="text-xs font-normal text-gray-500">(isteğe bağlı)</span>@endif</span>
                                <span class="block text-xs text-gray-500">{{ $step['detail'] }}</span>
                                @if ($step['action'] !== null && (in_array($step['state'], ['next', 'optional'], true) || ($step['always'] ?? false)) && ($isAdmin || ($step['action']['method'] ?? null) !== 'setupDiscover'))
                                    @if ($step['action']['kind'] === 'link')
                                        <a href="{{ $step['action']['url'] }}" @if (! str_contains($step['action']['url'], '#')) wire:navigate @endif @class(['mt-1 inline-flex rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset', 'text-gray-900 ring-gray-400 hover:bg-gray-50 dark:text-white dark:ring-gray-500 dark:hover:bg-white/5' => $step['state'] === 'next', 'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5' => $step['state'] !== 'next'])>{{ $step['action']['label'] }}</a>
                                    @else
                                        <button type="button" wire:click="{{ $step['action']['method'] }}({{ $step['action']['arg'] !== null ? \Illuminate\Support\Js::from($step['action']['arg']) : '' }})" wire:loading.attr="disabled" @class(['mt-1 inline-flex rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset', 'text-gray-900 ring-gray-400 hover:bg-gray-50 dark:text-white dark:ring-gray-500 dark:hover:bg-white/5' => $step['state'] === 'next', 'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5' => $step['state'] !== 'next'])>{{ $step['action']['label'] }}</button>
                                    @endif
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
                @if ($channel['complete'] && $channel['daily'] !== null)
                    <a href="{{ $channel['daily']['url'] }}" wire:navigate class="link-neutral mt-3 inline-block text-xs">Günlük kullanım: {{ $channel['daily']['label'] }}</a>
                @endif
            </div>
        @endforeach
    </div>
</section>
