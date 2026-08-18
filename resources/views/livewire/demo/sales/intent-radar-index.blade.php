<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'intent-radar'])

    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.nav.groups.sales') }}</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.intent_radar') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('operator.sales_intent.radar_subtitle') }}</p>
    </div>

    <select wire:model.live="status" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" aria-label="{{ __('operator.forms.status') }}">
        @foreach ($statusOptions as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>

    @if ($rows === [])
        <x-ta.card>
            <p class="text-sm text-gray-600">{{ __('operator.sales_intent.radar_empty') }}</p>
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
