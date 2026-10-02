@props(['insight', 'compact' => false])
@php
    $production = $insight['production'];
    $content = $production?->content ?? [];
    $running = $insight['state'] === 'running';
    $failed = is_string($insight['state']) && str_starts_with($insight['state'], 'failed');
@endphp
<div {{ $attributes->class(['rounded-xl bg-violet-50/60 p-4 ring-1 ring-inset ring-violet-200 dark:bg-violet-500/5 dark:ring-violet-500/20']) }} @if ($running) wire:poll.5s @endif>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-semibold text-violet-900 dark:text-violet-200">✨ {{ $insight['label'] }}</p>
        <button type="button" wire:click="runInsight('{{ $insight['kind'] }}', {{ $insight['subject_id'] }})" wire:loading.attr="disabled" @disabled($running)
            class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
            {{ $running ? 'AI çalışıyor…' : ($production ? 'Yeniden hazırla' : 'AI ile hazırla') }}@if ($insight['estimate'] && ! $running) <span class="font-normal opacity-80">({{ $insight['estimate'] }})</span>@endif
        </button>
    </div>
    @if ($failed)
        <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">{{ \Illuminate\Support\Str::after($insight['state'], 'failed: ') }}</p>
    @endif
    @if ($production)
        @if (filled($content['summary'] ?? null))
            <p class="mt-3 text-sm text-gray-800 dark:text-gray-200">{{ $content['summary'] }}</p>
        @endif
        @if (! empty($content['items']))
            <ul class="mt-3 space-y-2">
                @foreach ($content['items'] as $item)
                    @php [$tagLabel, $tagClass] = $insight['tags'][$item['tag']] ?? [$item['tag'], 'bg-gray-100 text-gray-600']; @endphp
                    <li class="rounded-lg bg-white px-3 py-2 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex items-start gap-2">
                            <span class="mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $tagClass }}">{{ $tagLabel }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                                @if (filled($item['detail']))<p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $item['detail'] }}</p>@endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="mt-2 text-[11px] text-gray-400">AI önerisidir, kontrol ederek kullan · {{ $production->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} · Üretim arşivinde saklanır</p>
    @elseif (! $running && ! $compact)
        <p class="mt-2 text-xs text-gray-500">Butona basınca AI mevcut verilerle hazırlar; kendiliğinden çalışmaz.</p>
    @endif
</div>
