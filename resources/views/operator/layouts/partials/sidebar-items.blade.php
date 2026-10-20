@php
    use App\Support\OperatorMenu;

    $sidebarOpen = '$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen';
@endphp
<ul class="flex flex-col gap-0.5">
    @foreach ($items as $item)
        @php
            // Only the most specific entry is lit (OperatorMenu::activeEntry).
            $isActive = $item['active'];
            $external = ! empty($item['external']);
            $tone = $item['tone'] ?? null;
            $hasCount = ($item['count'] ?? null) !== null;
        @endphp
        <li>
            <a href="{{ $item['path'] }}"
                @if ($external) target="_self" @endif
                @if ($isActive) aria-current="page" @endif
                title="{{ $item['name'] }}"
                class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}"
                :class="({{ $sidebarOpen }}) ? 'justify-start' : 'justify-center'">
                <span class="menu-icon-box {{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                    {!! OperatorMenu::icon($item['icon']) !!}
                </span>
                <span class="menu-item-text flex min-w-0 flex-1 items-center gap-2" x-show="{{ $sidebarOpen }}">
                    <span class="truncate">{{ $item['name'] }}</span>
                    @if ($external)
                        <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    @endif
                    @if ($hasCount)
                        <span @class(['menu-count', 'menu-count-accent' => $tone === 'accent', 'menu-count-red' => $tone === 'red', 'menu-count-amber' => $tone === 'amber'])
                            data-menu-count="{{ $item['count'] }}" data-menu-badge="{{ $tone ?? 'soft' }}">{{ number_format($item['count'], 0, ',', '.') }}</span>
                    @elseif ($tone === 'dot')
                        <span class="ml-auto mr-1.5 h-2 w-2 shrink-0 rounded-full bg-error-500" data-menu-badge="dot" title="{{ app()->getLocale() === 'tr' ? 'Bozuk bağlantı var' : 'A connection is broken' }}"></span>
                    @endif
                </span>
                @if ($hasCount || $tone === 'dot')
                    <span x-show="!({{ $sidebarOpen }})" @class(['absolute right-1.5 top-1 h-1.5 w-1.5 rounded-full', 'bg-accent' => $tone === 'accent', 'bg-error-500' => in_array($tone, ['red', 'dot'], true), 'bg-warning-500' => $tone === 'amber', 'bg-gray-400' => $tone === null || $tone === 'soft']) aria-hidden="true"></span>
                @endif
            </a>
        </li>
    @endforeach
</ul>
