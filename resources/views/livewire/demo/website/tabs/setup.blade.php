@php
    $conn = $data['connections'];
    $settings = $data['settings'];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.tabs.setup') }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Connection, Site Connector, CMS, and Website configuration.</p>
    </div>

    <div class="inline-flex flex-wrap rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist" aria-label="{{ __('operator.website.setup_sections.connection') }}">
        @foreach (['connection' => __('operator.website.setup_sections.connection'), 'configuration' => __('operator.website.setup_sections.configuration')] as $key => $label)
            <button type="button" role="tab" wire:click="setSetupSection('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $setup_section === $key,
                'text-gray-600 dark:text-gray-300' => $setup_section !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($setup_section === 'connection')
        <p class="text-xs text-gray-400">{{ $conn['note'] }}</p>

        <section>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Website data sources</h3>
            <div class="mt-3 grid gap-3 lg:grid-cols-3">
                @foreach ($conn['data_sources'] as $source)
                    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['name'] }}</p>
                            <span class="text-xs text-emerald-600 dark:text-emerald-400">{{ $source['state'] }}</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ $source['detail'] }}</p>
                        <p class="mt-2 text-xs text-gray-400">Last successful collection · {{ $source['last'] }}</p>
                        <p class="mt-2 text-xs text-gray-500">Provides · {{ implode(', ', $source['provides']) }}</p>
                        @if (! empty($source['route']))
                            <a href="{{ route($source['route']) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline">{{ $source['action'] }}</a>
                        @else
                            <p class="mt-3 text-xs text-gray-400">{{ $source['action'] }} · {{ $source['action_note'] ?? '' }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.website.setup_sections.connector') }}</h3>
            <p class="mt-1 text-xs text-gray-400">Site Connectors pair a CMS-managed Website with MoxDOP. This is not a MoxDOP runtime plugin marketplace.</p>
            <div class="mt-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">CMS detected · WordPress</p>
                        <p class="mt-1 text-xs text-gray-500">Recommended: MoxDOP WordPress Connector (Demo package)</p>
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">{{ __('operator.site_connectors.demo_badge') }}</p>
                    </div>
                    <a href="{{ route('demo.integrations.site-connectors') }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ __('operator.nav.site_connectors') }}</a>
                </div>
            </div>
        </section>

        @include('livewire.demo.website.tabs._related-assets', ['conn' => $conn])
    @else
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-gray-400">Website name</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $settings['name'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Domain</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $settings['domain'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Primary URL</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $settings['primary_url'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">CMS</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $settings['cms'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Website type</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $settings['website_type'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Hosting context</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $settings['hosting_context'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Languages</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ implode(' · ', $settings['languages']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Target countries</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ implode(' · ', $settings['target_countries']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Search market</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $settings['search_market']['country'] }} · {{ $settings['search_market']['language'] }}</dd></div>
            </dl>
            <p class="mt-4 text-xs text-gray-400">{{ $settings['brand_context_note'] }}</p>
            <a href="{{ route('demo.brand', ['brand' => \App\Support\Demo\DemoCatalog::BRAND_ID, 'tab' => 'business']) }}" wire:navigate class="mt-2 inline-flex text-xs font-medium text-brand-600 hover:underline">{{ __('operator.brand.business_context_short') }}</a>
        </div>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">GA4 business-action mapping</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($settings['event_mapping'] as $row)
                    <li class="flex justify-between gap-3">
                        <span class="text-gray-800 dark:text-white/90">{{ $row['business_action'] }}</span>
                        <span class="text-xs text-gray-500">{{ $row['ga4_event'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-gray-400">Demo Mode · mapping UI is illustrative. Unmapped actions do not become conversions.</p>
        </section>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('demo.website', ['tab' => 'infrastructure']) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">Open Infrastructure</a>
        </div>
    @endif
</div>
