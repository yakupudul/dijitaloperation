@php
    $mp = is_array($monthlyPacing ?? null) ? $monthlyPacing : ['available' => false, 'reason' => 'not_connected'];
    $mpCurrency = (string) ($mp['currency'] ?? '');
    $mpMoney = static fn ($value) => is_numeric($value) ? trim(number_format((float) $value, 2, ',', '.').' '.$mpCurrency) : '—';
    $mpPercent = static fn ($value) => is_numeric($value) ? number_format((float) $value, 1, ',', '.').'%' : '—';
    $mpTone = static fn (string $status): string => match ($status) {
        'on_track' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'over' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'under' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        default => 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
    };
    $mpAccount = is_array($mp['account'] ?? null) ? $mp['account'] : null;
    $mpCampaigns = collect($mp['campaigns'] ?? []);
    $mpDays = (int) ($mp['days_in_month'] ?? 0);
@endphp

<section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-testid="gads-monthly-pacing">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_gads.pacing.title') }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ __('operator_gads.pacing.subtitle', ['timezone' => $mp['timezone'] ?? '—']) }}</p>
            @if (filled($mp['data_through'] ?? null))
                <p class="mt-1 text-[11px] text-gray-400">{{ __('operator_gads.pacing.data_through', ['date' => $mp['data_through'], 'elapsed' => $mp['elapsed_days'] ?? 0, 'days' => $mpDays]) }}</p>
            @endif
        </div>
        @if ($mpAccount !== null)
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $mpTone((string) $mpAccount['status']) }}">{{ __('operator_gads.pacing.states.'.$mpAccount['status']) }}</span>
        @endif
    </div>

    @if (! ($mp['available'] ?? false))
        <p class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ __('operator_gads.pacing.not_connected') }}</p>
    @else
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div><p class="text-xs text-gray-400">{{ __('operator_gads.pacing.mtd_spend') }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $mpMoney($mpAccount['mtd_spend'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ __('operator_gads.pacing.daily_budget') }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $mpMoney($mpAccount['daily_budget'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ __('operator_gads.pacing.monthly_budget', ['days' => $mpDays]) }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $mpMoney($mpAccount['monthly_budget'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ __('operator_gads.pacing.projected') }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $mpMoney($mpAccount['projected_spend'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ __('operator_gads.pacing.pace') }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $mpPercent($mpAccount['pace_percent'] ?? null) }}</p></div>
        </div>

        @if (($mp['reason'] ?? null) === 'no_budget')
            <p class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300"><strong>{{ __('operator_gads.pacing.no_budget') }}.</strong> {{ __('operator_gads.pacing.no_budget_hint') }}</p>
        @endif
        <p class="mt-2 text-[11px] text-gray-400">{{ __('operator_gads.pacing.band_hint') }}</p>

        @if ($mpCampaigns->isNotEmpty())
            <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
                    <th class="px-3 py-2 text-left">{{ __('operator_gads.columns.campaign') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('operator_gads.pacing.mtd_spend') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('operator_gads.pacing.daily_budget') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('operator_gads.pacing.monthly_budget', ['days' => $mpDays]) }}</th>
                    <th class="px-3 py-2 text-right">{{ __('operator_gads.pacing.projected') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('operator_gads.pacing.pace') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('operator_gads.pacing.status') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($mpCampaigns as $row)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $mpMoney($row['mtd_spend']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                @if ($row['shared_budget'] ?? false)
                                    {{ $mpMoney($row['shared_daily_budget'] ?? null) }}<span class="block text-[10px] text-gray-400">{{ __('operator_gads.pacing.shared_budget') }}</span>
                                @else
                                    {{ $mpMoney($row['daily_budget']) }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $mpMoney($row['monthly_budget']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $mpMoney($row['projected_spend']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $mpPercent($row['pace_percent']) }}</td>
                            <td class="px-3 py-2 text-right">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $mpTone((string) $row['status']) }}">{{ ($row['shared_budget'] ?? false) ? __('operator_gads.pacing.shared_budget') : __('operator_gads.pacing.states.'.$row['status']) }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    @endif
</section>
