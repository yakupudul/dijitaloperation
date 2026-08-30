<div class="space-y-6">
    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.integrations'),
        'title' => __('operator.site_connectors.title'),
        'subtitle' => 'Site içine kurulan, salt-okunur ve imzalı veri bağlayıcıları.',
    ])

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="Site connector catalog">
        <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center gap-3">
                <x-demo.digital-asset-mark type="website" size="md" />
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">WordPress Connector</h2>
                    <x-ta.badge color="success" size="sm">v{{ config('moxdop-wordpress.connector_version') }}</x-ta.badge>
                </div>
            </div>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">CMS içi içerik, medya metadata’sı, taksonomi, eklenti, tema ve SEO alanlarını read-only toplar.</p>
            <p class="mt-2 text-xs text-gray-400">{{ $paired }} etkin eşleştirme</p>
            <div class="mt-4">
                <x-ta.button :href="route('operator.integrations.site-connector', ['connector' => 'wordpress'])" size="sm">{{ __('operator.actions.open') }}</x-ta.button>
            </div>
        </article>
    </section>
</div>

