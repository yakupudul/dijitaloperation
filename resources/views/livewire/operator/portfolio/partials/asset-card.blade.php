{{-- One digital asset of the brand: type, name, each data source's state (last data / collection / problem) and the fix, then its screen. --}}
@php
    $dot = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-500', 'bad' => 'bg-error-500', 'muted' => 'bg-gray-300 dark:bg-gray-600'];
    $text = ['ok' => 'text-success-700 dark:text-success-400', 'warn' => 'text-warning-700 dark:text-warning-400', 'bad' => 'text-error-600 dark:text-error-400', 'muted' => 'text-gray-500 dark:text-gray-400'];
@endphp
<article wire:key="asset-card-{{ $asset['id'] }}" data-asset-card="{{ $asset['id'] }}" data-tone="{{ $asset['tone'] }}" class="surface flex min-w-0 flex-col p-4">
    <div class="flex items-start gap-3">
        <x-demo.digital-asset-mark :type="$asset['type']" size="md" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-800 dark:text-white/90" title="{{ $asset['name'] }}">{{ $asset['name'] }}</p>
            <p class="text-xs text-gray-500">{{ $asset['type_label'] }}@unless ($asset['active']) · <span class="text-gray-400">pasif</span>@endunless</p>
        </div>
        <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ $dot[$asset['tone']] ?? $dot['muted'] }}" title="{{ $asset['summary'] }}"></span>
    </div>

    <ul class="mt-3 flex-1 space-y-2">
        @forelse ($asset['sources'] as $source)
            <li class="text-xs" data-asset-source="{{ $source['capability'] }}" data-state="{{ $source['state'] }}">
                <div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot[$source['tone']] ?? $dot['muted'] }}"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $source['label'] }}</span>
                    <span class="{{ $text[$source['tone']] ?? $text['muted'] }}">{{ $source['state_label'] }}</span>
                    @if ($source['collecting'])<span class="rounded-full bg-gray-50 px-1.5 text-[11px] text-gray-700 dark:bg-gray-500/10 dark:text-gray-300">{{ __('data_status.collecting', [], 'tr') }}</span>@endif
                    @if ($source['action'] === \App\Services\DataStatus\DataStatus::ACTION_REFRESH)
                        @if ($source['state'] === \App\Services\DataStatus\DataStatus::STALE && ! $source['collecting'])
                            <button type="button" wire:click="setupCollectNow('{{ $source['capability'] }}')" wire:loading.attr="disabled" class="link-neutral font-medium">{{ $source['action_label'] }} →</button>
                        @endif
                    @elseif ($source['action_url'])
                        <a href="{{ $source['action_url'] }}" @if ($source['action'] === \App\Services\DataStatus\DataStatus::ACTION_BIND) wire:navigate @endif @class(['font-medium hover:underline', 'text-error-600' => $source['action'] === \App\Services\DataStatus\DataStatus::ACTION_RECONNECT, 'link-neutral' => $source['action'] !== \App\Services\DataStatus\DataStatus::ACTION_RECONNECT])>{{ $source['action_label'] }} →</a>
                    @endif
                </div>
                @if ($source['detail'] !== '' || $source['resource'])
                    <p class="ml-3 truncate text-gray-400" title="{{ $source['resource'] }}">{{ collect([$source['resource'], $source['detail']])->filter()->implode(' · ') }}</p>
                @endif
            </li>
        @empty
            <li class="text-xs text-gray-400">Bu varlık tipine veri kaynağı bağlanmaz.</li>
        @endforelse
    </ul>

    <div class="mt-3 flex items-center justify-between gap-2 border-t border-gray-100 pt-3 text-xs dark:border-gray-800">
        <a href="{{ $asset['sources_url'] }}" wire:navigate class="text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">Veri kaynakları</a>
        <a href="{{ $asset['url'] }}" wire:navigate class="link-neutral font-medium" data-asset-open="{{ $asset['id'] }}">Ekranı aç →</a>
    </div>
</article>
