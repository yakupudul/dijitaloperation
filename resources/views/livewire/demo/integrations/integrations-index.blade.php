@php
    // Panel concept 2026-10-20: the hub answers "which connection is broken and what do I click to fix it?".
    // What needs a fix comes first (one row, one button each), then every connection as a row with a status dot and
    // one button, then the social profiles discovery found.
    $attentionStates = ['needs_attention', 'authorization_expired', 'configuration_incomplete'];
    $mainProviderId = collect($groups)->flatMap(fn (array $g): array => $g['providers'])->first(fn (array $p): bool => in_array($p['state'] ?? '', $attentionStates, true))['id'] ?? null;
    // Read models label some states in English ("Not configured", "Authorized"); shown in the operator's language.
    $englishStates = ['Not configured' => 'not_configured', 'Authorized' => 'authorized', 'Configured' => 'configured', 'Connected' => 'connected', 'Needs attention' => 'needs_attention', 'Disabled' => 'disabled'];
    $stateText = fn (array $p): string => isset($englishStates[$p['state_label'] ?? '']) ? __('operator.states.'.$englishStates[$p['state_label']]) : (string) ($p['state_label'] ?? '');
    $stateTone = fn (array $p): string => match ($p['state'] ?? '') {
        'connected', 'configured' => 'ok',
        'needs_attention', 'authorization_expired', 'configuration_incomplete' => 'look',
        default => 'info',
    };
    $connectionCount = collect($groups)->sum(fn (array $g): int => count($g['providers']));
    $errorCount = count(array_filter($problems, fn (array $p): bool => $p['tone'] === 'error'));
    $icons = [
        'alert' => '<path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
    ];
