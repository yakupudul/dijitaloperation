@props(['decision', 'showChannel' => false])
{{-- One AI decision: what to do (title) · why (one sentence with a number) · buttons. Kanıt behind a toggle. --}}
@php
    $d = $decision;
    $btn = 'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium';
    $secondary = $btn.' text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
@endphp
<article {{ $attributes->merge(['class' => 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800']) }} data-decision-card="{{ $d['id'] }}" wire:key="decision-{{ $d['id'] }}">
    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">
        @if ($showChannel)<span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-500 dark:bg-gray-800">{{ $d['channel_label'] }}</span>@endif{{ $d['title'] }}
    </h3>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" data-decision-why>{{ $d['why'] }}</p>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        @if ($d['action'])
            @if ($d['action']['kind'] === 'link' && $d['action']['url'])
                <a href="{{ $d['action']['url'] }}" wire:navigate class="{{ $btn }} bg-brand-500 text-white hover:bg-brand-600" data-decision-action>{{ $d['action']['label'] }}</a>
            @elseif ($d['action']['kind'] === 'run')
                <button type="button" wire:click="runDecisionAction({{ $d['id'] }})" wire:loading.attr="disabled" class="{{ $btn }} bg-brand-500 text-white hover:bg-brand-600" data-decision-action>{{ $d['action']['label'] }}</button>
            @endif
        @endif
        <button type="button" wire:click="markDecisionDone({{ $d['id'] }})" class="{{ $secondary }}">Yapıldı</button>
        <button type="button" wire:click="snoozeDecision({{ $d['id'] }}, 7)" class="{{ $secondary }}">Ertele</button>
        <button type="button" wire:click="dismissDecision({{ $d['id'] }})" class="{{ $secondary }}">Gereksiz</button>
    </div>
    @if ($d['evidence']['rows'] !== [])
        <x-workspace.evidence-table class="mt-2" :columns="$d['evidence']['columns']" :rows="$d['evidence']['rows']" />
    @endif
</article>
