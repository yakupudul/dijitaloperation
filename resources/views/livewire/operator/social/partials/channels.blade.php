{{-- One small mark per channel of the post; the color is that channel's result. --}}
@php
    $short = ['instagram' => 'IG', 'facebook' => 'FB', 'linkedin' => 'in', 'threads' => 'Th', 'linkedin_me' => 'in·K', 'youtube' => 'YT'];
@endphp
<span class="flex shrink-0 flex-wrap justify-end gap-1" data-social-row-channels>
    @foreach ($post->targets as $target)
        @php
            $tone = match ($target->status) {
                'published', 'done' => 'border-transparent tone-ok',
                'failed' => 'border-transparent tone-critical',
                'manual' => 'border-transparent tone-look',
                'skipped' => 'border-gray-200 text-gray-300 line-through dark:border-gray-800 dark:text-gray-600',
                default => 'border-gray-200 text-gray-500 dark:border-gray-700 dark:text-gray-400',
            };
        @endphp
        <span class="inline-flex h-[22px] min-w-[26px] items-center justify-center rounded-md border px-1 text-[11px] font-semibold {{ $tone }}" title="{{ $target->account?->platformLabel() }}: {{ $target->statusLabel() }}{{ $target->error ? ' ('.$target->error.')' : '' }}">{{ $short[$target->account?->platform] ?? '?' }}</span>
    @endforeach
</span>
