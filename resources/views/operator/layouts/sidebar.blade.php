@php
    use App\Support\OperatorMenu;

    $menuGroups = OperatorMenu::groups();
    $currentPath = '/'.ltrim(request()->path(), '/');
@endphp

<aside id="operator-sidebar"
    class="fixed flex flex-col mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-99999 border-r border-gray-200"
    :class="{
        'w-[290px]': $store.sidebar.isExpanded || $store.sidebar.isMobileOpen || $store.sidebar.isHovered,
        'w-[90px]': !$store.sidebar.isExpanded && !$store.sidebar.isHovered,
        'translate-x-0': $store.sidebar.isMobileOpen,
        '-translate-x-full xl:translate-x-0': !$store.sidebar.isMobileOpen
    }"
    @mouseenter="if (!$store.sidebar.isExpanded) $store.sidebar.setHovered(true)"
    @mouseleave="$store.sidebar.setHovered(false)">

    <!-- Brand -->
    <div class="pt-8 pb-7 flex items-center gap-3"
        :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'xl:justify-center' : 'justify-start'">
        <a href="{{ route('operator.dashboard') }}" class="flex items-center gap-3">
            @if (! empty($operatorBranding['logo_url']))
                <img src="{{ $operatorBranding['logo_url'] }}" alt="" class="h-10 w-10 rounded-xl object-contain shrink-0" />
            @else
                <span class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-500 text-white font-bold text-lg shrink-0">{{ $operatorBranding['display_initial'] ?? 'M' }}</span>
            @endif
            <span class="text-xl font-bold text-gray-800 dark:text-white/90"
                x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">{{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</span>
        </a>
    </div>

    <!-- Navigation -->
    <div class="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar">
        <nav class="mb-6">
            <div class="flex flex-col gap-4">
                @foreach ($menuGroups as $menuGroup)
                    <div>
                        <h2 class="mb-4 text-xs uppercase flex leading-[20px] text-gray-400"
                            :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'lg:justify-center' : 'justify-start'">
                            <template x-if="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">
                                <span>{{ $menuGroup['title'] }}</span>
                            </template>
                            <template x-if="!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen">
                                <span>&middot;&middot;&middot;</span>
                            </template>
                        </h2>

                        <ul class="flex flex-col gap-1">
                            @foreach ($menuGroup['items'] as $item)
                                @php
                                    $isActive = $currentPath === $item['path']
                                        || ($item['path'] !== '/' && str_starts_with($currentPath, $item['path']));
                                    $external = ! empty($item['external']);
                                @endphp
                                <li>
                                    <a href="{{ $item['path'] }}"
                                        @if ($external) target="_self" @endif
                                        class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}"
                                        :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'xl:justify-center' : 'justify-start'">
                                        <span class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                            {!! OperatorMenu::icon($item['icon']) !!}
                                        </span>
                                        <span class="menu-item-text flex items-center gap-2"
                                            x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">
                                            {{ $item['name'] }}
                                            @if ($external)
                                                <svg class="w-3.5 h-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                                                </svg>
                                            @endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </nav>
    </div>
</aside>
