@php
    // Orange only for the screen's one main action: the first card whose connection needs a fix; every other card
    // button is outline (2026-10-19: five filled orange buttons).
    $attentionStates = ['needs_attention', 'authorization_expired', 'configuration_incomplete'];
    $mainProviderId = collect($groups)->flatMap(fn (array $g): array => $g['providers'])->first(fn (array $p): bool => in_array($p['state'] ?? '', $attentionStates, true))['id'] ?? null;
@endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.integrations_ui.system'),
        'title' => __('operator.integrations_ui.title'),
        'subtitle' => __('operator.integrations_ui.subtitle'),
    ])

    <section class="surface p-5" aria-labelledby="hub-health">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="hub-health" class="text-sm font-semibold text-gray-800 dark:text-white/90">Bağlantı sağlığı</h2>
            <span class="flex flex-wrap items-center gap-3">
                <a href="{{ route('operator.integrations.discovered') }}" wire:navigate class="link-neutral text-xs">Keşfedilen varlıklar →</a>
                <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="link-neutral text-xs">Hesap bazında tablo: Sistem Sağlığı →</a>
            </span>
        </div>
        @if ($problems === [])
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400"><x-ta.pill tone="ok" class="mr-1.5">Sorun yok</x-ta.pill>İşlem gerektiren bağlantı yok: yetkiler geçerli, hesaplar güncel, WordPress siteleri sinyal gönderiyor.</p>
        @else
            <ul class="mt-2 space-y-1.5">
                @foreach ($problems as $problem)
                    <li @class(['flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm', 'tone-critical' => $problem['tone'] === 'error', 'tone-look' => $problem['tone'] !== 'error'])>
                        <span class="min-w-0 break-words">{{ $problem['text'] }}</span>
                        <a href="{{ $problem['url'] }}" wire:navigate class="font-medium underline">{{ $problem['action'] }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>


    <section id="site_connectors" class="surface p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator.site_connectors.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.site_connectors.subtitle') }}</p>
            </div>
            <x-ta.button :href="route('operator.integrations.site-connector', ['connector' => 'wordpress'])" size="sm" variant="outline">
                {{ __('operator.site_connectors.catalog') }}
            </x-ta.button>
        </div>
    </section>

    @foreach ($groups as $group)
        <section class="space-y-3" aria-labelledby="group-{{ \Illuminate\Support\Str::slug($group['group']) }}">
            <h2 id="group-{{ \Illuminate\Support\Str::slug($group['group']) }}" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $group['group'] }}
            </h2>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($group['providers'] as $provider)
                    @php
                        $statusColor = match ($provider['state']) {
                            'connected' => 'success',
                            'configured' => 'info',
                            'needs_attention', 'authorization_expired', 'configuration_incomplete' => 'warning',
                            'not_connected', 'not_configured', 'provider_unavailable' => 'light',
                            default => 'info',
                        };
                    @endphp
                    <article class="surface min-w-0 p-5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-demo.digital-asset-mark :type="$provider['logo_type'] ?? 'website'" size="md" />
                                <div class="min-w-0">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $provider['name'] }}</h3>
                                    {{-- The read models label a connected Google / Meta "Authorized"; shown in the operator's language. --}}
                                    <x-ta.badge :color="$statusColor" size="sm">{{ $provider['state_label'] === 'Authorized' ? __('operator.states.authorized') : $provider['state_label'] }}</x-ta.badge>
                                </div>
                            </div>
                        </div>

                        @if ($provider['resources_discovered'] !== null && ! ($provider['discovery_not_run'] ?? false))
                            <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
                                <div class="rounded-lg bg-gray-50 px-2 py-2 dark:bg-white/[0.03]">
                                    <dt class="text-xs text-gray-400">{{ __('operator.integrations_ui.discovered') }}</dt>
                                    <dd class="font-semibold tabular-nums text-gray-800 dark:text-white/90">{{ $provider['resources_discovered'] }}</dd>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-2 py-2 dark:bg-white/[0.03]">
                                    <dt class="text-xs text-gray-400">{{ __('operator.integrations_ui.bound') }}</dt>
                                    <dd class="font-semibold tabular-nums text-gray-800 dark:text-white/90">{{ $provider['bound'] }}</dd>
                                </div>
                                <a href="{{ route('operator.integrations.discovered', array_filter(['bound' => 'no', 'kind' => ($provider['id'] ?? '') === 'meta' ? 'meta_ads' : null])) }}" wire:navigate class="rounded-lg bg-gray-50 px-2 py-2 hover:bg-gray-100 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]" title="Markasız hesapları gör">
                                    <dt class="text-xs text-gray-400">{{ __('operator.integrations_ui.available') }}</dt>
                                    <dd class="font-semibold tabular-nums text-gray-900 underline decoration-gray-300 underline-offset-2 dark:text-white">{{ $provider['available'] }}</dd>
                                </a>
                            </dl>
                        @elseif ($provider['resources_discovered'] !== null && ($provider['discovery_not_run'] ?? false))
                            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.states.not_discovered') }}</p>
                        @elseif (! empty($provider['note']))
                            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ $provider['note'] }}</p>
                        @endif

                        <p class="mt-3 text-xs text-gray-500">{{ __('operator.integrations_ui.last_check') }} · {{ $provider['last_check'] }}</p>
                        @if (($provider['dependent_assets'] ?? 0) > 0)
                            <p class="mt-1 text-xs text-gray-500">{{ __('operator.integrations_ui.dependent_assets', ['count' => $provider['dependent_assets']]) }}</p>
                        @endif

                        <div class="mt-4">
                            <x-ta.button :href="route($provider['route'], $provider['route_params'] ?? [])" size="sm" :variant="$mainProviderId !== null && ($provider['id'] ?? null) === $mainProviderId ? 'primary' : 'outline'">
                                {{ $provider['manage_label'] ?? __('operator.integrations_ui.manage') }}
                            </x-ta.button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    <section id="discovered-profiles" class="surface space-y-4 p-5">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('public_discovery.profiles') }}</h2>
        <p class="text-sm text-gray-500">{{ __('public_discovery.profiles_help') }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <input type="search" wire:model.live.debounce.300ms="profileSearch" aria-label="{{ __('public_discovery.profile_search') }}" placeholder="{{ __('public_discovery.profile_search') }}" class="w-full min-w-0 rounded-lg border-gray-300 bg-transparent text-sm sm:w-64" />
            @if($discoveryBrand)<a href="{{ route('operator.integrations') }}#discovered-profiles" wire:navigate class="link-neutral text-sm">{{ __('public_discovery.show_all_brands') }}</a>@endif
        </div>
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            @forelse($discoveredProfiles as $profile)
                <article class="flex flex-wrap justify-between gap-3 py-3" wire:key="profile-{{ $profile->id }}">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $profile->brand?->name }} · {{ data_get($profile->support_json, 'application.platform') }}</p>
                        <p class="mt-1 break-all text-sm text-gray-500">{{ data_get($profile->support_json, 'application.url') }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ __('public_discovery.binding_needed') }} · {{ $profile->reviewed_at?->format('Y-m-d H:i') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm [&_a]:font-medium [&_a]:text-gray-600 [&_a:hover]:text-gray-900 [&_a:hover]:underline dark:[&_a]:text-gray-400">
                        <a href="{{ data_get($profile->support_json, 'application.url') }}" target="_blank" rel="noopener noreferrer">{{ __('public_discovery.open_profile') }}</a>
                        <a href="{{ route('operator.website.discovery', ['assetId' => $profile->digital_asset_id]) }}" wire:navigate>{{ __('public_discovery.from_discovery') }}</a>
                        @if(in_array(data_get($profile->support_json, 'application.platform'), ['instagram', 'facebook'], true))
                            <a href="{{ route('operator.integrations.meta') }}" wire:navigate>Meta →</a>
                        @endif
                    </div>
                </article>
            @empty
                <p class="py-3 text-sm text-gray-500">{{ __('public_discovery.profiles_empty') }}</p>
            @endforelse
        </div>
        {{ $discoveredProfiles->links() }}
    </section>
</div>
