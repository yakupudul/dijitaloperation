{{-- One digital asset of the brand as a card: type and name, each data source's state (last data / collection / problem) with its fix, then its screen. --}}
@php
    $dot = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-500', 'bad' => 'bg-error-500', 'muted' => 'bg-gray-300 dark:bg-gray-600'];
    $text = ['ok' => 'text-gray-600 dark:text-gray-300', 'warn' => 'text-warning-800 dark:text-warning-400', 'bad' => 'text-error-700 dark:text-error-400', 'muted' => 'text-gray-500 dark:text-gray-400'];
@endphp
<article wire:key="asset-card-{{ $asset['id'] }}" data-asset-card="{{ $asset['id'] }}" data-tone="{{ $asset['tone'] }}" class="card flex flex-col">
    <div class="flex items-center gap-3 px-4 pt-3.5 pb-3">
        <x-demo.digital-asset-mark :type="$asset['type']" size="md" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $asset['name'] }}">{{ $asset['name'] }}</p>
            <p class="mt-0.5 text-[13px] text-gray-500">{{ $asset['type_label'] }}@unless ($asset['active']), pasif @endunless</p>
        </div>
        <span class="h-2 w-2 shrink-0 rounded-full {{ $dot[$asset['tone']] ?? $dot['muted'] }}" title="{{ $asset['summary'] }}"></span>
    </div>

    <ul class="flex-1 space-y-2 px-4 pb-3.5">
        @forelse ($asset['sources'] as $source)
            <li class="text-[13px]" data-asset-source="{{ $source['capability'] }}" data-state="{{ $source['state'] }}">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot[$source['tone']] ?? $dot['muted'] }}" aria-hidden="true"></span>
                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $source['label'] }}</span>
                    <span class="{{ $text[$source['tone']] ?? $text['muted'] }}">{{ $source['state_label'] }}</span>
                    @if ($source['collecting'])<span class="text-gray-500">{{ __('data_status.collecting', [], 'tr') }}</span>@endif
                    @if ($source['action'] === \App\Services\DataStatus\DataStatus::ACTION_REFRESH)
                        @if ($source['state'] === \App\Services\DataStatus\DataStatus::STALE && ! $source['collecting'])
                            <button type="button" wire:click="setupCollectNow('{{ $source['capability'] }}')" wire:loading.attr="disabled" class="link-primary ml-auto">{{ $source['action_label'] }}</button>
                        @endif
                    @elseif ($source['action_url'])
                        <a href="{{ $source['action_url'] }}" @if ($source['action'] === \App\Services\DataStatus\DataStatus::ACTION_BIND) wire:navigate @endif @class(['ml-auto font-medium hover:underline', 'text-error-600 dark:text-error-400' => $source['action'] === \App\Services\DataStatus\DataStatus::ACTION_RECONNECT, 'link-primary' => $source['action'] !== \App\Services\DataStatus\DataStatus::ACTION_RECONNECT])>{{ $source['action_label'] }}</a>
                    @endif
                </div>
                @if ($source['detail'] !== '' || $source['resource'])
                    <p class="ml-3.5 truncate text-xs text-gray-500" title="{{ $source['resource'] }}">{{ collect([$source['resource'], $source['detail']])->filter()->implode(', ') }}</p>
                @endif
            </li>
        @empty
            <li class="text-[13px] text-gray-500">Bu varlık tipine veri kaynağı bağlanmaz.</li>
        @endforelse
    </ul>

    <div class="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-2.5 dark:border-gray-800">
        <a href="{{ $asset['sources_url'] }}" wire:navigate class="text-[13px] text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">Veri kaynakları</a>
        <a href="{{ $asset['url'] }}" wire:navigate class="btn h-7! px-2.5! text-xs!" data-asset-open="{{ $asset['id'] }}">Ekranı aç</a>
    </div>
</article>
