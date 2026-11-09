{{-- Rapor › Karne (yakup 2026-11-08): every closed deal in one line of facts, Claude's verdict, and what the brain learned. --}}
<section class="mt-8" data-sales-karne>
    <div class="section-head">
        <h2 class="section-title">Karne <span class="section-count">{{ $karneler->count() }}</span></h2>
        <p class="text-sm text-gray-500">Son kapanan görüşmeler: ne kadar sürdü, kaç temas, nereden geldi, neden bitti. Claude her birinden ders çıkarır; onaylarsan beyne girer.
            <button type="button" wire:click="$set('tab', 'ayarlar')" class="link-primary">Satış beyni{{ $learning['proposed'] > 0 ? ' · '.$learning['proposed'].' öneri onay bekliyor' : '' }}{{ $learning['questions'] > 0 ? ' · '.$learning['questions'].' soru' : '' }}</button>
        </p>
    </div>
    <div class="card divide-y divide-gray-200 dark:divide-gray-800">
        @forelse ($karneler as $row)
            @php $k = $row['karne']; $l = $row['lead']; @endphp
            <div class="px-4 py-3" wire:key="karne-{{ $l->id }}" data-sales-karne-row="{{ $l->id }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <a href="{{ route('operator.sales.lead', ['lead' => $l->id]) }}" wire:navigate class="text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $l->title() }}</a>
                    <span @class(['chip', 'chip-up' => $k['outcome'] === 'won', 'chip-down' => $k['outcome'] !== 'won'])>{{ $k['outcome'] === 'won' ? 'Kazanıldı' : 'Olumsuz' }}</span>
                </div>
                <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">{{ $k['line'] }}</p>
                @if ($l->lesson_verdict)
                    <p class="mt-1 text-[13px] text-gray-800 dark:text-gray-200"><span class="text-xs text-gray-500">Claude:</span> {{ $l->lesson_verdict }}</p>
                @elseif (in_array($l->lesson_status, ['queued', 'running', 'waiting'], true))
                    <p class="mt-1 text-xs text-gray-500">Claude bu görüşmeden ders çıkarıyor.</p>
                @endif
            </div>
        @empty
            <p class="px-4 py-3 text-sm text-gray-500">Henüz kapanan görüşme yok.</p>
        @endforelse
    </div>
</section>
