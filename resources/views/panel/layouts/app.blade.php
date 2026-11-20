@php
    use App\Panel\Models\PanelMembership;
    use App\Panel\Support\PanelContext;
    use App\Support\OperatorMenu;

    $panel = app(PanelContext::class);
    $membership = $panel->membership();
    $panelUser = auth('client')->user();
    $current = (string) request()->route()?->getName();
    $entries = array_values(array_filter([
        ['Ana sayfa', 'panel.home', 'dashboard', 'menu.home', ['panel.home']],
        ['Adaylar', 'panel.leads', 'customers', 'menu.leads', ['panel.leads', 'panel.person', 'panel.presets']],
        ['Gelen kutusu', 'panel.inbox', 'inbox', 'menu.inbox', ['panel.inbox']],
        ['Performans', 'panel.performance', 'report', 'menu.performance', ['panel.performance']],
        ['Destek', 'panel.support', 'chat', 'menu.support', ['panel.support']],
        ['Kullanıcılar', 'panel.users', 'prospects', 'menu.users', ['panel.users']],
    ], fn (array $entry): bool => $panel->can($entry[3])));
    $otherBrands = $panelUser ? PanelMembership::query()->with('brand:id,name')->where('panel_user_id', $panelUser->id)->where('is_active', true)->where('brand_id', '!=', $panel->brandId())->get() : collect();
    $viewer = session('panel.viewer');
@endphp
<!DOCTYPE html>
<html lang="tr" class="h-full overflow-x-hidden">

<head>
    @include('panel.layouts.head')
</head>

<body class="overflow-x-hidden" x-data="{ menu: false }" @keydown.escape.window="menu = false">
    @if ($viewer)
        <p class="relative z-[100000] bg-gray-900 px-4 py-1.5 text-center text-[13px] text-white" data-panel-viewer>{{ $viewer }}, bu ekranı {{ $panelUser?->name }} olarak görüyorsunuz. Bu bakış kayda geçti.</p>
    @endif
    <div class="min-h-screen xl:flex">
        <div x-show="menu" x-cloak x-transition.opacity @click="menu = false" class="fixed inset-0 z-[9998] bg-gray-900/40 xl:hidden" aria-hidden="true"></div>

        <aside class="fixed left-0 top-0 z-[9999] flex h-dvh w-[236px] -translate-x-full flex-col border-r border-gray-200 bg-page px-3 transition-transform duration-200 ease-in-out xl:translate-x-0 xl:border-r-0"
            :class="menu ? 'translate-x-0' : ''" data-panel-menu>
            <div class="flex h-16 shrink-0 items-center gap-2.5 px-1.5">
                <x-ta.brand-avatar :name="$membership?->brand?->name ?? ''" :logo="$membership?->brand?->logo_url" size="sm" />
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-gray-900">{{ $membership?->brand?->name }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ config('panel.name') }}</span>
                </span>
            </div>
            <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto pb-3 pt-1" aria-label="Ana menü">
                @foreach ($entries as [$label, $route, $icon, $permission, $routes])
                    @php($active = in_array($current, $routes, true))
                    <a href="{{ route($route) }}" wire:navigate @if ($active) aria-current="page" @endif
                        @class(['group menu-item', 'menu-item-active' => $active, 'menu-item-inactive' => ! $active])>
                        <span @class(['menu-icon-box', 'menu-item-icon-active' => $active, 'menu-item-icon-inactive' => ! $active])>{!! OperatorMenu::icon($icon) !!}</span>
                        <span class="truncate">{{ $label }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="shrink-0 border-t border-gray-200 py-3" x-data="{ open: false }" @click.outside="open = false">
                <div x-show="open" x-cloak class="mb-2 rounded-xl bg-white p-1.5 shadow-theme-lg ring-1 ring-gray-200">
                    @foreach ($otherBrands as $other)
                        <form method="POST" action="{{ route('panel.brand') }}">@csrf
                            <input type="hidden" name="brand_id" value="{{ $other->brand_id }}">
                            <button type="submit" class="block w-full truncate rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-100">{{ $other->brand?->name }} markasına geç</button>
                        </form>
                    @endforeach
                    @unless ($viewer)
                        <a href="{{ route('panel.password') }}" class="block rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100">Şifremi değiştir</a>
                    @endunless
                    <form method="POST" action="{{ route('panel.logout') }}">@csrf
                        <button type="submit" class="block w-full rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-100">Çıkış yap</button>
                    </form>
                </div>
                <button type="button" @click="open = ! open" :aria-expanded="open" class="flex w-full items-center gap-2.5 rounded-[10px] px-1.5 py-1.5 text-left hover:bg-black/[0.04]" data-panel-user>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-semibold text-gray-700 shadow-card" aria-hidden="true">{{ $panelUser?->initials() }}</span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-gray-900">{{ $panelUser?->name }}</span>
                        <span class="block truncate text-xs text-gray-500">{{ $membership?->is_manager ? 'Yönetici' : ($membership?->role?->name ?? 'Kullanıcı') }}</span>
                    </span>
                </button>
            </div>
        </aside>

        <div class="min-w-0 flex-1 xl:ml-[236px]">
            <div class="xl:py-2 xl:pr-2">
                <div class="min-h-dvh bg-white xl:min-h-[calc(100dvh-1rem)] xl:rounded-xl xl:border xl:border-gray-200 xl:shadow-card">
                    <header class="flex h-14 items-center gap-3 border-b border-gray-200 px-4 xl:hidden">
                        <button type="button" @click="menu = true" class="btn h-9! w-9! px-0!" aria-label="Menüyü aç">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                        </button>
                        <span class="truncate text-sm font-semibold text-gray-900">{{ $membership?->brand?->name }}</span>
                    </header>
                    <main class="mx-auto min-w-0 max-w-[1520px] px-4 pb-16 pt-6 md:px-10 md:pt-8">
                        @if (session('status'))
                            <p class="mb-5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700" role="status">{{ session('status') }}</p>
                        @endif
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
    </div>

    <div x-data="{ notice: null, timer: null }" x-cloak
        x-on:operator-notice.window="notice = $event.detail; clearTimeout(timer); timer = setTimeout(() => notice = null, notice?.tone === 'error' ? 9000 : 4000)"
        class="pointer-events-none fixed inset-x-4 bottom-4 z-[100000] flex justify-center sm:inset-x-auto sm:right-6">
        <div x-show="notice" role="status" x-on:click="notice = null" x-transition.opacity
            :class="notice?.tone === 'error' ? 'bg-error-600' : 'bg-gray-900'"
            class="pointer-events-auto max-w-md cursor-pointer rounded-xl px-4 py-3 text-sm text-white shadow-theme-lg">
            <span x-text="notice?.message"></span>
        </div>
    </div>
</body>

</html>
