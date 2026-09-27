{{-- Kurulum durumu: per channel the steps from integration to daily use, with a one-click action for the next step. --}}
@php
    $stateMark = ['done' => ['✓', 'text-success-600'], 'next' => ['→', 'text-brand-600'], 'waiting' => ['○', 'text-gray-300 dark:text-gray-600'], 'optional' => ['○', 'text-gray-400']];
    $stateText = ['done' => 'Tamam', 'next' => 'Sıradaki adım', 'waiting' => 'Önce önceki adım', 'optional' => 'İsteğe bağlı'];
@endphp
<section class="{{ $card }} p-5" data-setup-status aria-labelledby="brand-setup-status-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="brand-setup-status-heading" class="text-base font-semibold text-gray-800 dark:text-white/90">Kurulum durumu · {{ $setup['done'] }}/{{ $setup['total'] }} kanal hazır</h2>
            <p class="mt-1 text-sm text-gray-500">Her kanalda sıradaki adımın düğmesine basın; bağlanan her hesabın verisi birkaç dakika içinde kendiliğinden çekilmeye başlar.</p>
        </div>
        <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Otomatik kur</a>
    </div>
    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        @foreach ($setup['channels'] as $channel)
            <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]" data-setup-channel="{{ $channel['key'] }}">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $channel['label'] }}</h3>
                    @if ($channel['complete'])
                        <span class="rounded-full bg-success-50 px-2 py-0.5 text-xs text-success-700 dark:bg-success-500/10 dark:text-success-400">Hazır</span>
                    @elseif ($channel['unused'])
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800">Kullanılmıyor</span>
                    @else
                        <span class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $channel['done'] }}/{{ $channel['total'] }}</span>
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
                                <span @class(['block text-sm', 'font-semibold text-gray-900 dark:text-white' => $step['state'] === 'next', 'text-gray-800 dark:text-white/90' => $step['state'] !== 'next'])>{{ $step['label'] }}@if ($step['optional']) <span class="text-xs font-normal text-gray-400">(isteğe bağlı)</span>@endif</span>
                                <span class="block text-xs text-gray-500">{{ $step['detail'] }}</span>
                                @if ($step['action'] !== null && (in_array($step['state'], ['next', 'optional'], true) || ($step['always'] ?? false)) && ($isAdmin || ($step['action']['method'] ?? null) !== 'setupDiscover'))
                                    @if ($step['action']['kind'] === 'link')
                                        <a href="{{ $step['action']['url'] }}" @if (! str_contains($step['action']['url'], '#')) wire:navigate @endif @class(['mt-1 inline-flex rounded-md px-2.5 py-1 text-xs font-medium', 'bg-brand-500 text-white hover:bg-brand-600' => $step['state'] === 'next', 'text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30' => $step['state'] !== 'next'])>{{ $step['action']['label'] }}</a>
                                    @else
                                        <button type="button" wire:click="{{ $step['action']['method'] }}({{ $step['action']['arg'] !== null ? \Illuminate\Support\Js::from($step['action']['arg']) : '' }})" wire:loading.attr="disabled" @class(['mt-1 inline-flex rounded-md px-2.5 py-1 text-xs font-medium', 'bg-brand-500 text-white hover:bg-brand-600' => $step['state'] === 'next', 'text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30' => $step['state'] !== 'next'])>{{ $step['action']['label'] }}</button>
                                    @endif
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
                @if ($channel['complete'] && $channel['daily'] !== null)
                    <a href="{{ $channel['daily']['url'] }}" wire:navigate class="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline">Günlük kullanım: {{ $channel['daily']['label'] }} →</a>
                @endif
            </div>
        @endforeach
    </div>
</section>
