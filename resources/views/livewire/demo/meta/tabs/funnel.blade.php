@php
    $f = $data['funnel'];
    $maxDest = max(1, (float) collect($f['destinations'] ?? [])->max('spend'));
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Funnel & Destinations</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $f['subtitle'] ?? 'Where traffic lands and how funnel shapes differ by destination.' }}</p>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Destination mix</h3>
        <p class="mt-0.5 text-[11px] text-gray-400">Spend share by destination family</p>
        <ul class="mt-3 space-y-2">
            @foreach ($f['destinations'] ?? [] as $row)
                <li>
                    <div class="mb-1 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</span>
                        <span class="tabular-nums text-gray-500">₺{{ number_format($row['spend']) }} · {{ $row['share'] }}%@if (! empty($row['results'])) · {{ number_format($row['results']) }} {{ $row['result_label'] ?? '' }}@endif</span>
                    </div>
                    @php
                        $destTone = match (true) {
                            str_contains(strtolower($row['label']), 'instant') => 'bg-violet-500',
                            str_contains(strtolower($row['label']), 'website') => 'bg-blue-500',
                            str_contains(strtolower($row['label']), 'message') => 'bg-emerald-500',
                            str_contains(strtolower($row['label']), 'instagram') => 'bg-rose-500',
                            default => 'bg-slate-400',
                        };
                    @endphp
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                        <div class="h-full rounded-full {{ $destTone }}" style="width: {{ min(100, round(($row['spend'] / $maxDest) * 100)) }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Instant Form</h3>
            @php $if = $f['instant_form'] ?? []; @endphp
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-gray-400">Spend</dt><dd class="font-semibold tabular-nums">₺{{ number_format($if['spend'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Leads</dt><dd class="font-semibold tabular-nums">{{ number_format($if['leads'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Cost / lead</dt><dd class="font-semibold tabular-nums">₺{{ number_format($if['cost_lead'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Complete rate</dt><dd class="font-semibold">{{ $if['complete_rate'] ?? '—' }}</dd></div>
            </dl>
            @if (! empty($if['notes']))
                <ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                    @foreach ($if['notes'] as $note)<li>{{ $note }}</li>@endforeach
                </ul>
            @endif
            @if (! empty($if['attention']))
                <p class="mt-2 text-xs font-medium text-amber-700 dark:text-amber-400">{{ $if['attention'] }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Website destination</h3>
                @if (! empty($identity['website_asset_id']))
                    <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Website →</a>
                @endif
            </div>
            @php $web = $f['website'] ?? []; @endphp
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-gray-400">Spend</dt><dd class="font-semibold tabular-nums">₺{{ number_format($web['spend'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Landings</dt><dd class="font-semibold tabular-nums">{{ $web['landings'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">Primary action</dt><dd class="font-semibold">{{ $web['primary_action'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">Message match</dt><dd class="font-semibold">{{ $web['message_match'] ?? '—' }}</dd></div>
            </dl>
            @if (! empty($web['note']))
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $web['note'] }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Messaging</h3>
            @php $msg = $f['messaging'] ?? []; @endphp
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-gray-400">Spend</dt><dd class="font-semibold tabular-nums">₺{{ number_format($msg['spend'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Conversations</dt><dd class="font-semibold tabular-nums">{{ number_format($msg['conversations'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Cost / convo</dt><dd class="font-semibold tabular-nums">₺{{ number_format($msg['cost_conversation'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">State</dt><dd class="font-semibold">{{ $msg['state'] ?? '—' }}</dd></div>
            </dl>
            @if (! empty($msg['note']))
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $msg['note'] }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Instagram Profile</h3>
            @php $ig = $f['instagram_profile'] ?? []; @endphp
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-gray-400">Spend</dt><dd class="font-semibold tabular-nums">₺{{ number_format($ig['spend'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Profile visits</dt><dd class="font-semibold tabular-nums">{{ number_format($ig['profile_visits'] ?? 0) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Role</dt><dd class="font-semibold">{{ $ig['role'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">State</dt><dd class="font-semibold">{{ $ig['state'] ?? '—' }}</dd></div>
            </dl>
            @if (! empty($ig['note']))
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $ig['note'] }}</p>
            @endif
        </section>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Message match</h3>
        <p class="mt-0.5 text-[11px] text-violet-700 dark:text-violet-300">Explainable states — no numeric match score</p>
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($f['message_match'] ?? [] as $row)
                <li class="flex flex-col gap-1 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                        <p class="text-xs text-gray-500">{{ $row['detail'] }}</p>
                    </div>
                    <x-ta.badge :color="match($row['state']) { 'Strong' => 'success', 'Weak' => 'error', 'Partial' => 'warning', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Funnel shapes</h3>
        <p class="mt-0.5 text-[11px] text-gray-400">Different destinations produce different shapes — compare within type</p>
        <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($f['shapes'] ?? [] as $shape)
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/[0.03]">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $shape['label'] }}</p>
                    <ol class="mt-2 space-y-1.5">
                        @foreach ($shape['steps'] as $step)
                            <li class="flex items-center justify-between gap-2 text-xs">
                                <span class="text-gray-600 dark:text-gray-300">{{ $step['label'] }}</span>
                                <span class="tabular-nums font-medium text-gray-900 dark:text-white">{{ $step['value'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                    @if (! empty($shape['note']))
                        <p class="mt-2 text-[11px] text-gray-400">{{ $shape['note'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
</div>
