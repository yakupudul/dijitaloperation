{{-- Komuta "Bağlantılar" box (2026-11-06): what is ready on the link desk today; the desk itself is on Bağlantılar. --}}
<div class="card" data-links-today>
    <a href="{{ route('operator.links') }}" wire:navigate class="flex items-center gap-3 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-white/[0.02]">
        <span class="min-w-0 flex-1">
            <span class="block text-base font-semibold text-gray-900 dark:text-white">Bağlantılar</span>
            <span class="block text-sm text-gray-500">
                @if ($ready + $person + $late === 0)
                    Bugün hazır başvuru yok · bu ay {{ $won }} doğrulanmış link
                @else
                    {{ collect([$ready > 0 ? $ready.' başvuru hazır' : null, $person > 0 ? $person.' telefon / ziyaret' : null, $late > 0 ? $late.' takip bekliyor' : null])->filter()->implode(' · ') }} · bu ay {{ $won }} doğrulanmış link
                @endif
            </span>
        </span>
        <span class="shrink-0 text-[13px] text-gray-500">Masaya git →</span>
    </a>
</div>
