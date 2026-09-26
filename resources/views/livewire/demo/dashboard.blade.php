<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $dashboard['date_label'] }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $dashboard['greeting'] }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $dashboard['subtitle'] }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @include('livewire.demo.partials._dashboard-actions')
        </div>
    </div>

    <livewire:operator.assistant.today-panel />

    <section aria-labelledby="today-heading">
        <h2 id="today-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.today') }}</h2>
        <div class="mt-3 grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach ($dashboard['today'] as $metric)
                <a href="{{ route($metric['route'], $metric['route_params'] ?? []) }}" wire:navigate
                    class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $metric['label'] }}</p>
                    <p @class([
                        'mt-2 text-3xl font-bold',
                        'text-error-600 dark:text-error-400' => ($metric['tone'] ?? '') === 'error',
                        'text-warning-600 dark:text-warning-400' => ($metric['tone'] ?? '') === 'warning',
                        'text-brand-600 dark:text-brand-400' => ($metric['tone'] ?? '') === 'info',
                        'text-gray-800 dark:text-white/90' => ! in_array($metric['tone'] ?? '', ['error', 'warning', 'info'], true),
                    ])>{{ $metric['value'] }}</p>
                </a>
            @endforeach
        </div>
    </section>

    @if (($systemAlerts['critical'] ?? 0) + ($systemAlerts['warning'] ?? 0) > 0)
        <a href="{{ route('operator.settings.system-health') }}" wire:navigate @class(['flex flex-wrap items-center justify-between gap-2 rounded-xl px-4 py-3 text-sm ring-1 ring-inset', 'bg-rose-50 text-rose-800 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' => $systemAlerts['critical'] > 0, 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' => $systemAlerts['critical'] === 0])>
            <span><strong>Sistem uyarısı:</strong> @if ($systemAlerts['critical'] > 0){{ $systemAlerts['critical'] }} kritik @endif @if ($systemAlerts['warning'] > 0){{ $systemAlerts['warning'] }} uyarı @endif @if ($systemAlerts['top']) · {{ $systemAlerts['top'] }}@endif</span>
            <span class="font-medium underline">Sistem Sağlığı</span>
        </a>
    @endif

    @if ($alerts->isNotEmpty())
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-rose-200 dark:bg-gray-900 dark:ring-rose-500/30" aria-labelledby="alerts-heading">
            <div class="flex items-center justify-between gap-2">
                <h2 id="alerts-heading" class="text-sm font-semibold uppercase tracking-wide text-rose-600 dark:text-rose-400">{{ __('operator_asset.alerts_title') }} ({{ $alerts->count() }})</h2>
                <a href="{{ route('operator.alerts') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Tüm uyarılar</a>
            </div>
            <ul class="mt-3 space-y-2">
                @foreach ($alerts as $alert)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $alert->title }}</p>
                            <p class="text-xs text-gray-500">{{ $alert->brand?->name ?? '—' }} · {{ $alert->digitalAsset?->name ?? '—' }} · {{ $alert->message }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ta.badge :color="$alert->severityColor()" size="sm">{{ $alert->severityLabel() }}</x-ta.badge>
                            @if ($alert->digitalAsset)<a href="{{ \App\Services\Operator\OperatorPortfolioPresenter::specialistUrl($alert->digitalAsset) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="weekly-top-heading">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="weekly-top-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.weekly_top') }}</h2>
            <div class="flex gap-3">
                <a href="{{ route('operator.command-center') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Komuta merkezi ({{ $commandSummary['total'] }})</a>
                <a href="{{ route('operator.portfolio.health') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Portföy sağlığı</a>
            </div>
        </div>
        @if ($commandSummary['total'] > 0)
            <p class="mt-1 text-xs text-gray-500">{{ $commandSummary['critical'] }} kritik/yüksek iş · risk altında ~{{ number_format($commandSummary['money'], 0, ',', '.') }} TL · kazanılabilecek +{{ number_format($commandSummary['clicks'], 0, ',', '.') }} tık</p>
        @endif
        @if ($weeklyTop === [])
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.weekly_top_empty') }}</p>
        @else
            <ol class="mt-3 space-y-2">
                @foreach ($weeklyTop as $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-white text-xs font-semibold text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">{{ $loop->iteration }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                                <p class="text-xs text-gray-500">{{ $row['brand'] ?? '—' }} · {{ $row['source_label'] }}@if ($row['channel']) · {{ $row['channel'] }}@endif @if ($row['impact']) · {{ $row['impact'] }}@endif</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ta.badge :color="in_array($row['severity'], ['critical', 'high'], true) ? 'error' : ($row['severity'] === 'medium' ? 'warning' : 'light')" size="sm">{{ ['critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük'][$row['severity']] ?? $row['severity'] }}</x-ta.badge>
                            @if ($row['url'])<a href="{{ $row['url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

</div>
