@php
    $a = $data['audience'];
    $barRows = [
        ['title' => 'Placement', 'rows' => $a['placements'] ?? [], 'tone' => 'bg-blue-500'],
        ['title' => 'Age', 'rows' => $a['age'] ?? [], 'tone' => 'bg-violet-500'],
        ['title' => 'Country', 'rows' => $a['country'] ?? [], 'tone' => 'bg-emerald-500'],
        ['title' => 'Gender', 'rows' => $a['gender'] ?? [], 'tone' => 'bg-amber-500'],
        ['title' => 'Platform', 'rows' => $a['platform'] ?? [], 'tone' => 'bg-rose-500'],
    ];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Audience & Delivery</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $a['subtitle'] ?? 'Configured targeting vs observed delivery — descriptive only.' }}</p>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">No causal claims. Concentration ≠ audience quality score.</p>
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Configured</h3>
            <p class="mt-0.5 text-[11px] text-gray-400">Operator / Meta targeting setup</p>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach ($a['configured'] ?? [] as $row)
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-gray-500">{{ $row['label'] }}</dt>
                        <dd class="text-right font-medium text-gray-900 dark:text-white">{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Observed</h3>
            <p class="mt-0.5 text-[11px] text-gray-400">Where spend actually delivered</p>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach ($a['observed'] ?? [] as $row)
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-gray-500">{{ $row['label'] }}</dt>
                        <dd class="text-right font-medium text-gray-900 dark:text-white">{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>

    @if (! empty($a['concentration_note']))
        <p class="rounded-xl border border-amber-200/80 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ $a['concentration_note'] }}</p>
    @endif

    <div class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
        @foreach ($barRows as $block)
            @php $maxSpend = max(1, (float) collect($block['rows'])->max('spend')); @endphp
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $block['title'] }}</h3>
                <ul class="mt-3 space-y-2.5">
                    @foreach ($block['rows'] as $row)
                        <li>
                            <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</span>
                                <span class="tabular-nums text-gray-500">
                                    ₺{{ number_format($row['spend']) }}
                                    @if (isset($row['results']))
                                        · {{ number_format($row['results']) }} {{ $row['result_label'] ?? '' }}
                                    @elseif (isset($row['share']))
                                        · {{ $row['share'] }}%
                                    @endif
                                </span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                                <div class="h-full rounded-full {{ $block['tone'] }}" style="width: {{ min(100, round(($row['spend'] / $maxSpend) * 100)) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    @if (! empty($a['notes']))
        <ul class="space-y-1 text-xs text-gray-500">
            @foreach ($a['notes'] as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>
    @endif
</div>