@endphp
<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">{{ __('operator.integrations_ui.title') }}</h1>
            <p class="page-sub">
                @if ($problems === [])
                    {{ $connectionCount }} bağlantı, düzeltilecek bir şey yok.
                @else
                    {{ count($problems) }} bağlantı sorunu{{ $errorCount > 0 ? ', '.$errorCount.' tanesi acil' : '' }}.
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('operator.integrations.discovered') }}" wire:navigate class="btn">Keşfedilen varlıklar</a>
            <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="btn-quiet">Sistem sağlığı</a>
        </div>
    </header>

    {{-- 1. What needs a fix: one row each, one button each. --}}
    <section aria-labelledby="hub-health">
        <div class="section-head">
            <h2 id="hub-health" class="section-title">Bağlantı sağlığı @if ($problems !== [])<span class="section-count">{{ count($problems) }}</span>@endif</h2>
        </div>
        <ul class="card">
            @forelse ($problems as $problem)
                <li class="card-row flex-wrap sm:flex-nowrap">
                    <span @class(['row-icon border-transparent', 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400' => $problem['tone'] === 'error', 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $problem['tone'] !== 'error']) aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $problem['tone'] === 'error' ? $icons['alert'] : $icons['clock'] !!}</svg>
                    </span>
                    <p class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-white">{{ $problem['text'] }}</p>
                    <a href="{{ $problem['url'] }}" wire:navigate class="{{ $loop->first ? 'btn-primary' : 'btn' }} w-full sm:w-auto">{{ $problem['action'] }}</a>
                </li>
            @empty
                <li class="card-row" title="Yetkiler geçerli, hesaplar güncel, WordPress siteleri sinyal gönderiyor.">
                    <span class="row-icon border-transparent bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icons['check'] !!}</svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Sorun yok</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">İşlem gerektiren bağlantı yok.</p>
                    </div>
                </li>
            @endforelse
        </ul>
    </section>

    {{-- 2. Every connection: logo, name, status dot, one gray line, one button. Groups are thin label strips. --}}
    <section aria-labelledby="hub-connections">
        <div class="section-head">
            <h2 id="hub-connections" class="section-title">Bağlantılar <span class="section-count">{{ $connectionCount }}</span></h2>
        </div>
        <div class="card">
            @foreach ($groups as $group)
                @php $groupSlug = \Illuminate\Support\Str::slug($group['group']); @endphp
                <div @if (($group['id'] ?? '') === 'site_connectors') id="site_connectors" @endif aria-labelledby="group-{{ $groupSlug }}" role="group"
                    @class(['scroll-mt-20', 'border-t border-gray-200 dark:border-gray-800' => ! $loop->first])>
                    <div class="flex items-center justify-between gap-3 bg-gray-50 px-4 py-2 dark:bg-white/[0.02]">
                        <h3 id="group-{{ $groupSlug }}" class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $group['group'] }}</h3>
                        @if (($group['id'] ?? '') === 'site_connectors')
                            <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress']) }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white">{{ __('operator.site_connectors.catalog') }}</a>
                        @endif
                    </div>
                    @foreach ($group['providers'] as $provider)
                        @php
                            $lastCheck = (string) ($provider['last_check'] ?? '—');
                            $dependent = (int) ($provider['dependent_assets'] ?? 0);
                            $meta = collect([
                                $lastCheck !== '—' && $lastCheck !== '' ? __('operator.integrations_ui.last_check').' '.$lastCheck : null,
                                $dependent > 0 ? __('operator.integrations_ui.dependent_assets', ['count' => $dependent]) : null,
                            ])->filter()->implode(', ');
                            $isMain = $mainProviderId !== null && ($provider['id'] ?? null) === $mainProviderId;
                        @endphp
                        <article class="card-row flex-wrap items-start sm:flex-nowrap sm:items-center" wire:key="hub-provider-{{ $provider['id'] ?? $loop->index }}">
                            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => ($provider['id'] ?? '') === 'website' ? 'website' : ($provider['id'] ?? $provider['logo_type'] ?? 'website')])
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ $provider['name'] }}</h4>
                                    <x-ta.pill :tone="$stateTone($provider)">{{ $stateText($provider) }}</x-ta.pill>
                                </div>
                                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                                    @if ($provider['resources_discovered'] !== null && ! ($provider['discovery_not_run'] ?? false))
                                        <span class="tabular-nums">{{ $provider['resources_discovered'] }}</span> {{ mb_strtolower(__('operator.integrations_ui.discovered')) }},
                                        <span class="tabular-nums">{{ $provider['bound'] }}</span> {{ mb_strtolower(__('operator.integrations_ui.bound')) }},
                                        <a href="{{ route('operator.integrations.discovered', array_filter(['bound' => 'no', 'kind' => ($provider['id'] ?? '') === 'meta' ? 'meta_ads' : null])) }}" wire:navigate class="text-gray-700 underline decoration-gray-300 underline-offset-2 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" title="Markasız hesapları gör"><span class="tabular-nums">{{ $provider['available'] }}</span> {{ mb_strtolower(__('operator.integrations_ui.available')) }}</a>@if ($meta !== '').@endif
                                    @elseif ($provider['resources_discovered'] !== null && ($provider['discovery_not_run'] ?? false))
                                        {{ __('operator.states.not_discovered') }}@if ($meta !== '').@endif
                                    @elseif (($provider['note'] ?? '') === __('operator.integrations_ui.not_configured_note'))
                                        <span title="{{ $provider['note'] }}">Canlı veri için önce kimlik bilgilerini kaydet.</span>
                                    @elseif (! empty($provider['note']))
                                        <span title="{{ $provider['note'] }}">{{ \Illuminate\Support\Str::limit($provider['note'], 110) }}</span>
                                    @endif
                                    @if ($meta !== '')<span class="text-gray-400 dark:text-gray-500">{{ $meta }}</span>@endif
                                </p>
                            </div>
                            <a href="{{ route($provider['route'], $provider['route_params'] ?? []) }}" wire:navigate class="{{ $isMain ? 'btn-primary' : 'btn' }} w-full sm:w-auto">
                                {{ $provider['manage_label'] ?? __('operator.integrations_ui.manage') }}
                            </a>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>

    {{-- 3. Social profiles discovery found and a human accepted; binding happens on the provider's page. --}}
    <section id="discovered-profiles" class="scroll-mt-20">
        <div class="section-head items-center">
            <h2 class="section-title" title="{{ __('public_discovery.profiles_help') }}">{{ __('public_discovery.profiles') }} <span class="section-count">{{ $discoveredProfiles->total() }}</span></h2>
            <div class="ml-auto flex w-full flex-wrap items-center gap-3 sm:w-auto">
                @if($discoveryBrand)<a href="{{ route('operator.integrations') }}#discovered-profiles" wire:navigate class="link-neutral text-[13px]">{{ __('public_discovery.show_all_brands') }}</a>@endif
                <input type="search" wire:model.live.debounce.300ms="profileSearch" aria-label="{{ __('public_discovery.profile_search') }}" placeholder="{{ __('public_discovery.profile_search') }}" class="h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm sm:w-64 dark:border-gray-700 dark:bg-gray-900" />
            </div>
        </div>
        <ul class="card">
            @forelse($discoveredProfiles as $profile)
                @php $platform = (string) data_get($profile->support_json, 'application.platform'); @endphp
                <li class="card-row flex-wrap sm:flex-nowrap" wire:key="profile-{{ $profile->id }}">
                    <x-ta.brand-avatar :name="$profile->brand?->name ?? ''" :logo="$profile->brand?->logo_url" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $profile->brand?->name }} <span class="font-normal text-gray-500">{{ $platform }}</span></p>
                        <p class="mt-0.5 break-all text-[13px] text-gray-500 dark:text-gray-400">{{ data_get($profile->support_json, 'application.url') }}</p>
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ __('public_discovery.binding_needed') }}, {{ $profile->reviewed_at?->format('d.m.Y H:i') }}</p>
                    </div>
                    <div class="flex w-full flex-wrap items-center gap-1 sm:w-auto sm:flex-nowrap">
                        <a href="{{ data_get($profile->support_json, 'application.url') }}" target="_blank" rel="noopener noreferrer" class="btn-quiet">{{ __('public_discovery.open_profile') }}</a>
                        <a href="{{ route('operator.website.discovery', ['assetId' => $profile->digital_asset_id]) }}" wire:navigate class="btn-quiet">{{ __('public_discovery.from_discovery') }}</a>
                        @if(in_array($platform, ['instagram', 'facebook'], true))
                            <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="btn">Meta</a>
                        @endif
                    </div>
                </li>
            @empty
                <li><x-ta.empty-state size="sm" :title="__('public_discovery.profiles_empty')" /></li>
            @endforelse
        </ul>
        <div class="mt-3">{{ $discoveredProfiles->links() }}</div>
    </section>
</div>
