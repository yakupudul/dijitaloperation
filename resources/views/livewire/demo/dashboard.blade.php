<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.dashboard_exec.today') }}</h1>

    @if (($systemAlerts['critical'] ?? 0) + ($systemAlerts['warning'] ?? 0) > 0)
        <a href="{{ route('operator.settings.system-health') }}" wire:navigate @class(['flex flex-wrap items-center justify-between gap-2 rounded-xl px-4 py-3 text-sm ring-1 ring-inset', 'bg-rose-50 text-rose-800 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' => $systemAlerts['critical'] > 0, 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' => $systemAlerts['critical'] === 0])>
            <span><strong>Sistem uyarısı:</strong> @if ($systemAlerts['critical'] > 0){{ $systemAlerts['critical'] }} kritik @endif @if ($systemAlerts['warning'] > 0){{ $systemAlerts['warning'] }} uyarı @endif @if ($systemAlerts['top']) · {{ $systemAlerts['top'] }}@endif</span>
            <span class="font-medium underline">Sistem Sağlığı</span>
        </a>
    @endif

    @if ($rows === [])
        <p class="text-sm text-gray-500">Hizmet verilen marka yok.</p>
    @else
        <ul class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-800 dark:bg-gray-900 dark:ring-gray-800" data-portfolio-today>
            @foreach ($rows as $row)
                <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="today-{{ $row['id'] }}">
                    <div class="min-w-0">
                        <a href="{{ route('operator.brand', ['brand' => $row['id']]) }}" wire:navigate class="text-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $row['name'] }}</a>
                        @if ($row['top'])
                            <p class="truncate text-sm text-gray-600 dark:text-gray-400">{{ $row['top']['title'] }} <span class="text-gray-400">· {{ $row['top']['why'] }}</span></p>
                        @else
                            <p class="text-sm text-gray-400">Açık iş yok.</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-1.5 text-xs">
                        @foreach ($channels as $channel => $label)
                            <span @class(['rounded-md px-2 py-0.5', 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => ($row['counts'][$channel] ?? 0) > 0, 'bg-gray-50 text-gray-400 dark:bg-white/[0.03]' => ($row['counts'][$channel] ?? 0) === 0])>{{ $label }} {{ $row['counts'][$channel] ?? 0 }}</span>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
