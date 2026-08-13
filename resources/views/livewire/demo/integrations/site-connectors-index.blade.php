<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.integrations'),
        'title' => __('operator.site_connectors.title'),
        'subtitle' => __('operator.site_connectors.subtitle'),
    ])

    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        {{ __('operator.site_connectors.demo_badge') }}
    </div>

    <section class="space-y-3" aria-labelledby="site-connector-catalog">
        <h2 id="site-connector-catalog" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('operator.site_connectors.catalog') }}
        </h2>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($connectors as $connector)
                <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex items-center gap-3">
                        <x-demo.digital-asset-mark :type="$connector['logo_type'] ?? 'website'" size="md" />
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $connector['name'] }}</h3>
                            <x-ta.badge color="warning" size="sm">{{ $connector['status_label'] }}</x-ta.badge>
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ $connector['summary'] }}</p>
                    <p class="mt-2 text-xs text-gray-400">{{ count($connector['connected_sites'] ?? []) }} connected sites · {{ count($connector['releases'] ?? []) }} release(s)</p>
                    <div class="mt-4">
                        <x-ta.button :href="route('demo.integrations.site-connector', ['connector' => $connector['id']])" size="sm">
                            {{ __('operator.actions.open') }}
                        </x-ta.button>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <div>
        <a href="{{ route('demo.integrations') }}" wire:navigate class="text-sm font-medium text-brand-600 dark:text-brand-400">← {{ __('operator.nav.integrations') }}</a>
    </div>
</div>
