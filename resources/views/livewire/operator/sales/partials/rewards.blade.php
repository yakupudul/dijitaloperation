{{-- Referans ödülleri (yakup 2026-11-08): who referred whom, what it earned, whether it was given. --}}
@php $fmt = fn (int $v): string => number_format($v, 0, ',', '.'); @endphp
<div class="section-head mt-8" data-sales-rewards>
    <h2 class="section-title">Referans ödülleri</h2>
    <p class="text-sm text-gray-500">Referansla gelen kayıt müşteri olup ikinci aylık ödemesini yapınca ödül hak edilir. Referans müşterimizse 1 aylık hizmeti hediye (sonraki ödemesi 0 ₺ olur), değilse yeni müşterinin ilk ödemesinin %10'u, bir kez.</p>
</div>
<div class="card overflow-x-auto">
    <table class="data-table">
        <thead><tr><th>Referans</th><th>Getirdiği</th><th>Ödül</th><th class="text-right">Tutar</th><th>Durum</th><th></th></tr></thead>
        <tbody>
            @forelse ($rewards as $reward)
                <tr wire:key="reward-{{ $reward->id }}" data-sales-reward="{{ $reward->id }}">
                    <td class="font-medium text-gray-900 dark:text-white">{{ $reward->referrerName() }}<span class="block text-[11px] font-normal text-gray-500">{{ $reward->referrer_customer_id ? 'Müşterimiz' : 'Referanslar listesi' }}</span></td>
                    <td>@if ($reward->lead)<a href="{{ route('operator.sales.lead', ['lead' => $reward->lead_id]) }}" wire:navigate class="link-neutral">{{ $reward->customer?->name ?? $reward->lead->title() }}</a>@else{{ $reward->customer?->name ?? '–' }}@endif</td>
                    <td class="text-gray-600 dark:text-gray-300">{{ $reward->kindLabel() }}</td>
                    <td class="text-right tabular-nums">{{ $reward->amount ? $fmt((int) $reward->amount).' ₺' : '–' }}</td>
                    <td><span @class(['chip', 'chip-accent' => $reward->status === 'earned', 'chip-up' => $reward->status === 'paid', 'chip-flat' => in_array($reward->status, ['waiting', 'void'], true)])>{{ $reward->statusLabel() }}</span>@if ($reward->paid_at)<span class="block text-[11px] text-gray-400">{{ $reward->paid_at->timezone('Europe/Istanbul')->format('d.m.Y') }}</span>@endif</td>
                    <td class="whitespace-nowrap text-right">
                        @if ($reward->status === 'earned')
                            <button type="button" wire:click="giveReward({{ $reward->id }})" wire:confirm="{{ $reward->kind === 'gift_month' ? 'Hediye ay, referans veren müşterinin sonraki ödenmemiş ayına 0 ₺ olarak işlensin mi?' : 'Komisyon ödendi olarak işaretlensin mi?' }}" class="btn h-7 px-2.5 text-[12px]" data-sales-reward-give>{{ $reward->kind === 'gift_month' ? 'Hediye ayı uygula' : 'Ödendi' }}</button>
                        @endif
                        @if (in_array($reward->status, ['waiting', 'earned'], true))
                            <button type="button" wire:click="voidReward({{ $reward->id }})" wire:confirm="Bu ödül düşürülsün mü?" class="btn-quiet h-7 px-2 text-[12px]">Düşür</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-gray-500">Henüz referans ödülü yok. Bir kaydın Kimden alanında Referans seçince ve kayıt müşteri olunca burada görünür.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
