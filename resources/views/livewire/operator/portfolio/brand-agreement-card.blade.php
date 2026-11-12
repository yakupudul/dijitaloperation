@php
    use App\Models\Agreement;
    use App\Models\SalesLead;

    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $a = $terms['agreement'];
@endphp
{{-- Anlaşma (2026-11-07): aylık / dönemlik / tek seferlik, the fee, the due day, the monthly ticks. Shared with Satış. --}}
<section id="anlasma" data-brand-agreement>
    <div class="section-head">
        <h2 class="section-title" title="Bu marka bizimle nasıl çalışıyor: aylık, dönemlik ya da tek seferlik; ücret, ödeme günü ve ay ay ödeme tikleri. Satış › Müşteriler aynı sayıları gösterir.">Anlaşma</h2>
        @unless ($editing)
            <button type="button" wire:click="startNew" class="btn ml-auto" data-brand-agreement-new>{{ $a === null ? 'Anlaşma gir' : 'Yeni anlaşma' }}</button>
        @endunless
    </div>
    @if ($message !== '')
        <p role="status" class="mb-3 text-[13px] text-gray-600 dark:text-gray-300">{{ $message }}</p>
    @endif
    @if ($editing)
        <form wire:submit="save" class="card space-y-4 p-5" data-brand-agreement-form>
            <div class="flex flex-wrap gap-1.5">
                @foreach (Agreement::KINDS as $key => $label)
                    <button type="button" wire:click="$set('form.kind', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $form['kind'] === $key]) data-brand-agreement-kind="{{ $key }}">{{ $label }}</button>
                @endforeach
                <span class="self-center text-xs text-gray-400">{{ ['monthly' => 'Her ay, durdurulana kadar.', 'term' => 'Belirli ay sayısı boyunca her ay (6 aylık kampanya).', 'one_time' => 'Bir kez (site yapımı gibi).', 'partnership' => 'Gelir paylaşımı; sabit ücret yoksa 0 bırak.', 'yearly' => 'Yılda bir (alan adı, sunucu); süren anlaşmanın yanında durur.'][$form['kind']] ?? '' }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <label class="block text-xs text-gray-500">{{ $form['kind'] === 'one_time' ? 'Tutar (₺, KDV hariç)' : ($form['kind'] === 'yearly' ? 'Yıllık ücret (₺, KDV hariç)' : 'Aylık ücret (₺, KDV hariç)') }}<input wire:model="form.fee" type="text" inputmode="numeric" placeholder="14.000" class="mt-1 {{ $input }}" data-brand-agreement-fee />@error('fee')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                @if ($form['kind'] === 'term')
                    <label class="block text-xs text-gray-500">Dönem (ay)<input wire:model="form.term_months" type="number" min="1" max="36" class="mt-1 {{ $input }}" />@error('term_months')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                @endif
                <label class="block text-xs text-gray-500">Başlangıç<input wire:model="form.starts_on" type="date" class="mt-1 {{ $input }}" />@error('starts_on')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                @if ($form['kind'] !== 'one_time')
                    <label class="block text-xs text-gray-500">Ödeme günü (ayın)<input wire:model="form.due_day" type="number" min="1" max="28" class="mt-1 {{ $input }}" />@error('due_day')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                @endif
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                @foreach (SalesLead::SERVICES as $key => $label)
                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" wire:model="form.services" value="{{ $key }}" class="rounded border-gray-300" /> {{ $label }}</label>
                @endforeach
            </div>
            <input wire:model="form.note" type="text" placeholder="Not (ör. 6 ayda site hediye; KDV dahil anlaşıldı)" class="{{ $input }}" />
            <div class="flex items-center gap-2">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" data-brand-agreement-save>Kaydet</button>
                <button type="button" wire:click="cancel" class="btn">Vazgeç</button>
                @if ($editingId === 0 && $a !== null && ! in_array($form['kind'], \App\Services\Sales\Agreements::BESIDE_KINDS, true))<span class="text-xs text-gray-400">Yeni aylık / dönemlik anlaşma, sürenin bir gün öncesinde eskisini bitirir.</span>@endif
            </div>
        </form>
    @elseif ($a === null)
        <div class="card"><div class="card-row"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">Anlaşma girilmedi</p><p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Aylık mı, 6 aylık mı, tek seferlik mi; ücreti ve ödeme günü. Satış › Müşteriler ve aylık gelir bu bilgiden hesaplanır.</p></div><button type="button" wire:click="startNew" class="btn">Gir</button></div></div>
    @else
        <div class="card p-5" data-brand-agreement-current="{{ $a->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $a->feeLabel() }}</p>
                    <p class="text-[13px] text-gray-500">{{ $a->kindLabel() }} · {{ $a->statusLabel() }} · başlangıç {{ $a->starts_on?->format('d.m.Y') }}{{ $a->kind !== 'one_time' ? ' · ödeme günü '.$a->due_day : '' }}{{ $a->ends_on && $a->kind === 'term' ? ' · bitiş '.$a->ends_on->format('d.m.Y') : '' }}</p>
                    @if ($a->services)<p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300">{{ collect($a->services)->map(fn (string $s): string => SalesLead::SERVICES[$s] ?? $s)->implode(' · ') }}</p>@endif
                    @if ($a->note)<p class="mt-1 text-[13px] text-gray-500">{{ $a->note }}</p>@endif
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($terms['risks'] as $risk)<span class="chip chip-down" data-brand-agreement-risk="{{ $risk['key'] }}">{{ $risk['label'] }}</span>@endforeach
                    <button type="button" wire:click="edit({{ $a->id }})" class="btn h-8">Düzenle</button>
                    @if ($a->isActive())
                        <button type="button" wire:click="setStatus({{ $a->id }}, 'paused')" class="btn-quiet h-8">Durdur</button>
                        <button type="button" wire:click="setStatus({{ $a->id }}, 'ended')" wire:confirm="Anlaşma bugün bitsin mi? Sonraki ayların ödemeleri silinir, ödenenler kalır." class="btn-quiet h-8">Bitir</button>
                    @elseif ($a->status === 'paused')
                        <button type="button" wire:click="setStatus({{ $a->id }}, 'active')" class="btn-quiet h-8">Sürdür</button>
                    @endif
                </div>
            </div>
            @if ($terms['payments']->isNotEmpty())
                <div class="mt-4 flex flex-wrap items-center gap-1.5" data-brand-agreement-payments>
                    <span class="mr-1 text-[12px] text-gray-400">Ödemeler:</span>
                    @foreach ($terms['payments'] as $p)
                        <button type="button" wire:click="togglePaid({{ $p->id }})" @class(['seg h-8 text-[12px]', 'seg-active' => $p->isPaid(), 'border-error-300 text-error-700 dark:border-error-500/40 dark:text-error-300' => $p->isOverdue()]) title="{{ $p->periodLabel() }} · {{ number_format($p->amount, 0, ',', '.') }} ₺ · {{ $p->isPaid() ? 'ödendi '.$p->paid_on?->format('d.m') : ($p->isOverdue() ? $p->daysLate().' gün gecikti' : 'vade '.$p->due_on?->format('d.m')) }}" data-brand-payment="{{ $p->period }}">
                            {{ $p->isPaid() ? '✓' : ($p->isOverdue() ? '!' : '○') }} {{ \Carbon\CarbonImmutable::parse($p->period.'-01')->locale('tr')->isoFormat('MMM YY') }}
                        </button>
                    @endforeach
                    @if ($terms['next_due'] !== null)<span class="text-[12px] text-gray-400">· sonraki vade {{ $terms['next_due']->format('d.m.Y') }}</span>@endif
                </div>
            @endif
            @foreach ($terms['one_time'] as $job)
                <p class="mt-3 text-[13px] text-gray-600 dark:text-gray-300" data-brand-agreement-one-time="{{ $job->id }}">Tek seferlik: <span class="font-medium text-gray-900 dark:text-white">{{ $job->feeLabel() }}</span> · {{ $job->statusLabel() }} · {{ $job->starts_on?->format('d.m.Y') }}@foreach ($job->payments as $p) · <button type="button" wire:click="togglePaid({{ $p->id }})" class="link-neutral">{{ $p->isPaid() ? '✓ ödendi' : ($p->isOverdue() ? '! '.$p->daysLate().' gün gecikti' : '○ ödenmedi') }}</button>@endforeach <button type="button" wire:click="edit({{ $job->id }})" class="link-neutral">düzenle</button></p>
            @endforeach
            @if ($history->isNotEmpty())
                <p class="mt-3 text-[12px] text-gray-400">Önceki: {{ $history->map(fn (Agreement $h): string => $h->feeLabel().' ('.$h->starts_on?->format('m.Y').' – '.($h->ends_on?->format('m.Y') ?? '?').')')->implode(' · ') }}</p>
            @endif
        </div>
    @endif
</section>
