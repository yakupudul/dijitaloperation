@php
    $reports = $detail['reports'] ?? ['internal_live' => [], 'client_live' => [], 'snapshots' => []];
    $internal = $reports['internal_live'] ?? [];
    $client = $reports['client_live'] ?? [];
    $snapshots = $reports['snapshots'] ?? [];
    $hasMaterial = ($detail['evidence'] ?? []) !== [] || (($detail['sales_intelligence']['status'] ?? null) === 'available');
@endphp

<div class="space-y-5">
    @if (! $hasMaterial)
        <x-ta.card>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.prospects.reports.unavailable') }}</p>
        </x-ta.card>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <x-ta.card data-testid="prospect-internal-report">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.prospects.reports.internal') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.prospects.reports.internal_help') }}</p>
            <dl class="mt-4 space-y-2 text-sm">
                <div><dt class="text-gray-500">{{ __('operator.prospects.identity_label') }}</dt><dd>{{ $internal['identity_status'] ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('operator.prospects.first_meeting_focus') }}</dt><dd>{{ $internal['first_meeting_focus'] ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('operator.prospects.overall_confidence') }}</dt><dd>{{ $internal['overall_confidence'] ?? '—' }}</dd></div>
            </dl>
            <div class="mt-4">
                <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.prospects.reports.internal_notes') }}</label>
                <textarea wire:model="internal_notes" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
            </div>
            <button type="button" wire:click="generateInternalReport" class="mt-3 rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">{{ __('operator.prospects.reports.generate_internal') }}</button>
        </x-ta.card>

        <x-ta.card data-testid="prospect-client-report">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.prospects.reports.client') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.prospects.reports.client_help') }}</p>
            <p class="mt-4 text-sm text-gray-700 dark:text-gray-200">{{ $client['public_digital_situation'] ?? '—' }}</p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-200">
                @foreach (($client['recommended_priorities'] ?? []) as $priority)
                    <li>{{ $priority }}</li>
                @endforeach
            </ul>
            <div class="mt-4">
                <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.prospects.reports.locale') }}</label>
                <select wire:model="report_locale" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="en">EN</option>
                    <option value="tr">TR</option>
                </select>
            </div>
            <button type="button" wire:click="generateClientReport" @disabled(! $hasMaterial)
                class="mt-3 rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60">{{ __('operator.prospects.reports.generate_client') }}</button>
        </x-ta.card>
    </div>

    @if ($shareUrl)
        <x-ta.card>
            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ __('operator.prospects.reports.share_url') }}</p>
            <p class="mt-1 break-all text-sm text-brand-700" data-testid="prospect-share-url">{{ $shareUrl }}</p>
        </x-ta.card>
    @endif

    <x-ta.card>
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.prospects.reports.history') }}</h2>
        @if ($snapshots === [])
            <p class="mt-2 text-sm text-gray-500">{{ __('operator.prospects.reports.no_snapshots') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-gray-400">
                            <th class="py-2 pr-4">{{ __('operator.prospects.reports.projection') }}</th>
                            <th class="py-2 pr-4">{{ __('operator.prospects.reports.generated_at') }}</th>
                            <th class="py-2">{{ __('operator.actions.save') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshots as $snapshot)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4">{{ $snapshot['projection_label'] }}</td>
                                <td class="py-2 pr-4">{{ $snapshot['generated_at'] }}</td>
                                <td class="py-2">
                                    <button type="button" wire:click="downloadSnapshot('{{ $snapshot['id'] }}')" class="text-brand-600 hover:underline">{{ __('operator.prospects.reports.download_pdf') }}</button>
                                    @if ($snapshot['is_client'])
                                        ·
                                        <button type="button" wire:click="shareSnapshot('{{ $snapshot['id'] }}')" class="text-brand-600 hover:underline">{{ __('operator.prospects.reports.create_share') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ta.card>
</div>
