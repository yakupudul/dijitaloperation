{{-- Ticari şerit (Aşama 3): one line, read from Satış › Müşteriler; the numbers are edited there. --}}
<div data-brand-commercial>
    @if ($row !== null)
        @php $a = $row['agreement']; $pm = $row['this_month']; $n = fn (int $v): string => number_format($v, 0, ',', '.'); @endphp
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-gray-600 dark:text-gray-300">
            @if ($a === null)
                <a href="{{ route('operator.brand', ['brand' => $row['brand']->id, 'tab' => 'ayarlar']) }}" wire:navigate class="font-semibold text-error-600 hover:underline" data-brand-commercial-agreement>Anlaşma girilmedi</a>
            @else
                <span class="font-semibold text-gray-900 dark:text-white" data-brand-commercial-agreement>{{ $a->feeLabel() }}</span>
                @if ($row['month_no'])<span>{{ $row['month_no'] }}. ay</span>@endif
            @endif
            @if ($pm !== null)
                <span @class(['font-semibold text-error-600' => $pm->isOverdue(), 'text-success-700 dark:text-success-400' => $pm->isPaid()])>{{ $pm->periodLabel() }}: {{ $pm->isPaid() ? 'ödendi' : ($pm->isOverdue() ? $pm->daysLate().' gün gecikti' : 'vade '.$pm->due_on?->format('d.m')) }}</span>
            @endif
            @if ($a !== null && $a->kind !== 'one_time')
                <span>{{ \Carbon\CarbonImmutable::parse($row['report_month'].'-01')->locale('tr')->isoFormat('MMMM') }} raporu {{ $row['report'] !== null ? 'gönderildi' : 'gönderilmedi' }}</span>
            @endif
            @if ($row['contact_days'] !== null)
                <span @class(['font-semibold text-error-600' => $row['contact_days'] >= \App\Services\Sales\CustomerSignals::CONTACT_DAYS])>son temas {{ $row['contact_days'] === 0 ? 'bugün' : $row['contact_days'].' gün önce' }}</span>
            @endif
            @if ($row['setup']['total'] > 0 && $row['setup']['done'] < $row['setup']['total'])
                <span class="chip chip-flat h-5! px-1.5! text-[11px]!" title="{{ collect($row['setup']['items'])->map(fn ($i) => ($i['done'] ? '✓ ' : '○ ').$i['label'])->implode(' · ') }}">Kurulum {{ $row['setup']['done'] }}/{{ $row['setup']['total'] }}</span>
            @endif
            @foreach ($row['signals'] as $signal)
                <span class="chip chip-down h-5! px-1.5! text-[11px]!" title="{{ $signal['detail'] }}">{{ $signal['label'] }}</span>
            @endforeach
            <a href="{{ route('operator.sales', ['sekme' => 'adaylar', 'gorunum' => 'musteriler']) }}" wire:navigate class="link-neutral text-xs">Satış › Müşteriler →</a>
        </p>
    @endif
</div>
