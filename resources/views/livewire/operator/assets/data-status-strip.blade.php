<div @if ($polling) wire:poll.30s @endif>
    <x-operator.data-status :statuses="$statuses">
        @if ($feedback !== '')
            <p @class([
                'shrink-0 text-xs',
                'text-emerald-700 dark:text-emerald-300' => $feedbackTone === 'success',
                'text-amber-700 dark:text-amber-300' => $feedbackTone !== 'success',
            ]) role="status">{{ $feedback }}</p>
        @endif
    </x-operator.data-status>
    @if ($asset)
        <div class="mt-2 flex justify-end"><x-operator.activity-pause :asset="$asset" /></div>
    @endif
</div>
