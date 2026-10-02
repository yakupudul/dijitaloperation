{{-- Channel tab (Arama · Harita · Google Ads · Meta): the channel component once it exists; until then which assets feed
     the channel and their data status, what is missing with the fix, and the channel's open suggestions. --}}
<section aria-label="{{ $workspaceTabs[$mainTab] }}">
    @if ($channelComponent !== null)
        @livewire($channelComponent, ['brandId' => $brandModel->id], key('workspace-'.$mainTab.'-'.$brandModel->id))
    @else
        @php $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800'; @endphp
        <div class="space-y-6" data-workspace-pending="{{ $mainTab }}">
            @unless ($operational)
                <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.03] dark:text-gray-400 dark:ring-gray-800">{{ \App\Support\ServiceScope::NOT_SERVED }}</p>
            @endunless

            <section aria-labelledby="channel-sources-heading">
                <div class="mb-2">
                    <h2 id="channel-sources-heading" class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $workspaceTabs[$mainTab] }} · veri kaynakları</h2>
                    <p class="text-xs text-gray-500">Bu kanalın marka özeti henüz bu sekmede değil; rakamlar ve işlemler varlığın kendi ekranında.</p>
                </div>
                @if ($channel['assets'] === [])
                    <div class="{{ $panel }} flex flex-wrap items-center justify-between gap-3 px-5 py-4" data-channel-missing>
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">Markaya bağlı {{ $channel['asset_label'] }} yok.</p>
                            <p class="text-xs text-gray-500">Bağlanmadan bu kanal için veri toplanmaz ve öneri üretilmez. Markada yoksa sorun değil.</p>
                        </div>
                        <span class="flex items-center gap-2">
                            <a href="{{ route('operator.brand', ['brand' => $brandModel->id, 'tab' => 'assets']) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600">Hesap bağla</a>
                            <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Varlık ekle</a>
                        </span>
                    </div>
                @else
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($channel['assets'] as $asset)
                            @include('livewire.operator.portfolio.partials.asset-card', ['asset' => $asset])
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="{{ $panel }}" aria-labelledby="channel-work-heading" data-channel-work>
                <h2 id="channel-work-heading" class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-800 dark:border-gray-800 dark:text-white/90">Açık öneriler <span class="font-normal text-gray-400">{{ $channel['work']['total'] }}</span></h2>
                @forelse ($channel['work']['items'] as $item)
                    <div wire:key="channel-work-{{ $item['id'] }}" class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-800" data-work-item="{{ $item['id'] }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                            @if ($item['reason'] !== '')<p class="mt-0.5 text-xs text-gray-500">{{ $item['reason'] }}</p>@endif
                        </div>
                        @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Aç →</a>@endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-500">Bu kanalda açık öneri yok.@if ($channel['assets'] === []) Önce {{ $channel['asset_label'] }} bağlanmalı.@endif</p>
                @endforelse
            </section>
        </div>
    @endif
</section>
