@php
    use App\Support\OperatorMenu;

    $menuGroups = OperatorMenu::groups();
    $switcherBrands = OperatorMenu::brandSwitcher();
    $sidebarOpen = '$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen';
@endphp

{{-- Operator sidebar (panel redesign 2026-10-19): 236 px, grouped entries with counters, brand switcher on top. --}}
<aside id="operator-sidebar"
    class="fixed left-0 top-0 z-99999 flex h-screen flex-col border-r border-gray-200 bg-gray-50 px-3 text-gray-900 transition-all duration-200 ease-in-out dark:border-gray-800 dark:bg-gray-950"
    :class="{
        'w-[236px]': {{ $sidebarOpen }},
        'w-[64px]': !$store.sidebar.isExpanded && !$store.sidebar.isHovered,
        'translate-x-0': $store.sidebar.isMobileOpen,
        '-translate-x-full xl:translate-x-0': !$store.sidebar.isMobileOpen
    }"
    @mouseenter="if (!$store.sidebar.isExpanded) $store.sidebar.setHovered(true)"
    @mouseleave="$store.sidebar.setHovered(false)">

    {{-- Workspace: the agency and, under it, every brand it works for (one click to any brand page). --}}
    <div class="relative flex h-14 shrink-0 items-center" x-data="{ open: false, q: '' }" @click.outside="open = false" @keydown.escape.window="open = false" data-brand-switcher>
        <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu"
            class="flex w-full items-center gap-2.5 rounded-lg px-1.5 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-white/5"
            :class="({{ $sidebarOpen }}) ? '' : 'justify-center'">
            @if (! empty($operatorBranding['logo_url']))
                <img src="{{ $operatorBranding['logo_url'] }}" alt="" class="h-7 w-7 shrink-0 rounded-md object-contain" />
            @else
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gray-900 text-sm font-bold text-white dark:bg-white dark:text-gray-900">{{ $operatorBranding['display_initial'] ?? 'M' }}</span>
            @endif
            <span class="min-w-0 flex-1" x-show="{{ $sidebarOpen }}">
                <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</span>
                <span class="block truncate text-[11px] text-gray-500">{{ count($switcherBrands) }} {{ app()->getLocale() === 'tr' ? 'marka' : 'brands' }}</span>
            </span>
            <svg x-show="{{ $sidebarOpen }}" class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 3a.75.75 0 0 1 .55.24l3.25 3.5a.75.75 0 1 1-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 0 1-1.1-1.02l3.25-3.5A.75.75 0 0 1 10 3Zm-3.76 9.2a.75.75 0 0 1 1.06.04l2.7 2.908 2.7-2.908a.75.75 0 1 1 1.1 1.02l-3.25 3.5a.75.75 0 0 1-1.1 0l-3.25-3.5a.75.75 0 0 1 .04-1.06Z" clip-rule="evenodd"/></svg>
        </button>
        <div x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu"
            class="absolute left-0 top-full z-50 mt-1 w-64 rounded-xl bg-white p-1.5 shadow-theme-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
            @if (count($switcherBrands) > 6)
                <input type="search" x-model="q" placeholder="{{ app()->getLocale() === 'tr' ? 'Marka ara' : 'Find a brand' }}" class="mb-1 h-8 w-full rounded-lg border-0 bg-gray-100 px-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-2 focus:ring-gray-300 dark:bg-white/5 dark:text-gray-200" />
            @endif
            <div class="max-h-72 overflow-y-auto">
                @forelse ($switcherBrands as $brand)
                    <a href="{{ $brand['url'] }}" wire:navigate role="menuitem" data-name="{{ mb_strtolower($brand['name']) }}" x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())"
                        class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5" data-brand-switch="{{ $brand['id'] }}">
                        <x-ta.brand-avatar :name="$brand['name']" :logo="$brand['logo']" size="sm" />
                        <span class="truncate">{{ $brand['name'] }}</span>
                    </a>
                @empty
                    <p class="px-2 py-1.5 text-xs text-gray-500">{{ app()->getLocale() === 'tr' ? 'Henüz marka yok.' : 'No brands yet.' }}</p>
                @endforelse
            </div>
            <a href="{{ route('operator.brands') }}" wire:navigate role="menuitem" class="mt-1 block rounded-lg border-t border-gray-100 px-2 pb-1 pt-2 text-xs font-medium text-gray-600 hover:text-gray-900 dark:border-gray-800 dark:text-gray-400 dark:hover:text-white">{{ __('operator.nav.brands') }} →</a>
        </div>
    </div>

    <!-- Navigation -->
    <div class="no-scrollbar flex flex-1 flex-col overflow-y-auto pb-4 pt-1">
        <nav class="flex flex-col gap-4">
            @foreach ($menuGroups as $menuGroup)
                <div>
                    <h2 class="mb-1 flex h-5 items-center px-2.5 text-[11px] font-medium uppercase tracking-wide text-gray-400"
                        :class="({{ $sidebarOpen }}) ? 'justify-start' : 'justify-center'">
                        <span x-show="{{ $sidebarOpen }}">{{ $menuGroup['title'] }}</span>
                        <span x-show="!({{ $sidebarOpen }})" class="h-px w-4 bg-gray-200 dark:bg-gray-800" aria-hidden="true"></span>
                    </h2>

                    <ul class="flex flex-col gap-0.5">
                        @foreach ($menuGroup['items'] as $item)
                            @php
                                // Only the most specific entry is lit (OperatorMenu::activeEntry).
                                $isActive = $item['active'];
                                $external = ! empty($item['external']);
                            @endphp
                            <li>
                                <a href="{{ $item['path'] }}"
                                    @if ($external) target="_self" @endif
                                    @if ($isActive) aria-current="page" @endif
                                    title="{{ $item['name'] }}"
                                    class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}"
                                    :class="({{ $sidebarOpen }}) ? 'justify-start' : 'justify-center'">
                                    <span class="shrink-0 {{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                        {!! OperatorMenu::icon($item['icon']) !!}
                                    </span>
                                    <span class="menu-item-text flex min-w-0 flex-1 items-center gap-2" x-show="{{ $sidebarOpen }}">
                                        <span class="truncate">{{ $item['name'] }}</span>
                                        @if ($external)
                                            <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                                            </svg>
                                        @endif
                                        @if (($item['count'] ?? null) !== null)
                                            <span class="menu-count" data-menu-count="{{ $item['count'] }}">{{ number_format($item['count'], 0, ',', '.') }}</span>
                                        @endif
                                    </span>
                                    @if (($item['count'] ?? null) !== null)
                                        <span x-show="!({{ $sidebarOpen }})" class="absolute right-1.5 top-1 h-1.5 w-1.5 rounded-full bg-brand-500" aria-hidden="true"></span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
    </div>
</aside>
