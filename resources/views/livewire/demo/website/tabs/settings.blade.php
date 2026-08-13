@php $settings = $data['settings']; @endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Settings</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Website configuration and market context.</p>
    </div>

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
        <a href="{{ route('demo.brand', ['brand' => \App\Support\Demo\DemoCatalog::BRAND_ID, 'tab' => 'context']) }}" wire:navigate class="mt-2 inline-flex text-xs font-medium text-brand-600 hover:underline">Open Brand Business Context</a>
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
        <a href="{{ route('demo.domain') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Legacy Domain view</a>
        <a href="{{ route('demo.hosting') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Legacy Hosting view</a>
    </div>
</div>
