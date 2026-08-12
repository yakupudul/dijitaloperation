<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Activity</h1>
            <p class="mt-1 text-sm text-gray-500">Imports, scans, and analysis jobs (Demo Mode).</p>
        </div>
        <x-ta.button href="{{ route('demo.integrations.meta') }}" size="sm">Meta import</x-ta.button>
    </div>

    <div class="space-y-3">
        @foreach ($activity as $row)
            <x-ta.card>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $row['title'] }}</h3>
                        <p class="text-sm text-gray-500">{{ $row['detail'] }}</p>
                    </div>
                    <div class="text-right">
                        <x-ta.badge :color="match($row['status']) { 'completed' => 'success', 'running' => 'info', 'partial' => 'warning', 'queued' => 'light', default => 'light' }" size="sm">{{ $row['status'] }}</x-ta.badge>
                        <p class="mt-1 text-xs text-gray-400">{{ $row['when'] }}</p>
                    </div>
                </div>
            </x-ta.card>
        @endforeach
    </div>
</div>