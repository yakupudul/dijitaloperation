@php $w = 'data_status.open_work.'; @endphp
<section {{ $attributes->class('rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700') }} data-open-work>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __($w.'title', [], 'tr') }}</h2>
            <p class="mt-1 text-xs text-gray-400">{{ __($w.'hint', [], 'tr') }}</p>
        </div>
        <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $total() }}</p>
    </div>
    @if ($total() > 0)
        <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($counts as $kind => $count)
                @if ($count > 0)
                    <li class="flex justify-between"><span>{{ __($w.$kind, [], 'tr') }}</span><span class="font-semibold tabular-nums">{{ $count }}</span></li>
                @endif
            @endforeach
        </ul>
    @else
        <p class="mt-3 text-sm text-gray-500">{{ __($w.'none', [], 'tr') }}</p>
    @endif
    <a href="{{ $url }}" wire:navigate class="mt-3 inline-flex text-sm font-medium text-brand-600 hover:underline">{{ __($w.'open', [], 'tr') }} →</a>
</section>
