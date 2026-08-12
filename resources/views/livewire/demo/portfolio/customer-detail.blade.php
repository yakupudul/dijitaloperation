<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.customers') }}" size="sm" variant="outline">← Customers</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $customer['name'] }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $customer['industry'] }} · {{ $customer['hq'] }}</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <x-ta.metric-card label="Brands" :value="$customer['brands_count'] ?? count($brands)" />
        <x-ta.metric-card label="Open issues" :value="$customer['open_issues'] ?? 0" />
        <x-ta.metric-card label="Open tasks" :value="$customer['open_tasks'] ?? 0" />
    </div>

    <x-ta.card>
        <h2 class="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Brands</h2>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($brands as $brand)
                <li class="flex items-center justify-between py-3">
                    <div>
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $brand['name'] }}</p>
                        <p class="text-sm text-gray-500">{{ $brand['location'] ?? '' }} · {{ $brand['health_label'] ?? '' }}</p>
                    </div>
                    <x-ta.button :href="route('demo.brand', ['brand' => $brand['id']])" size="sm">Open brand</x-ta.button>
                </li>
            @endforeach
        </ul>
    </x-ta.card>
</div>
