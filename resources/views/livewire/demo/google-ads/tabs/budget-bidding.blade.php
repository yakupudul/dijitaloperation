@php
    $isTr = app()->getLocale() === 'tr';
    $control = is_array($budgetControl ?? null) ? $budgetControl : [
        'currency' => $professional['currency'] ?? '',
        'plan' => null,
        'summary' => [],
        'pacing' => ['available' => false],
        'campaigns' => [],
        'matrix' => ['scale' => [], 'fix' => [], 'efficient' => [], 'reduce' => []],
        'reallocation' => ['available' => false, 'increase' => [], 'decrease' => [], 'note' => null],
        'strategies' => ['items' => [], 'total' => 0, 'active' => 0, 'unused' => 0, 'attention' => 0],
        'scenario' => ['available' => false, 'reason' => null],
        'boundaries' => [],
    ];
    $currency = $control['currency'] ?? ($professional['currency'] ?? '');
    $summary = $control['summary'] ?? [];
    $pacing = $control['pacing'] ?? ['available' => false];
    $plan = $control['plan'] ?? null;
    $campaigns = collect($control['campaigns'] ?? []);
    $matrix = $control['matrix'] ?? [];
    $reallocation = $control['reallocation'] ?? [];
    $strategies = $control['strategies'] ?? ['items' => []];
    $editable = (bool) ($budgetPlanEditable ?? false);

    $money = static fn ($value) => is_numeric($value) ? number_format((float) $value, 2, ',', '.').' '.$currency : '—';
    $number = static fn ($value, int $decimals = 1) => is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : '—';
    $percent = static fn ($value) => is_numeric($value) ? number_format((float) $value, 1, ',', '.').'%' : '—';

    $decisionLabels = $isTr ? [
        'scale' => 'Scale adayı',
        'fix' => 'Önce düzelt',
        'reduce' => 'Azalt / incele',
        'rank' => 'Rank / kalite sorunu',
        'efficient' => 'Verimli / koru',
        'maintain' => 'Koru / izle',
        'insufficient' => 'Sinyal yetersiz',
        'inactive' => 'Aktif değil',
    ] : [
        'scale' => 'Scale candidate',
        'fix' => 'Fix before scaling',
        'reduce' => 'Reduce / review',
        'rank' => 'Rank / quality constraint',
        'efficient' => 'Efficient / maintain',
        'maintain' => 'Maintain / monitor',
        'insufficient' => 'Insufficient signal',
        'inactive' => 'Inactive',
    ];
    $decisionReasons = $isTr ? [
        'scale' => 'CPA karar ölçütünün iyi tarafında ve bütçe kaybı ek talep olduğunu gösteriyor.',
        'fix' => 'Talep var ancak mevcut CPA zayıf; bütçeyi büyütmeden önce verimlilik düzeltilmeli.',
        'reduce' => 'CPA karar ölçütünden belirgin biçimde kötü ve güçlü bir bütçe-kısıtı fırsatı görünmüyor.',
        'rank' => 'Kayıp daha çok sıralama/kalite kaynaklı; yalnız bütçe artırmak sorunu çözmeyebilir.',
        'efficient' => 'Verimli çalışıyor ancak agresif büyümeyi destekleyen güçlü bütçe kaybı sinyali yok.',
        'maintain' => 'Büyük bir bütçe hareketini destekleyen yeterli kanıt henüz yok.',
        'insufficient' => 'Sağlıklı bir bütçe kararı için provider conversion/CPA sinyali yetersiz.',
        'inactive' => 'Seçili dönemde aktif harcama sinyali yok.',
    ] : [
        'scale' => 'CPA is on the efficient side of the decision benchmark and budget loss indicates additional reachable demand.',
        'fix' => 'Demand exists but current CPA is weak; improve efficiency before adding budget.',
        'reduce' => 'CPA is materially worse than the decision benchmark without a strong budget-constrained opportunity.',
        'rank' => 'Loss is driven more by rank/quality; adding budget alone may not solve the constraint.',
        'efficient' => 'Efficient, but there is no strong budget-loss signal supporting aggressive scaling.',
        'maintain' => 'There is not enough evidence for a material budget move yet.',
        'insufficient' => 'Provider conversion/CPA signal is insufficient for a confident budget decision.',
        'inactive' => 'No active spend signal in the selected period.',
    ];
    $decisionTone = static fn (string $code): string => match ($code) {
        'scale', 'efficient' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'fix', 'rank' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'reduce' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        default => 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
    };
    $pacingStateLabel = $isTr ? match ($pacing['state'] ?? 'unavailable') {
        'ahead' => 'Hızlı harcanıyor',
        'behind' => 'Yavaş harcanıyor',
        'on_track' => 'Planla uyumlu',
        default => 'Plan yok',
    } : match ($pacing['state'] ?? 'unavailable') {
        'ahead' => 'Spending fast',
        'behind' => 'Spending slow',
        'on_track' => 'On plan',
        default => 'No plan',
    };
