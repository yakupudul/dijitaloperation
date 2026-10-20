@props(['insight', 'compact' => false])
@php
    $production = $insight['production'];
    $content = $production?->content ?? [];
    $running = $insight['state'] === 'running';
    $failed = is_string($insight['state']) && str_starts_with($insight['state'], 'failed');
@endphp
<div {{ $attributes->class(['min-w-0']) }} @if ($running) wire:poll.5s @endif>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="section-title">{{ $insight['label'] }}</p>
        <button type="button" wire:click="runInsight('{{ $insight['kind'] }}', {{ $insight['subject_id'] }})" wire:loading.attr="disabled" @disabled($running)
            class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:text-white dark:ring-gray-600 dark:hover:bg-white/5">
            {{ $running ? 'AI çalışıyor…' : ($production ? 'Yeniden hazırla' : 'AI ile hazırla') }}@if ($insight['estimate'] && ! $running) <span class="font-normal opacity-80">({{ $insight['estimate'] }})</span>@endif
        </button>
    </div>
    @if ($failed)
        <p class="mt-2 text-xs text-error-700 dark:text-error-300">{{ \Illuminate\Support\Str::after($insight['state'], 'failed: ') }}</p>
    @endif
    @if ($production)
        @if (filled($content['summary'] ?? null))
            <p class="mt-3 text-sm text-gray-800 dark:text-gray-200">{{ $content['summary'] }}</p>
        @endif
        @if (! empty($content['items']))
            <ul class="mt-3 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($content['items'] as $item)
                    @php [$tagLabel, $tagClass] = $insight['tags'][$item['tag']] ?? [$item['tag'], '']; @endphp
                    <li class="py-2.5">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 shrink-0 text-xs text-gray-500">{{ $tagLabel }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                                @if (filled($item['detail']))<p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $item['detail'] }}</p>@endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="mt-2 text-xs text-gray-500" title="Üretim arşivinde saklanır">AI önerisidir, kontrol ederek kullan. {{ $production->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
    @elseif (! $running && ! $compact)
        <p class="mt-2 text-xs text-gray-500">Butona basınca AI mevcut verilerle hazırlar; kendiliğinden çalışmaz.</p>
    @endif
</div>
