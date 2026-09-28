<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full overflow-x-hidden">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? __('operator.product.operator') }} | {{ $operatorBranding['portal_name'] ?? 'MoxDOP' }}</title>
    @if (! empty($operatorBranding['favicon_url']))
        <link rel="icon" href="{{ $operatorBranding['favicon_url'] }}" />
    @endif

    @vite(['resources/css/operator.css', 'resources/js/operator.js'])

    {{-- Theme + sidebar Alpine stores, adapted from TailAdmin layouts/app.blade.php --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('theme', {
                theme: 'light',
                init() {
                    const saved = localStorage.getItem('theme');
                    const system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                    this.theme = saved || system;
                    this.updateTheme();
                },
                toggle() {
                    this.theme = this.theme === 'light' ? 'dark' : 'light';
                    localStorage.setItem('theme', this.theme);
                    this.updateTheme();
                },
                updateTheme() {
                    const html = document.documentElement;
                    const body = document.body;
                    if (this.theme === 'dark') {
                        html.classList.add('dark');
                        body.classList.add('dark', 'bg-gray-900');
                    } else {
                        html.classList.remove('dark');
                        body.classList.remove('dark', 'bg-gray-900');
                    }
                }
            });

            Alpine.store('sidebar', {
                isExpanded: window.innerWidth >= 1280,
                isMobileOpen: false,
                isHovered: false,
                toggleExpanded() {
                    this.isExpanded = !this.isExpanded;
                    this.isMobileOpen = false;
                },
                toggleMobileOpen() {
                    this.isMobileOpen = !this.isMobileOpen;
                },
                setMobileOpen(val) {
                    this.isMobileOpen = val;
                },
                setHovered(val) {
                    if (window.innerWidth >= 1280 && !this.isExpanded) {
                        this.isHovered = val;
                    }
                }
            });
        });
    </script>

    {{-- Apply dark mode immediately to prevent flash --}}
    <script>
        (function() {
            const saved = localStorage.getItem('theme');
            const system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            const theme = saved || system;
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
                document.body.classList.add('dark', 'bg-gray-900');
            }
        })();
    </script>
</head>

<body
    class="overflow-x-hidden"
    x-data="{}"
    x-init="$store.sidebar.isExpanded = window.innerWidth >= 1280;
        const checkMobile = () => {
            if (window.innerWidth < 1280) {
                $store.sidebar.setMobileOpen(false);
                $store.sidebar.isExpanded = false;
            } else {
                $store.sidebar.isMobileOpen = false;
                $store.sidebar.isExpanded = true;
            }
        };
        window.addEventListener('resize', checkMobile);">

    <div class="min-h-screen xl:flex">
        @include('operator.layouts.backdrop')
        @include('operator.layouts.sidebar')

        <div class="flex-1 min-w-0 overflow-x-clip transition-all duration-300 ease-in-out"
            :class="{
                'xl:ml-[290px]': $store.sidebar.isExpanded || $store.sidebar.isHovered,
                'xl:ml-[90px]': !$store.sidebar.isExpanded && !$store.sidebar.isHovered,
                'ml-0': $store.sidebar.isMobileOpen
            }">
            @include('operator.layouts.header')

            <div class="min-w-0 p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6">
                @if (request()->routeIs('operator.settings'))
                    <div class="mb-5 flex flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-900 dark:ring-gray-800">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300" aria-hidden="true">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                </span>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.title') }}</p>
                            </div>
                            <p class="mt-1 pl-10 text-xs text-gray-500 dark:text-gray-400">{{ __('background_operations.subtitle') }}</p>
                        </div>
                        <a href="{{ route('operator.settings.background-operations') }}" wire:navigate class="inline-flex shrink-0 items-center justify-center rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">
                            {{ app()->getLocale() === 'tr' ? 'Kontrol merkezini aç' : 'Open control center' }}
                        </a>
                    </div>
                @endif

                @php($sectionTabs = \App\Support\OperatorMenu::sectionTabs())
                @if ($sectionTabs)
                    <nav class="mb-5 -mt-1 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Bölüm">
                        @foreach ($sectionTabs as $tab)
                            <a href="{{ $tab['url'] }}" wire:navigate @if ($tab['active']) aria-current="page" @endif
                                @class(['whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-500 text-brand-600 dark:text-brand-400' => $tab['active'], 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => ! $tab['active']])>{{ $tab['label'] }}</a>
                        @endforeach
                    </nav>
                @endif

                {{ $slot }}
            </div>
        </div>
    </div>

    <livewire:demo.capture-modal />

    {{-- Action notices from any page (e.g. a record deleted in another tab): App\Support\Operator\LivewireActionErrors. --}}
    <div x-data="{ notice: null, timer: null }" x-cloak
        x-on:operator-notice.window="notice = $event.detail; clearTimeout(timer); timer = setTimeout(() => notice = null, 8000)"
        class="pointer-events-none fixed inset-x-4 bottom-4 z-[100000] flex justify-center sm:inset-x-auto sm:right-6">
        <p x-show="notice" x-transition role="alert" x-text="notice?.message" x-on:click="notice = null"
            :class="notice?.tone === 'error' ? 'bg-red-600' : 'bg-gray-900'"
            class="pointer-events-auto max-w-md cursor-pointer rounded-lg px-4 py-3 text-sm text-white shadow-lg"></p>
    </div>

    @stack('scripts')
</body>

</html>
