<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'intent-radar'])

    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.nav.groups.sales') }}</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.intent_radar') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('operator.sales_intent.radar_subtitle') }}</p>
    </div>

    <section @class([
        'rounded-xl p-5 ring-1 ring-inset',
        'bg-emerald-50 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/20' => $engine['ready'],
        'bg-amber-50 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20' => ! $engine['ready'],
    ])>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Sales Intent Engine</h2>
                    <span @class([
                        'rounded-full px-2.5 py-1 text-xs font-semibold',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' => $engine['ready'],
                        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => ! $engine['ready'],
                    ])>{{ $engine['ready'] ? 'LIVE' : strtoupper($engine['reality']) }}</span>
                </div>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Provider: {{ $engine['provider'] }} · {{ $engine['endpoint'] }}</p>
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                    <span>Credentials: <strong>{{ $engine['configured'] ? 'configured' : 'missing' }}</strong></span>
                    <span>Paid live calls: <strong>{{ $engine['paid_calls_enabled'] ? 'enabled' : 'disabled' }}</strong></span>
                    <span>Fixtures: <strong>{{ $engine['fixtures_enabled'] ? 'ON' : 'OFF' }}</strong></span>
                </div>
                @if (! $engine['ready'])
                    <p class="mt-3 text-sm font-medium text-amber-800 dark:text-amber-300">{{ $engine['message'] ?: 'Live intent discovery is not ready.' }}</p>
                    @if (! $engine['paid_calls_enabled'] && ! $engine['fixtures_enabled'])
                        <p class="mt-1 text-xs text-amber-700/80 dark:text-amber-300/80">Staging runtime must enable <code>MOXDOP_SALES_INTENT_PAID_CALLS=true</code> before real searches can run. Each run still requires explicit paid-call consent.</p>
                    @endif
                @else
                    <p class="mt-3 text-sm text-emerald-800 dark:text-emerald-300">The engine is ready for real, operator-triggered discovery. Paid requests are never triggered by simply opening this page.</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('operator.search-profiles') }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Search Profiles</a>
                <a href="{{ route('operator.integrations.dataforseo') }}" wire:navigate class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">DataForSEO</a>
            </div>
        </div>
    </section>

    <select wire:model.live="status" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" aria-label="{{ __('operator.forms.status') }}">
        @foreach ($statusOptions as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>

    @if ($rows === [])
        <x-ta.card>
            <p class="text-sm text-gray-600">{{ __('operator.sales_intent.radar_empty') }}</p>
            @if ($engine['ready'])
                <p class="mt-2 text-xs text-gray-500">Create/open a Search Profile and run a real search to populate the radar.</p>
            @endif
        </x-ta.card>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr class="text-xs uppercase text-gray-400">
                        <th class="px-4 py-3">{{ __('operator.sales_intent.fields.source') }}</th>
                        <th class="px-4 py-3">{{ __('operator.sales_intent.fields.snippet') }}</th>
                        <th class="px-4 py-3">{{ __('operator.sales_intent.fields.intent_confidence') }}</th>
                        <th class="px-4 py-3">{{ __('operator.sales_intent.fields.identity_confidence') }}</th>
                        <th class="px-4 py-3">{{ __('operator.forms.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-700" data-testid="intent-signal-row" data-purchase-stage="{{ $row['purchase_stage'] }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('operator.intent-signal', ['signalId' => $row['id']]) }}" class="font-medium text-brand-700 hover:underline">{{ $row['source_title'] }}</a>
                                <div class="text-xs text-gray-500">{{ $row['service'] }} · {{ $row['discovered_at'] }}</div>
                                <a href="{{ route('operator.intent-signal', ['signalId' => $row['id']]) }}" class="mt-1 inline-block text-xs text-brand-700 hover:underline">{{ __('operator.sales_intent.inspect') }}</a>
                            </td>
                            <td class="max-w-xs px-4 py-3 text-gray-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit($row['snippet'], 120) }}</td>
                            <td class="px-4 py-3">{{ $row['intent_confidence'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $row['identity_confidence'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $row['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
