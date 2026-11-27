@php
    use App\Panel\Models\PanelMembership;
    use App\Panel\Support\PanelContext;
    use App\Panel\Support\PanelMenu;

    $panel = app(PanelContext::class);
    $membership = $panel->membership();
    $panelUser = auth('client')->user();
    $current = (string) request()->route()?->getName();
    $groups = PanelMenu::for($panel, $current);
    $group = collect($groups)->firstWhere('active', true);
    $otherBrands = $panelUser ? PanelMembership::query()->with('brand:id,name')->where('panel_user_id', $panelUser->id)->where('is_active', true)->where('brand_id', '!=', $panel->brandId())->get() : collect();
    $viewer = session('panel.viewer');
    $roleName = $membership?->is_manager ? 'Yönetici' : ($membership?->role?->name ?? 'Kullanıcı');
@endphp
<!DOCTYPE html>
<html lang="tr" class="h-full overflow-x-hidden">

<head>
    @include('panel.layouts.head')
</head>

<body class="ui2 overflow-x-hidden bg-white">
    @if ($viewer)
        <p class="relative z-[100000] bg-gray-900 px-4 py-1.5 text-center text-[13px] text-white" data-panel-viewer>{{ $viewer }}, bu ekranı {{ $panelUser?->name }} olarak görüyorsunuz. Bu bakış kayda geçti.</p>
    @endif
    <div class="min-h-dvh">
        <aside class="fixed inset-y-0 left-0 z-40 hidden w-[232px] flex-col print:hidden! border-r border-line bg-white px-3 py-3.5 lg:flex" data-panel-menu>
            <a href="{{ route('panel.home') }}" wire:navigate class="flex items-center gap-2.5 px-1.5 pb-3.5 pt-1">
                <img src="/images/app/icon-192.png" alt="" width="30" height="30" class="h-[30px] w-[30px] rounded-[7px]">
                <span class="font-brand text-[19px] font-semibold leading-none tracking-[-0.01em] text-gray-900">Moximu</span>
            </a>
            <div class="relative mb-3.5" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @if ($otherBrands->isNotEmpty()) @click="open = ! open" :aria-expanded="open" @endif @class(['flex w-full items-center gap-2.5 rounded-[10px] border border-line px-2 py-2 text-left', 'hover:bg-soft' => $otherBrands->isNotEmpty(), 'cursor-default' => $otherBrands->isEmpty()]) data-panel-brand>
                    <x-ta.brand-avatar :name="$membership?->brand?->name ?? ''" :logo="$membership?->brand?->logo_url" size="sm" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-gray-900">{{ $membership?->brand?->name }}</span>
                        <span class="block truncate text-[13px] text-gray-500">{{ $roleName }}</span>
                    </span>
                </button>
                @if ($otherBrands->isNotEmpty())
                    <div x-show="open" x-cloak class="absolute inset-x-0 top-full z-50 mt-1 rounded-xl border border-line bg-white p-1.5 shadow-theme-lg">
                        @foreach ($otherBrands as $other)
                            <form method="POST" action="{{ route('panel.brand') }}">@csrf
                                <input type="hidden" name="brand_id" value="{{ $other->brand_id }}">
                                <button type="submit" class="block w-full truncate rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-soft">{{ $other->brand?->name }} markasına geç</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
            <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto" aria-label="Ana menü">
                @foreach ($groups as $entry)
                    <a href="{{ $entry['url'] }}" wire:navigate @if ($entry['active']) aria-current="page" @endif
                        @class(['flex items-center gap-[11px] rounded-[9px] px-2.5 py-[9px] text-[15px] leading-5', 'bg-soft font-semibold text-primary' => $entry['active'], 'font-medium text-gray-800 hover:bg-hover' => ! $entry['active']])>
                        {!! $entry['icon'] !!}<span class="truncate">{{ $entry['label'] }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="relative border-t border-line pt-2.5" x-data="{ open: false }" @click.outside="open = false">
                <div x-show="open" x-cloak class="absolute inset-x-0 bottom-full z-50 mb-1 rounded-xl border border-line bg-white p-1.5 shadow-theme-lg">
                    @unless ($viewer)
                        <a href="{{ route('panel.password') }}" class="block rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-soft">Şifremi değiştir</a>
                    @endunless
                    <form method="POST" action="{{ route('panel.logout') }}">@csrf
                        <button type="submit" class="block w-full rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-soft">Çıkış yap</button>
                    </form>
                </div>
                <button type="button" @click="open = ! open" :aria-expanded="open" class="flex w-full items-center gap-2.5 rounded-[10px] px-2 py-1.5 text-left hover:bg-hover" data-panel-user>
                    <span class="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-full bg-soft text-[13px] font-semibold text-gray-900" aria-hidden="true">{{ $panelUser?->initials() }}</span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-gray-900">{{ $panelUser?->name }}</span>
                        <span class="block truncate text-[13px] text-gray-500">{{ $roleName }}</span>
                    </span>
                </button>
            </div>
        </aside>

        <div class="min-w-0 lg:ml-[232px] print:ml-0!">
            <div class="mx-auto max-w-[1240px] px-4 pb-28 lg:px-9 lg:pb-16">
                <header class="flex h-[60px] items-center gap-3 print:hidden">
                    <a href="{{ route('panel.home') }}" wire:navigate class="flex min-w-0 items-center gap-2 lg:hidden">
                        <img src="/images/app/icon-192.png" alt="" width="28" height="28" class="h-7 w-7 shrink-0 rounded-[7px]">
                        <span class="truncate text-[15px] font-semibold text-gray-900">{{ $membership?->brand?->name }}</span>
                    </a>
                    <span class="flex-1"></span>
                    <livewire:panel.bell side="right" />
                    <div class="relative lg:hidden" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" @click="open = ! open" :aria-expanded="open" class="grid h-9 w-9 place-items-center rounded-full bg-soft text-[13px] font-semibold text-gray-900" aria-label="Hesabım" data-panel-user-phone>{{ $panelUser?->initials() }}</button>
                        <div x-show="open" x-cloak class="absolute right-0 top-full z-50 mt-1 w-60 rounded-xl border border-line bg-white p-1.5 shadow-theme-lg">
                            <p class="truncate px-2 py-1.5 text-sm font-semibold text-gray-900">{{ $panelUser?->name }}</p>
                            @foreach ($otherBrands as $other)
                                <form method="POST" action="{{ route('panel.brand') }}">@csrf
                                    <input type="hidden" name="brand_id" value="{{ $other->brand_id }}">
                                    <button type="submit" class="block w-full truncate rounded-lg px-2 py-2 text-left text-sm text-gray-700 hover:bg-soft">{{ $other->brand?->name }} markasına geç</button>
                                </form>
                            @endforeach
                            @foreach ($groups as $entry)
                                @if ($entry['key'] === 'team')
                                    <a href="{{ $entry['url'] }}" wire:navigate class="block rounded-lg px-2 py-2 text-sm text-gray-700 hover:bg-soft">Ekip</a>
                                @endif
                            @endforeach
                            @unless ($viewer)
                                <a href="{{ route('panel.password') }}" class="block rounded-lg px-2 py-2 text-sm text-gray-700 hover:bg-soft">Şifremi değiştir</a>
                            @endunless
                            <form method="POST" action="{{ route('panel.logout') }}">@csrf
                                <button type="submit" class="block w-full rounded-lg px-2 py-2 text-left text-sm text-gray-700 hover:bg-soft">Çıkış yap</button>
                            </form>
                        </div>
                    </div>
                </header>

                @if ($group)
                    <div class="pt-3.5 print:hidden" data-panel-head>
                        <h1 class="text-[23px] font-semibold leading-tight tracking-[-0.02em] text-gray-900 lg:text-[26px]">{{ $group['label'] }}</h1>
                        <p class="mt-1 text-[15px] text-gray-500">{{ $group['key'] === 'home' ? now('Europe/Istanbul')->translatedFormat('j F Y, l') : $group['sub'] }}</p>
                        @if (count($group['tabs']) > 1)
                            <nav class="no-scrollbar -mx-4 mt-4 flex gap-[22px] overflow-x-auto border-b border-line px-4 lg:mx-0 lg:gap-[26px] lg:px-0" aria-label="{{ $group['label'] }}" data-panel-tabs>
                                @foreach ($group['tabs'] as $tab)
                                    <a href="{{ $tab['url'] }}" wire:navigate @if ($tab['active']) aria-current="page" @endif
                                        @class(['-mb-px whitespace-nowrap border-b-2 pb-[11px] pt-2.5 text-[15px]', 'border-primary font-semibold text-primary' => $tab['active'], 'border-transparent font-medium text-gray-500 hover:text-gray-900' => ! $tab['active']])>{{ $tab['label'] }}</a>
                                @endforeach
                            </nav>
                        @endif
                    </div>
                @endif

                <main class="min-w-0 pt-[26px]">
                    @if (session('status'))
                        <p class="mb-5 rounded-lg border border-line bg-soft px-3 py-2 text-sm text-gray-700" role="status">{{ session('status') }}</p>
                    @endif
                    {{ $slot }}
                </main>
            </div>
        </div>

        <nav class="fixed inset-x-0 bottom-0 z-40 grid print:hidden! auto-cols-fr grid-flow-col border-t border-line bg-white px-1 pb-[calc(6px+env(safe-area-inset-bottom,0px))] pt-1.5 lg:hidden" aria-label="Ana menü" data-panel-bottom>
            @foreach ($groups as $entry)
                @continue($entry['key'] === 'team')
                <a href="{{ $entry['url'] }}" wire:navigate @if ($entry['active']) aria-current="page" @endif
                    @class(['flex min-w-0 flex-col items-center gap-[3px] py-1 text-[12px] leading-4', 'font-semibold text-primary' => $entry['active'], 'font-medium text-gray-500' => ! $entry['active']])>
                    {!! $entry['icon'] !!}<span class="max-w-full truncate">{{ $entry['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>

    <div x-data="{ notice: null, timer: null }" x-cloak
        x-on:operator-notice.window="notice = $event.detail; clearTimeout(timer); timer = setTimeout(() => notice = null, notice?.tone === 'error' ? 9000 : 4000)"
        class="pointer-events-none fixed inset-x-4 bottom-20 z-[100000] lg:bottom-4 flex justify-center sm:inset-x-auto sm:right-6">
        <div x-show="notice" role="status" x-on:click="notice = null" x-transition.opacity
            :class="notice?.tone === 'error' ? 'bg-error-600' : 'bg-gray-900'"
            class="pointer-events-auto max-w-md cursor-pointer rounded-xl px-4 py-3 text-sm text-white shadow-theme-lg">
            <span x-text="notice?.message"></span>
        </div>
    </div>
</body>

</html>
