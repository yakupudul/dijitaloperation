{{-- Genel işler list: brand → one card per site · work type (its rule said once) → rows; overlaps under their cluster. --}}
@php
    $per = \App\Services\Work\WorkDesk::PER_GROUP;
    $workLogos ??= [];
    $ghost = 'btn';
@endphp
<div class="space-y-10" data-work-list>
    @forelse($sections as $section)
        <section class="space-y-3" wire:key="work-brand-{{ $section['brand_id'] ?? 0 }}" data-work-brand="{{ $section['brand_id'] ?? 0 }}">
            <header class="section-head mb-0! items-center!">
                <x-ta.brand-avatar :name="$section['brand']" :logo="$section['brand_id'] !== null ? ($workLogos[$section['brand_id']] ?? null) : null" size="sm" />
                <h2 class="section-title">{{ $section['brand'] }} <span class="section-count">{{ $section['count'] }} iş</span></h2>
                @if($section['urgent'] > 0)<span class="text-[13px] text-error-700 dark:text-error-400">{{ $section['urgent'] }} acil</span>@endif
                @if($brand === null && $section['brand_id'] !== null && count($sections) > 1)
                    <button type="button" wire:click="showBrand({{ $section['brand_id'] }})" class="link-neutral ml-auto text-[13px]">Yalnız bu marka</button>
                @endif
            </header>

            @foreach($section['groups'] as $group)
                @php
                    $all = isset($expanded[$group['key']]);
                    $units = $group['clusters'] ?? $group['items'];
                    $shownUnits = $all ? $units : array_slice($units, 0, $per);
                    $hidden = count($units) - count($shownUnits);
                    $sharedAsset = $group['asset'];
                @endphp
                <article wire:key="work-group-{{ $group['key'] }}" class="card overflow-visible! text-sm" data-work-group="{{ $group['key'] }}">
                    <header class="flex flex-col gap-2 rounded-t-xl border-b border-gray-200 bg-gray-50/60 px-4 py-3 sm:flex-row sm:items-start sm:gap-3 dark:border-gray-800 dark:bg-white/[0.02]">
                        <div class="min-w-0 sm:flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                <h3 class="text-sm font-medium text-gray-900 first-letter:uppercase dark:text-white">{{ $group['type'] }}</h3>
                                <span class="text-[13px] tabular-nums text-gray-500">{{ $group['count'] }}</span>
                                @if($group['urgent'] > 0)<span class="text-[13px] text-error-700 dark:text-error-400">{{ $group['urgent'] }} acil</span>@endif
                                @if($sharedAsset)<span class="min-w-0 truncate text-[13px] text-gray-500">{{ $sharedAsset }}</span>@endif
                            </div>
                            @if($group['about'])<p class="mt-0.5 max-w-3xl text-xs text-gray-500" data-work-rule>{{ $group['about'] }}</p>@endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @php $mergeable = $group['mergeable'] ?? []; $ticked = count(array_intersect(array_map('intval', $selected), $mergeable)); @endphp
                            @if($view === 'acik' && $ticked > 0)
                                <button type="button" wire:click="mergeSelected" wire:confirm="Seçilen {{ $ticked }} sayfanın ziyaretçileri ana sayfalarına yönlendirilsin ve sayfalar taslağa alınsın mı? Silinmez, geri alınabilir." class="btn-primary" data-merge-selected>Seçilenleri birleştir ({{ $ticked }})</button>
                            @endif
                            @if($view === 'acik' && count($mergeable) > 1)
                                <button type="button" wire:click="mergeGroup('{{ $group['key'] }}')" wire:confirm="Bu karttaki {{ count($mergeable) }} «Birleştirilecek» sayfanın ziyaretçileri ana sayfalarına yönlendirilsin ve sayfalar taslağa alınsın mı? Silinmez, geri alınabilir." class="{{ $ghost }}" data-merge-group>Hepsini birleştir ({{ count($mergeable) }})</button>
                            @endif
                            @if($view === 'acik' && $group['snoozable'] > 1)
                                <button type="button" wire:click="snoozeGroup('{{ $group['key'] }}')" wire:confirm="Bu karttaki {{ $group['snoozable'] }} iş 7 gün ertelensin mi?{{ $group['snoozable'] < $group['count'] ? ' Onaylanmış işler ertelenmez.' : '' }}" class="{{ $ghost }}" data-snooze-group>Hepsini 7 gün ertele ({{ $group['snoozable'] }})</button>
                            @endif
                            @if($group['url'])
                                <a href="{{ $group['url'] }}" @if($group['external']) target="_blank" rel="noopener" @else wire:navigate @endif class="link-neutral text-[13px]">{{ $group['url_label'] }}</a>
                            @endif
                        </div>
                    </header>

                    @if($group['clusters'] !== null)
                        @foreach($shownUnits as $cluster)
                            <div class="border-t border-gray-200 first:border-t-0 dark:border-gray-800" wire:key="work-cluster-{{ $group['key'] }}-{{ $loop->index }}" data-work-cluster>
                                @if($cluster['name'] !== null)
                                    <div class="px-4 pt-3 pb-1">
                                        <p class="font-medium text-gray-900 dark:text-white">«{{ $cluster['name'] }}»</p>
                                        <p class="text-xs text-gray-500">
                                            Ana sayfa:
                                            @if($cluster['main_url'])<a href="{{ $cluster['main_url'] }}" target="_blank" rel="noopener" class="text-gray-700 hover:underline dark:text-gray-300">{{ $cluster['main_path'] }}</a>@else{{ $cluster['main_path'] }}@endif.
                                            {{ count($cluster['items']) }} sayfa aynı ihtiyaca yanıt veriyor.
                                        </p>
                                    </div>
                                @endif
                                <ul>
                                    @foreach($cluster['items'] as $row)
                                        @include('livewire.operator.work.partials.work-row', ['row' => $row, 'group' => $group])
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @else
                        <ul>
                            @foreach($shownUnits as $row)
                                @include('livewire.operator.work.partials.work-row', ['row' => $row, 'group' => $group])
                            @endforeach
                        </ul>
                    @endif

                    @if($hidden > 0)
                        <button type="button" wire:click="expand('{{ $group['key'] }}')" class="w-full rounded-b-xl border-t border-gray-200 py-2.5 text-[13px] font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/5" data-expand-group>
                            Tümünü göster (+{{ $hidden }} {{ $group['clusters'] !== null ? 'küme' : 'iş' }})
                        </button>
                    @endif
                </article>
            @endforeach
        </section>
    @empty
        @if(! ($tab === 'icerik' && $view === 'acik'))
            <div class="card"><x-ta.empty-state size="sm" :icon="$view === 'acik' ? 'check' : 'none'" :title="$view === 'acik' ? 'Bu sekmede açık iş yok.' : 'Son 30 günde yapılan iş yok.'" /></div>
        @endif
    @endforelse

    @if($truncated)
        <p class="text-xs text-gray-500" data-work-truncated>Bu sekmede en acil {{ \App\Services\Work\WorkDesk::LIMIT }} iş listeleniyor; geri kalanı için marka seç.</p>
    @endif

    @if($hiddenSections > 0)
        <button type="button" wire:click="more" class="btn w-full">Daha fazla marka göster ({{ $hiddenSections }})</button>
    @endif
</div>
