@php
    $isTr = app()->getLocale() === 'tr';
    $opsData = $data['operations'] ?? [];
    $labels = [
        'findings' => $isTr ? 'Bulgular' : 'Findings',
        'recommendations' => $isTr ? 'Öneriler' : 'Recommendations',
        'tasks' => $isTr ? 'Görevler' : 'Tasks',
        'outcomes' => $isTr ? 'Sonuçlar' : 'Outcomes',
    ];
    $activeRows = collect($opsData[$ops] ?? []);
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'MOXDOP operasyon zinciri' : 'MOXDOP operations chain' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Provider verisini karar ve işe dönüştüren katman: Bulgu → Öneri → Görev → Sonuç.' : 'The layer that turns provider data into decisions and work: Finding → Recommendation → Task → Outcome.' }}</p>
    </div>

    <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
        @foreach ($labels as $key => $label)
            <button type="button" wire:click="setOps('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $ops === $key,
                'text-gray-600 dark:text-gray-300' => $ops !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($activeRows->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center dark:border-gray-700 dark:bg-white/[0.02]">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $labels[$ops] }} · {{ $isTr ? 'henüz kayıt yok' : 'no records yet' }}</h3>
            <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-500">
                @if ($ops === 'findings')
                    {{ $isTr ? 'Google Ads provider verisi toplanmış olabilir; ancak MOXDOP analiz motoru kanıta dayalı bir bulgu üretmeden burada sahte uyarı gösterilmez.' : 'Provider data may already be collected, but no warning is fabricated until the MOXDOP analysis engine produces an evidence-backed finding.' }}
                @elseif ($ops === 'recommendations')
                    {{ $isTr ? 'Öneriler bir bulguya dayanır. Google’ın kendi Recommendations kayıtları yukarıda ayrı tutulur ve MOXDOP önerisi sayılmaz.' : 'Recommendations are based on findings. Google provider Recommendations remain separate above and are not treated as MOXDOP recommendations.' }}
                @elseif ($ops === 'tasks')
                    {{ $isTr ? 'Bir öneri operatör tarafından işe dönüştürülmeden otomatik görev oluşturulmaz.' : 'Tasks are not auto-created until an operator turns a recommendation into work.' }}
                @else
                    {{ $isTr ? 'Bir görev tamamlandıktan ve sonraki performans gözlemlendikten sonra sonuç kaydı oluşur; sistem nedenselliği uydurmaz.' : 'Outcomes are recorded after work is completed and later performance is observed; the system does not invent causality.' }}
                @endif
            </p>
        </div>
    @elseif ($ops === 'findings')
        <x-ta.table>
            <x-slot:head><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Önem' : 'Severity' }}</th><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Kategori' : 'Category' }}</th><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Bulgu' : 'Finding' }}</th><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Durum' : 'Status' }}</th></x-slot:head>
            @foreach ($activeRows as $f)<tr><td class="px-4 py-2.5"><x-ta.badge :color="match($f['severity']) { 'critical', 'high' => 'error', 'medium' => 'warning', default => 'light' }" size="sm">{{ $f['severity'] }}</x-ta.badge></td><td class="px-4 py-2.5 text-xs text-gray-500">{{ $f['category'] }}</td><td class="px-4 py-2.5"><button type="button" wire:click="openFinding('{{ $f['id'] }}')" class="text-left text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $f['title'] }}</button></td><td class="px-4 py-2.5 text-xs">{{ $f['status'] }}</td></tr>@endforeach
        </x-ta.table>
    @elseif ($ops === 'recommendations')
        <ul class="space-y-2">@foreach ($activeRows as $r)<li class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $r['status'] }} · {{ $r['finding_id'] }}</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $r['title'] }}</p><button type="button" wire:click="openFinding('{{ $r['finding_id'] }}')" class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Bulguyu aç' : 'Open Finding' }}</button></li>@endforeach</ul>
    @elseif ($ops === 'tasks')
        <x-ta.table><x-slot:head><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Görev' : 'Task' }}</th><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Sorumlu' : 'Owner' }}</th><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Termin' : 'Due' }}</th><th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">{{ $isTr ? 'Durum' : 'Status' }}</th></x-slot:head>@foreach ($activeRows as $t)<tr><td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $t['title'] }}</td><td class="px-4 py-2.5 text-xs">{{ $t['owner'] }}</td><td class="px-4 py-2.5 text-xs">{{ $t['due'] }}</td><td class="px-4 py-2.5 text-xs font-medium">{{ $t['status'] }}</td></tr>@endforeach</x-ta.table>
    @else
        <ul class="space-y-2">@foreach ($activeRows as $o)<li class="flex flex-col gap-1 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] sm:flex-row sm:justify-between"><div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $o['task'] }}</p><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $o['note'] }}</p></div><span class="text-xs font-semibold">{{ $o['state'] }}</span></li>@endforeach</ul>
        <p class="text-xs text-gray-500">{{ $isTr ? 'Sonuçlar gözlemsel dille yazılır; görev değişikliğe neden oldu diye otomatik nedensellik kurulmaz.' : 'Outcomes use observational language; they do not automatically claim the task caused the change.' }}</p>
    @endif
</div>

@if ($selectedFinding)
    <x-demo.gads-drawer :title="$selectedFinding['title']" :subtitle="$selectedFinding['category'] ?? null" :severity="$selectedFinding['severity'] ?? null">
        @foreach (['what' => ($isTr ? 'Ne oldu?' : 'What happened'), 'why' => ($isTr ? 'Neden önemli?' : 'Why this matters'), 'scope' => ($isTr ? 'Kapsam' : 'Scope'), 'evidence' => ($isTr ? 'Kanıt' : 'Evidence'), 'next' => ($isTr ? 'Önerilen sonraki adım' : 'Recommended next action')] as $key => $label)
            @if (! empty($selectedFinding[$key]))<div><p class="text-xs text-gray-400">{{ $label }}</p><p class="text-gray-800 dark:text-white/90">{{ $selectedFinding[$key] }}</p></div>@endif
        @endforeach
        <p class="text-[11px] text-gray-400">{{ $isTr ? 'Görevler otomatik oluşturulmaz; Google Ads’e otomatik yazma yapılmaz.' : 'Tasks are not auto-created and no automatic Google Ads write is performed.' }}</p>
    </x-demo.gads-drawer>
@endif
