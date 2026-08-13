@php $m = $data['measurement']; @endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Measurement</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $m['subtitle'] }}</p>
        <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">{{ $m['missing_note'] ?? 'Missing ≠ zero — absent signals are not performance.' }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ta.metric-card label="Primary mappings" :value="(string) $m['glance']['primary_mappings']" />
        <x-ta.metric-card label="Healthy signals" :value="$m['glance']['healthy']" tone="positive" />
        <x-ta.metric-card label="Needs mapping" :value="(string) $m['glance']['needs_mapping']" tone="warning" />
        <x-ta.metric-card label="Measurement Findings" :value="(string) $m['glance']['findings']" tone="warning" />
    </div>

    @if (! empty($m['interruption']))
        <x-ta.alert variant="warning" title="{{ $m['interruption']['title'] }}" :message="$m['interruption']['detail']" />
    @endif

    <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Result mapping matrix</h3>
            <p class="text-xs text-gray-400">Business action → Meta result · platform vs outcome</p>
        </div>
        <x-ta.table class="border-0 rounded-none">
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Business action</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Meta result</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Role</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">State</th>
            </x-slot:head>
            @foreach ($m['matrix'] as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] }}</td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['meta_result'] ?? $row['source'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['role'] }}</td>
                    <td class="px-4 py-2.5"><x-ta.badge :color="match($row['state']) { 'Healthy' => 'success', 'Needs mapping', 'No recent signal', 'Partial' => 'warning', 'Broken' => 'error', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge></td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Business outcome funnel</h3>
            <p class="mt-0.5 text-[11px] text-gray-400">{{ $m['business_funnel']['note'] ?? 'Shown only where business evidence exists' }}</p>
            <ol class="mt-3 space-y-2">
                @foreach ($m['business_funnel']['steps'] ?? [] as $step)
                    <li class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                        <span class="text-gray-700 dark:text-gray-300">{{ $step['label'] }}</span>
                        <span @class([
                            'font-semibold tabular-nums',
                            'text-slate-400' => ($step['state'] ?? '') === 'Missing',
                            'text-gray-900 dark:text-white' => ($step['state'] ?? '') !== 'Missing',
                        ])>{{ $step['value'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Lead quality</h3>
            <p class="mt-0.5 text-[11px] text-gray-400">{{ $m['lead_quality']['source'] ?? 'CRM / operator context when available' }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                @foreach ($m['lead_quality']['metrics'] ?? [] as $metric)
                    <div>
                        <dt class="text-xs text-gray-400">{{ $metric['label'] }}</dt>
                        <dd class="font-semibold text-gray-900 dark:text-white">{{ $metric['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
            @if (! empty($m['lead_quality']['note']))
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $m['lead_quality']['note'] }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Measurement debt</h3>
            <ul class="mt-3 space-y-2">
                @foreach ($m['debt'] as $row)
                    <li class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                        <span>{{ $row['label'] }}</span>
                        <span class="font-semibold tabular-nums text-amber-700 dark:text-amber-400">{{ $row['count'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Trust states</h3>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($m['trust_chips'] ?? $m['chips'] ?? [] as $chip)
                    <span @class([
                        'inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium',
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' => ($chip['state'] ?? '') === 'Healthy',
                        'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400' => in_array($chip['state'] ?? '', ['Needs mapping', 'Partial', 'No recent signal'], true),
                        'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400' => ($chip['state'] ?? '') === 'Broken',
                        'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-gray-300' => ! in_array($chip['state'] ?? '', ['Healthy', 'Needs mapping', 'Partial', 'No recent signal', 'Broken'], true),
                    ])>{{ $chip['label'] }} · {{ $chip['state'] }}</span>
                @endforeach
            </div>
            @if (! empty($m['trust']))
                <p class="mt-3 text-xs text-gray-500">{{ $m['trust'] }}</p>
            @endif
            @if (! empty($m['finding_id']))
                <button type="button" wire:click="openFinding('{{ $m['finding_id'] }}')" class="mt-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open measurement Finding →</button>
            @endif
        </section>
    </div>

    <x-ta.alert variant="info" title="Missing ≠ zero" :message="$m['interpretation_note'] ?? 'Do not judge CPA or underperformance until the primary signal is trustworthy.'" />
</div>
