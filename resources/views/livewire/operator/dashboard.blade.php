<div>
    <x-ta.page-breadcrumb pageTitle="Dashboard" />

    <div class="mb-6">
        <h1 class="text-title-sm font-bold text-gray-800 dark:text-white/90">
            Welcome back, {{ auth()->user()->name }}
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            MoxDOP Agency Operations OS — internal operator workspace.
        </p>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ta.metric-card
            label="Customers"
            :value="number_format($customerCount)"
            :icon="'<svg class=\'w-5 h-5\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.8\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\'/><circle cx=\'9\' cy=\'7\' r=\'4\'/></svg>'"
        />
        <x-ta.metric-card label="Brands" :value="number_format($brandCount)" />
        <x-ta.metric-card label="Digital Assets" :value="number_format($assetCount)" />
        <x-ta.metric-card label="Meta Ad Assets" :value="number_format($metaAssetCount)" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ta.section-card title="Portfolio" subtitle="Manage customers, brands and their digital assets.">
            <div class="flex flex-col gap-3">
                <a href="{{ route('operator.customers') }}" class="flex items-center justify-between rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Customers</span>
                    <span class="text-sm text-gray-400">{{ number_format($customerCount) }} &rarr;</span>
                </a>
                <a href="{{ route('operator.brands') }}" class="flex items-center justify-between rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Brands</span>
                    <span class="text-sm text-gray-400">{{ number_format($brandCount) }} &rarr;</span>
                </a>
                <a href="{{ route('operator.digital-assets') }}" class="flex items-center justify-between rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Digital Assets</span>
                    <span class="text-sm text-gray-400">{{ number_format($assetCount) }} &rarr;</span>
                </a>
            </div>
        </x-ta.section-card>

        <x-ta.section-card title="Meta Ads" subtitle="Connection, history import and performance workspace.">
            <div class="flex flex-col gap-3">
                <a href="{{ route('operator.meta') }}" class="flex items-center justify-between rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Meta Integration &amp; Import</span>
                    <span class="text-sm text-gray-400">&rarr;</span>
                </a>
                <a href="/admin/runs" class="flex items-center justify-between rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Activity (back-office)</span>
                    <span class="text-sm text-gray-400">&rarr;</span>
                </a>
            </div>
        </x-ta.section-card>
    </div>
</div>
