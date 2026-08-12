<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Agency Command Center</h1>
                @include('livewire.demo.partials.demo-badge')
            </div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                What needs attention across the portfolio — not a CRUD counter.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button href="{{ route('demo.brands') }}" variant="outline" size="sm">Open brands</x-ta.button>
            <x-ta.button href="{{ route('demo.findings') }}" size="sm">Review findings</x-ta.button>
            <x-ta.button wire:click="resetDemo" variant="outline" size="sm">Reset demo</x-ta.button>
        </div>
    </div>

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">Needs attention</h2>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($attention as $item)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <x-ta.badge :color="match($item['severity']) { 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">
                                    {{ ucfirst($item['severity']) }}
                                </x-ta.badge>
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $item['asset'] }}</span>
                            </div>
                            <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $item['issue'] }}</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $item['evidence'] }}</p>
                            <p class="mt-2 text-xs text-gray-400">Why it matters: {{ $item['why'] }} · {{ $item['source'] }}</p>
                        </div>
                        <x-ta.button :href="route($item['route'])" size="sm" variant="outline">Inspect</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    </div>

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">Brand health</h2>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <x-ta.card>
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $brand['name'] }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $brand['health_label'] }}</h3>
                    </div>
                    <x-ta.badge color="warning" size="sm">Needs attention</x-ta.badge>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-gray-400">Media spend</dt>
                        <dd class="font-semibold text-gray-800 dark:text-white/90">₺{{ number_format($brand['summary']['media_spend']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Platform leads</dt>
                        <dd class="font-semibold text-gray-800 dark:text-white/90">{{ number_format($brand['summary']['platform_leads']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Website leads</dt>
                        <dd class="font-semibold text-gray-800 dark:text-white/90">{{ number_format($brand['summary']['website_leads']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Calls / messages</dt>
                        <dd class="font-semibold text-gray-800 dark:text-white/90">{{ number_format($brand['summary']['calls_messages']) }}</dd>
                    </div>
                </dl>
                <div class="mt-4 flex gap-2">
                    <x-ta.button :href="route('demo.brand', ['brand' => $brand['id']])" size="sm">Open brand</x-ta.button>
                    <x-ta.button :href="route('demo.tasks')" size="sm" variant="outline">{{ $brand['open_tasks'] }} open tasks</x-ta.button>
                </div>
            </x-ta.card>

            @foreach (collect($assets)->take(2) as $asset)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $asset['type_label'] }}</p>
                            <h3 class="mt-1 font-semibold text-gray-800 dark:text-white/90">{{ $asset['name'] }}</h3>
                        </div>
                        <x-ta.badge :color="($asset['health'] ?? '') === 'healthy' ? 'success' : (($asset['health'] ?? '') === 'needs_attention' ? 'warning' : 'info')" size="sm">
                            {{ $asset['health_label'] ?? '—' }}
                        </x-ta.badge>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $asset['detail'] ?? ($asset['provenance'] ?? '') }}</p>
                    <div class="mt-4">
                        <x-ta.button :href="route($asset['route'])" size="sm" variant="outline">Open workspace</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ta.card>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Recent operations</h2>
                <x-ta.button href="{{ route('demo.activity') }}" size="sm" variant="outline">Activity</x-ta.button>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach (collect($activity)->take(5) as $row)
                    <li class="flex items-start justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>
                        </div>
                        <div class="text-right">
                            <x-ta.badge :color="match($row['status']) { 'completed' => 'success', 'running' => 'info', 'partial' => 'warning', default => 'light' }" size="sm">
                                {{ $row['status'] }}
                            </x-ta.badge>
                            <p class="mt-1 text-xs text-gray-400">{{ $row['when'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ta.card>

        <x-ta.card>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Upcoming lifecycle</h2>
                <x-ta.button href="{{ route('demo.website', ['tab' => 'lifecycle']) }}" size="sm" variant="outline">Website lifecycle</x-ta.button>
            </div>
            <ul class="space-y-3">
                @foreach (collect($assets)->filter(fn ($a) => in_array($a['type'], ['domain', 'hosting'], true)) as $life)
                    <li class="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.02]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $life['type_label'] }} · {{ $life['name'] }}</p>
                            <p class="text-xs text-gray-500">{{ $life['detail'] ?? $life['health_label'] }}</p>
                        </div>
                        <x-ta.badge :color="($life['health'] ?? '') === 'warning' ? 'warning' : 'success'" size="sm">
                            {{ $life['health_label'] }}
                        </x-ta.badge>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">
                <h3 class="mb-2 text-sm font-medium text-gray-600 dark:text-gray-300">Open tasks</h3>
                <ul class="space-y-2">
                    @foreach (collect($tasks)->where('status', '!=', 'completed')->take(3) as $task)
                        <li>
                            <a href="{{ route('demo.task', ['taskId' => $task['id']]) }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                {{ $task['title'] }}
                            </a>
                            <span class="text-xs text-gray-400"> · due {{ $task['due'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-ta.card>
    </div>
</div>
