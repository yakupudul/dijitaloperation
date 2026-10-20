@php
    use App\Support\OperatorMenu;

    $menuGroups = OperatorMenu::groups();
    $switcherBrands = OperatorMenu::brandSwitcher();
    $sidebarOpen = '$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen';
@endphp

{{-- Operator sidebar: 236 px on the gray page, brand switcher card on top, grouped entries with work badges, Claude card
     and Sistem pinned at the bottom (menu prototype approved 2026-10-20). --}}
<aside id="operator-sidebar"
    class="fixed left-0 top-0 z-99999 flex h-dvh flex-col border-r border-gray-200 bg-page px-3 text-gray-900 transition-all duration-200 ease-in-out xl:border-r-0 dark:border-gray-800 dark:bg-gray-950"
    :class="{
        'w-[236px]': {{ $sidebarOpen }},
        'w-[64px]': !$store.sidebar.isExpanded && !$store.sidebar.isHovered,
        'translate-x-0': $store.sidebar.isMobileOpen,
        '-translate-x-full xl:translate-x-0': !$store.sidebar.isMobileOpen
    }"
    @mouseenter="if (!$store.sidebar.isExpanded) $store.sidebar.setHovered(true)"
    @mouseleave="$store.sidebar.setHovered(false)">

    {{-- Workspace: the agency and, under it, every brand it works for (one click to any brand page). --}}
    <div class="relative flex h-16 shrink-0 items-center" x-data="{ open: false, q: '' }" @click.outside="open = false" @keydown.escape.window="open = false" data-brand-switcher>
        <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu"
            class="flex w-full items-center gap-2.5 rounded-[12px] border border-gray-200 bg-white px-2 py-1.5 text-left shadow-card transition hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.04] dark:hover:border-gray-700"
            :class="({{ $sidebarOpen }}) ? '' : 'justify-center'">
            @if (! empty($operatorBranding['logo_url']))
                <img src="{{ $operatorBranding['logo_url'] }}" alt="" class="h-7 w-7 shrink-0 rounded-md object-contain" />
            @else
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gray-900 text-sm font-bold text-white dark:bg-white dark:text-gray-900">{{ $operatorBranding['display_initial'] ?? 'M' }}</span>
            @endif
            <span class="min-w-0 flex-1" x-show="{{ $sidebarOpen }}">
                <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</span>
                <span class="block truncate text-xs text-gray-500">{{ count($switcherBrands) }} {{ app()->getLocale() === 'tr' ? 'marka' : 'brands' }}</span>
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
            <a href="{{ route('operator.brands') }}" wire:navigate role="menuitem" class="mt-1 block rounded-lg border-t border-gray-100 px-2 pb-1 pt-2 text-xs font-medium text-gray-600 hover:text-gray-900 dark:border-gray-800 dark:text-gray-400 dark:hover:text-white">{{ __('operator.nav.brands') }}</a>
        </div>
    </div>

    <!-- Navigation: groups scroll, Sistem stays pinned at the bottom with the Claude card (menu prototype 2026-10-20). -->
    @php
        $scrollGroups = array_values(array_filter($menuGroups, fn (array $g): bool => ! $g['pinned']));
        $pinnedGroups = array_values(array_filter($menuGroups, fn (array $g): bool => $g['pinned']));
    @endphp
    <div class="no-scrollbar flex flex-1 flex-col overflow-y-auto overscroll-contain pb-3 pt-1">
        <nav class="flex flex-col gap-3" aria-label="{{ app()->getLocale() === 'tr' ? 'Ana menü' : 'Main menu' }}">
            @foreach ($scrollGroups as $menuGroup)
                <div data-menu-group="{{ $menuGroup['title'] }}">
                    @unless ($loop->first)
                        <h2 class="mb-0.5 flex h-6 items-center px-2.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-gray-400 dark:text-gray-500"
                            :class="({{ $sidebarOpen }}) ? 'justify-start' : 'justify-center'">
                            <span x-show="{{ $sidebarOpen }}">{{ $menuGroup['title'] }}</span>
                            <span x-show="!({{ $sidebarOpen }})" class="h-px w-4 bg-gray-200 dark:bg-gray-800" aria-hidden="true"></span>
                        </h2>
                    @endunless
                    @include('operator.layouts.partials.sidebar-items', ['items' => $menuGroup['items']])
                </div>
            @endforeach
        </nav>
    </div>

    <div class="shrink-0 border-t border-gray-200 pb-3 pt-2.5 dark:border-gray-800" data-menu-pinned>
        @auth
            <livewire:operator.ai-live-indicator card />
        @endauth
        @foreach ($pinnedGroups as $menuGroup)
            <div class="mt-1.5" data-menu-group="{{ $menuGroup['title'] }}">
                @include('operator.layouts.partials.sidebar-items', ['items' => $menuGroup['items']])
            </div>
        @endforeach
    </div>
</aside>
