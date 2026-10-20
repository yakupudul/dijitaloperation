{{-- Kurulum durumu: per channel the steps from integration to daily use, with a one-click action for the next step. --}}
@php
    $stateMark = ['done' => ['✓', 'text-success-600 dark:text-success-400'], 'next' => ['•', 'text-gray-900 dark:text-white'], 'waiting' => ['○', 'text-gray-300 dark:text-gray-600'], 'optional' => ['○', 'text-gray-400']];
    $stateText = ['done' => 'Tamam', 'next' => 'Sıradaki adım', 'waiting' => 'Önce önceki adım', 'optional' => 'İsteğe bağlı'];
@endphp
<section data-setup-status aria-labelledby="brand-setup-status-heading">
    <div class="section-head">
        <h2 id="brand-setup-status-heading" class="section-title" title="Her kanalda sıradaki adımın düğmesine basın; bağlanan her hesabın verisi birkaç dakika içinde kendiliğinden çekilmeye başlar.">Kurulum durumu <span class="section-count">{{ $setup['done'] }}/{{ $setup['total'] }} kanal hazır</span></h2>
        <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="ml-auto text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Otomatik kur</a>
    </div>
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($setup['channels'] as $channel)
            <div class="card" data-setup-channel="{{ $channel['key'] }}">
                <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $channel['label'] }}</h3>
                    @if ($channel['complete'])
                        <x-ta.pill tone="ok">Hazır</x-ta.pill>
                    @elseif ($channel['unused'])
                        <x-ta.pill tone="info">Kullanılmıyor</x-ta.pill>
                    @else
                        <x-ta.pill tone="look">{{ $channel['done'] }}/{{ $channel['total'] }}</x-ta.pill>
                    @endif
                </div>
                <div class="px-4 py-3.5">
                    @if ($channel['unused'])
                        <p class="mb-3 text-[13px] text-gray-500">Markanın {{ $channel['label'] }} hesabı yoksa bu adımları atlayın. Varsa aşağıdaki adımlarla bağlayın.</p>
                    @endif
                    <ol class="space-y-2.5">
                        @foreach ($channel['steps'] as $step)
                            <li class="flex items-start gap-2" data-setup-step="{{ $channel['key'] }}:{{ $step['key'] }}" data-state="{{ $step['state'] }}">
                                <span class="mt-px w-4 shrink-0 text-center text-sm {{ $stateMark[$step['state']][1] }}" title="{{ $stateText[$step['state']] }}">{{ $stateMark[$step['state']][0] }}</span>
                                <span class="min-w-0 flex-1">
                                    <span @class(['block text-sm', 'font-medium text-gray-900 dark:text-white' => $step['state'] === 'next', 'text-gray-700 dark:text-gray-300' => $step['state'] !== 'next'])>{{ $step['label'] }}@if ($step['optional']) <span class="text-xs font-normal text-gray-500">(isteğe bağlı)</span>@endif</span>
                                    <span class="mt-0.5 block text-xs text-gray-500">{{ $step['detail'] }}</span>
                                    @if ($step['action'] !== null && (in_array($step['state'], ['next', 'optional'], true) || ($step['always'] ?? false)) && ($isAdmin || ($step['action']['method'] ?? null) !== 'setupDiscover'))
                                        @if ($step['action']['kind'] === 'link')
                                            <a href="{{ $step['action']['url'] }}" @if (! str_contains($step['action']['url'], '#')) wire:navigate @endif class="btn mt-2 h-7! px-2.5! text-xs!">{{ $step['action']['label'] }}</a>
                                        @else
                                            <button type="button" wire:click="{{ $step['action']['method'] }}({{ $step['action']['arg'] !== null ? \Illuminate\Support\Js::from($step['action']['arg']) : '' }})" wire:loading.attr="disabled" class="btn mt-2 h-7! px-2.5! text-xs!">{{ $step['action']['label'] }}</button>
                                        @endif
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>
                @if ($channel['complete'] && $channel['daily'] !== null)
                    <a href="{{ $channel['daily']['url'] }}" wire:navigate class="block border-t border-gray-200 px-4 py-2.5 text-[13px] font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.02]">Günlük kullanım: {{ $channel['daily']['label'] }}</a>
                @endif
            </div>
        @endforeach
    </div>
</section>