@endphp

<div class="space-y-5">
    <header class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Bütçe & Teklif Kontrol Merkezi' : 'Budget & Bidding Control Center' }}</h2>
                <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-700 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">MOXDOP Decision Layer</span>
            </div>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bütçenin yalnızca ne kadar harcandığını değil; hangi kampanyanın büyütülmesi, korunması veya önce düzeltilmesi gerektiğini değerlendirin.' : 'Go beyond reporting spend: identify which campaigns should scale, hold, or be fixed before receiving more budget.' }}</p>
        </div>
        <div class="text-xs text-gray-400">{{ $data['period_label'] ?? (($data['period_start'] ?? '').' – '.($data['period_end'] ?? '')) }}</div>
    </header>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @foreach ([
            [$isTr ? 'Harcama' : 'Spend', $money($summary['spend'] ?? null), null],
            [$isTr ? 'Provider dönüşüm' : 'Provider conversions', $number($summary['provider_conversions'] ?? null, 2), null],
            ['Provider CPA', $money($summary['provider_cpa'] ?? null), $isTr ? 'Qualified lead CPA değildir' : 'Not qualified-lead CPA'],
            [$isTr ? 'Karar CPA ölçütü' : 'Decision CPA benchmark', $money($summary['benchmark_cpa'] ?? null), ($summary['benchmark_source'] ?? '') === 'plan_target_cpa' ? ($isTr ? 'Ajans hedef CPA' : 'Agency target CPA') : ($isTr ? 'Hesap provider CPA ortalaması' : 'Account provider CPA average')],
            [$isTr ? 'Scale adayı' : 'Scale candidates', (string) ($summary['scale_candidates'] ?? 0), null],
            [$isTr ? 'Önce düzelt' : 'Fix first', (string) ($summary['fix_before_scale'] ?? 0), null],
        ] as [$label, $value, $secondary])
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p>
                @if ($secondary)<p class="mt-1 text-[11px] leading-4 text-gray-400">{{ $secondary }}</p>@endif
            </div>
        @endforeach
    </section>

    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(340px,.8fr)]">
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Bütçe sağlığı & pacing' : 'Budget health & pacing' }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Gerçek harcamayı MOXDOP’ta tanımlanan ajans dönem bütçesiyle karşılaştırır.' : 'Compares actual spend with the agency period budget stored in MOXDOP.' }}</p>
                </div>
                <span @class([
                    'rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
                    'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => ($pacing['state'] ?? '') === 'on_track',
                    'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => in_array(($pacing['state'] ?? ''), ['ahead', 'behind'], true),
                    'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => ! ($pacing['available'] ?? false),
                ])>{{ $pacingStateLabel }}</span>
            </div>

            @if ($pacing['available'] ?? false)
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p class="text-xs text-gray-400">{{ $isTr ? 'Plan bütçesi' : 'Plan budget' }}</p><p class="mt-1 text-lg font-semibold">{{ $money($pacing['planned_budget']) }}</p></div>
                    <div><p class="text-xs text-gray-400">{{ $isTr ? 'Kalan' : 'Remaining' }}</p><p class="mt-1 text-lg font-semibold {{ ($pacing['remaining'] ?? 0) < 0 ? 'text-rose-600' : '' }}">{{ $money($pacing['remaining']) }}</p></div>
                    <div><p class="text-xs text-gray-400">{{ $isTr ? 'Bugün beklenen harcama' : 'Expected by today' }}</p><p class="mt-1 text-lg font-semibold">{{ $money($pacing['expected_spend']) }}</p></div>
                    <div><p class="text-xs text-gray-400">{{ $isTr ? 'Dönem sonu tahmini' : 'Projected period spend' }}</p><p class="mt-1 text-lg font-semibold">{{ $money($pacing['projected_spend']) }}</p></div>
                </div>
                <div class="mt-5">
                    <div class="flex items-center justify-between gap-3 text-xs text-gray-500">
                        <span>{{ $isTr ? 'Zaman ilerlemesi' : 'Time elapsed' }} {{ $percent($pacing['elapsed_percent']) }}</span>
                        <span>{{ $isTr ? 'Pacing' : 'Pacing' }} <strong class="text-gray-800 dark:text-gray-200">{{ $percent($pacing['pace_percent']) }}</strong></span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ max(0, min(100, (float) ($pacing['pace_percent'] ?? 0))) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs {{ ($pacing['variance'] ?? 0) > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500' }}">
                        {{ $isTr ? 'Projeksiyon sapması:' : 'Projected variance:' }} {{ $money($pacing['variance']) }}
                    </p>
                </div>
            @else
                <div class="mt-5 rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? 'Bu dönem için ajans bütçe planı tanımlı değil.' : 'No agency budget plan is defined for this period.' }}</p>
                    <p class="mt-1 text-xs leading-5 text-gray-500">{{ $isTr ? 'MOXDOP plan bütçesi olmadan pacing, kalan bütçe veya dönem sonu tahmini uydurmaz.' : 'MOXDOP does not fabricate pacing, remaining budget, or end-of-period projections without a canonical plan.' }}</p>
                </div>
            @endif
        </div>

        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Ajans bütçe planı' : 'Agency budget plan' }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $data['period_start'] ?? '—' }} → {{ $data['period_end'] ?? '—' }}</p>
                </div>
                @if ($plan)<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $isTr ? 'Tanımlı' : 'Defined' }}</span>@endif
            </div>

            @if ($editable)
                <div class="mt-4 space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'Dönem bütçesi' : 'Period budget' }} · {{ $currency }}</label>
                        <input wire:model="budget_plan_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" placeholder="30000">
                        @error('budget_plan_amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'Hedef CPA (opsiyonel)' : 'Target CPA (optional)' }}</label>
                            <input wire:model="budget_target_cpa" type="number" min="0" step="0.01" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" placeholder="50">
                            @error('budget_target_cpa')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'Hedef ROAS (opsiyonel, x)' : 'Target ROAS (optional, x)' }}</label>
                            <input wire:model="budget_target_roas" type="number" min="0" step="0.01" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" placeholder="4.00">
                            @error('budget_target_roas')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'Plan notu' : 'Plan note' }}</label>
                        <textarea wire:model="budget_plan_notes" rows="2" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" placeholder="{{ $isTr ? 'Örn. Bu ay lead kalitesi öncelikli.' : 'e.g. Lead quality is the priority this period.' }}"></textarea>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <button type="button" wire:click="saveBudgetPlan" wire:loading.attr="disabled" wire:target="saveBudgetPlan" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50">{{ $isTr ? 'Planı kaydet' : 'Save plan' }}</button>
                        @if ($plan)
                            <button type="button" wire:click="clearBudgetPlan" wire:confirm="{{ $isTr ? 'Seçili dönem bütçe planı kaldırılsın mı?' : 'Remove the budget plan for this period?' }}" class="rounded-lg px-3 py-2 text-xs font-medium text-rose-600 ring-1 ring-inset ring-rose-200">{{ $isTr ? 'Planı kaldır' : 'Remove plan' }}</button>
                        @endif
                    </div>
                </div>
            @else
                <div class="mt-4 text-sm text-gray-500">{{ $isTr ? 'Plan düzenleme yalnız operator çalışma alanında kullanılabilir.' : 'Plan editing is available in the operator workspace.' }}</div>
            @endif
        </div>
    </section>

    <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'MOXDOP bütçe kararları' : 'MOXDOP budget decisions' }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Provider performansı + bütçe/rank kaybı + varsa ajans hedef CPA birlikte değerlendirilir.' : 'Combines provider performance, budget/rank loss and the agency target CPA when available.' }}</p>
            </div>
            <span class="text-xs text-gray-400">{{ $campaigns->count() }} {{ $isTr ? 'kampanya' : 'campaigns' }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[1250px] w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th>
                        <th class="px-3 py-2 text-right">{{ $isTr ? 'Günlük bütçe' : 'Daily budget' }}</th>
                        <th class="px-3 py-2 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
                        <th class="px-3 py-2 text-right">Conv.</th>
                        <th class="px-3 py-2 text-right">Provider CPA</th>
                        <th class="px-3 py-2 text-right">{{ $isTr ? 'Gösterim payı' : 'Impr. share' }}</th>
                        <th class="px-3 py-2 text-right">{{ $isTr ? 'Bütçe kaybı' : 'Lost budget' }}</th>
                        <th class="px-3 py-2 text-right">{{ $isTr ? 'Rank kaybı' : 'Lost rank' }}</th>
                        <th class="px-4 py-2 text-left">{{ $isTr ? 'Karar' : 'Decision' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($campaigns as $row)
                        @php $code = (string) ($row['decision_code'] ?? 'maintain'); @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3"><p class="font-medium text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="mt-0.5 text-[11px] text-gray-400">{{ $row['type'] }} · {{ $row['status'] }}</p></td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ ($row['budget'] ?? 0) > 0 ? $money($row['budget']) : '—' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $money($row['spend']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $number($row['conversions'], 2) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums font-medium">{{ $money($row['cpa']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $percent($row['impr_share']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $percent($row['lost_is_budget']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $percent($row['lost_is_rank']) }}</td>
                            <td class="max-w-[320px] px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $decisionTone($code) }}">{{ $decisionLabels[$code] ?? $code }}</span>
                                <p class="mt-1.5 text-[11px] leading-4 text-gray-500">{{ $decisionReasons[$code] ?? ($row['decision_reason'] ?? '') }}</p>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Bu dönem için kampanya performans verisi yok.' : 'No campaign performance data for this period.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Budget Opportunity Map' : 'Budget Opportunity Map' }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Verimlilik ve bütçe baskısını dört karar bölgesine ayırır.' : 'Groups campaigns into four decision zones using efficiency and budget pressure.' }}</p>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['scale', $isTr ? 'Scale' : 'Scale', 'bg-emerald-50/70 ring-emerald-100 dark:bg-emerald-500/[0.06] dark:ring-emerald-500/20'],
                    ['fix', $isTr ? 'Önce düzelt' : 'Fix before scaling', 'bg-amber-50/70 ring-amber-100 dark:bg-amber-500/[0.06] dark:ring-amber-500/20'],
                    ['efficient', $isTr ? 'Verimli / koru' : 'Efficient / maintain', 'bg-blue-50/70 ring-blue-100 dark:bg-blue-500/[0.06] dark:ring-blue-500/20'],
                    ['reduce', $isTr ? 'Azalt / rank incele' : 'Reduce / inspect rank', 'bg-rose-50/70 ring-rose-100 dark:bg-rose-500/[0.06] dark:ring-rose-500/20'],
                ] as [$key, $label, $classes])
                    @php $zoneRows = collect($matrix[$key] ?? []); @endphp
                    <div class="rounded-lg p-3 ring-1 ring-inset {{ $classes }}">
                        <div class="flex items-center justify-between gap-2"><p class="text-xs font-bold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ $label }}</p><span class="text-sm font-bold">{{ $zoneRows->count() }}</span></div>
                        <div class="mt-2 space-y-1">
                            @forelse ($zoneRows->take(4) as $row)<p class="truncate text-xs text-gray-600 dark:text-gray-300">{{ $row['name'] }}</p>@empty<p class="text-xs text-gray-400">—</p>@endforelse
                            @if ($zoneRows->count() > 4)<p class="text-[11px] text-gray-400">+{{ $zoneRows->count() - 4 }}</p>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Bütçe yeniden dağıtım yönü' : 'Budget reallocation direction' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Kesin tutar uydurulmaz; yalnız kanıtlanan yön gösterilir.' : 'Exact amounts are never fabricated; only evidence-backed direction is shown.' }}</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-emerald-50/60 p-3 dark:bg-emerald-500/[0.06]">
                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ $isTr ? 'Bütçe artışı adayları' : 'Increase candidates' }}</p>
                    <div class="mt-2 space-y-2">
                        @forelse (($reallocation['increase'] ?? []) as $row)
                            <div><p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="text-[11px] text-gray-500">CPA {{ $money($row['cpa']) }} · {{ $isTr ? 'bütçe kaybı' : 'lost budget' }} {{ $percent($row['lost_is_budget']) }}</p></div>
                        @empty <p class="text-xs text-gray-500">{{ $isTr ? 'Güçlü artış adayı yok.' : 'No strong increase candidate.' }}</p> @endforelse
                    </div>
                </div>
                <div class="rounded-lg bg-rose-50/60 p-3 dark:bg-rose-500/[0.06]">
                    <p class="text-xs font-bold uppercase tracking-wide text-rose-700 dark:text-rose-300">{{ $isTr ? 'Azaltma / gözden geçirme' : 'Reduce / review' }}</p>
                    <div class="mt-2 space-y-2">
                        @forelse (($reallocation['decrease'] ?? []) as $row)
                            <div><p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="text-[11px] text-gray-500">CPA {{ $money($row['cpa']) }}</p></div>
                        @empty <p class="text-xs text-gray-500">{{ $isTr ? 'Güçlü azaltma adayı yok.' : 'No strong reduction candidate.' }}</p> @endforelse
                    </div>
                </div>
            </div>
            <p class="mt-3 text-[11px] leading-4 text-gray-400">{{ $isTr ? 'Kesin TL transferi için forecast/simulator verisi gerekir. MOXDOP bu kanıt olmadan marjinal CPA veya ek dönüşüm tahmini üretmez.' : 'Exact transfer amounts require forecast/simulator evidence. MOXDOP does not invent marginal CPA or incremental conversions without it.' }}</p>
        </div>
    </section>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Teklif stratejisi sağlığı' : 'Bid strategy health' }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Raw provider stratejilerini kullanılabilirlik ve hedefler açısından operatör diline çevirir.' : 'Translates raw provider strategies into operator-friendly health and target context.' }}</p>
            </div>
            <div class="flex flex-wrap gap-2 text-[11px]">
                <span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $isTr ? 'Aktif' : 'Active' }} {{ $strategies['active'] ?? 0 }}</span>
                <span class="rounded bg-gray-50 px-2 py-1 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $isTr ? 'Kullanılmayan' : 'Unused' }} {{ $strategies['unused'] ?? 0 }}</span>
                <span class="rounded bg-amber-50 px-2 py-1 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ $isTr ? 'Dikkat' : 'Attention' }} {{ $strategies['attention'] ?? 0 }}</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[900px] w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2 text-left">{{ $isTr ? 'Strateji' : 'Strategy' }}</th><th class="px-3 py-2 text-left">{{ $isTr ? 'Tür' : 'Type' }}</th><th class="px-3 py-2 text-left">{{ $isTr ? 'Sağlık' : 'Health' }}</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Kampanya' : 'Campaigns' }}</th><th class="px-3 py-2 text-right">Target CPA</th><th class="px-4 py-2 text-right">Target ROAS</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (($strategies['items'] ?? []) as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-800 dark:text-gray-200">{{ $row['name'] }}</td>
                            <td class="px-3 py-2">{{ $row['type_label'] }}</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ ($row['health'] ?? '') === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : (($row['health'] ?? '') === 'unused' ? 'bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300') }}">{{ $isTr ? (($row['health'] ?? '') === 'active' ? 'Aktif' : (($row['health'] ?? '') === 'unused' ? 'Kullanılmıyor' : 'Dikkat')) : $row['health_label'] }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['campaign_count'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $money($row['target_cpa']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ is_numeric($row['target_roas'] ?? null) ? number_format((float)$row['target_roas'] * 100, 0, ',', '.').'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Portfolio teklif stratejisi verisi yok.' : 'No portfolio bidding strategy data.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50/50 p-5 dark:border-gray-700 dark:bg-white/[0.02]">
            <div class="flex items-center gap-2"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Senaryo Planlayıcı' : 'Scenario Planner' }}</h3><span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-white/10">Forecast required</span></div>
            <p class="mt-2 text-sm text-gray-500">{{ $isTr ? '“Bütçeyi 30.000 → 40.000 TL yaparsam ne olur?” sorusu için Google forecast/simulator kanıtı gerekir.' : 'Answering “what happens if budget moves from 30k to 40k?” requires Google forecast/simulator evidence.' }}</p>
            <p class="mt-2 text-xs leading-5 text-gray-400">{{ $isTr ? 'Bu veri henüz merkezi havuza alınmadığı için ek dönüşüm, marginal CPA veya ROAS tahmini gösterilmiyor.' : 'Because this data is not yet in the central pool, incremental conversions, marginal CPA, and ROAS projections remain unavailable.' }}</p>
        </div>
        <div class="rounded-xl bg-blue-50 p-5 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:ring-blue-500/20">
            <h3 class="font-semibold text-blue-900 dark:text-blue-100">{{ $isTr ? 'Veri sınırları' : 'Decision boundaries' }}</h3>
            <div class="mt-3 space-y-2 text-xs leading-5 text-blue-800 dark:text-blue-200">
                <p>• {{ $isTr ? 'Provider CPA, Google Ads conversion sayısını kullanır; qualified lead / satış CPA değildir.' : 'Provider CPA uses Google Ads conversions; it is not qualified-lead or sale CPA.' }}</p>
                <p>• {{ $isTr ? 'Hedef CPA tanımlı değilse MOXDOP yalnız göreli hesap CPA ortalamasını kıyas ölçütü olarak kullanır.' : 'Without a target CPA, MOXDOP uses account provider CPA only as a relative benchmark.' }}</p>
                <p>• {{ $isTr ? 'Bu ekran Google Ads bütçesini veya teklif stratejisini otomatik değiştirmez.' : 'This screen never auto-applies Google Ads budgets or bidding changes.' }}</p>
                @if (($plan['target_roas'] ?? null) !== null)<p>• {{ $isTr ? 'Plan Target ROAS kayıtlı; kampanya conversion value / revenue karar katmanı hazır olmadan otomatik karar ölçütü yapılmaz.' : 'Plan Target ROAS is stored, but it is not used for automated decisions until canonical conversion-value/revenue evidence exists.' }}</p>@endif
            </div>
        </div>
    </section>
</div>
