{{-- One quiet line in the asset page head: "Veri durumu" per source, the refresh feedback and (ad accounts) the pause toggle. --}}
<div class="min-w-0" @if ($polling) wire:poll.30s @endif data-data-status-line>
    @php
        $feedbackLine = $feedback !== ''
            ? '<p class="shrink-0 text-xs '.($feedbackTone === 'success' ? 'text-success-700 dark:text-success-300' : 'text-warning-700 dark:text-warning-300').'" role="status">'.e($feedback).'</p>'
            : '';
    @endphp
    @if (count($statuses) > 0)
        <x-operator.data-status :statuses="$statuses" compact>
            {!! $feedbackLine !!}
            @if ($asset)
                <span class="sm:ml-auto"><x-operator.activity-pause :asset="$asset" /></span>
            @endif
        </x-operator.data-status>
    @elseif ($asset && in_array((string) $asset->type, ['google_ads', 'meta_ads'], true))
        <div class="flex flex-wrap items-center gap-2 text-xs">{!! $feedbackLine !!}<x-operator.activity-pause :asset="$asset" /></div>
    @endif
</div>
