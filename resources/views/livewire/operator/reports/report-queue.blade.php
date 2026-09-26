@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
@endphp
<div class="space-y-5" wire:poll.60s>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Rapor kuyruğu</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Bir ayın raporları tüm markalar için tek listede. Eksikleri hazırlayın, AI yorumunu kontrol edin, sonra seçtiklerinizi tek seferde yayınlayıp müşterilere gönderin.</p>
        </div>
        <label class="text-sm"><span class="block text-xs text-gray-500">Ay</span>
            <select wire:model.live="month" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($months as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
    </div>

    <div class="grid gap-3 sm:grid-cols-4">
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Hazırlanmamış</div><div class="text-xl font-semibold">{{ $counts['missing'] }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Taslak (kontrol bekliyor)</div><div class="text-xl font-semibold">{{ $counts['draft'] }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Yayında, gönderilmedi</div><div class="text-xl font-semibold">{{ $counts['published'] }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Müşteriye gönderildi</div><div class="text-xl font-semibold text-success-600">{{ $counts['sent'] }}</div></div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        @if ($counts['missing'] > 0)
            <button type="button" wire:click="prepareMissing" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Eksik {{ $counts['missing'] }} raporu hazırla</button>
        @endif
        @if ($selectable !== [])
            <button type="button" wire:click="$set('bulkIds', @js($selectable))" class="text-xs text-brand-600 hover:underline">Hepsini seç</button>
        @endif
        @if ($bulkIds !== [])
            <span class="text-sm font-medium">{{ count($bulkIds) }} rapor seçili</span>
            <button type="button" wire:click="publishSelected" class="rounded-lg bg-white px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-300 dark:bg-gray-800">Yayınla</button>
            <button type="button" wire:click="sendSelected" wire:confirm="Seçili raporlar yayınlanıp müşterilere e-postalansın mı?" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">Yayınla ve gönder</button>
        @endif
    </div>

    <section class="{{ $card }} overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs text-gray-500"><tr><th class="w-8 px-4 py-3"></th><th class="py-3">Marka</th><th class="py-3">Rapor</th><th class="py-3">AI yorumu</th><th class="py-3">Gönderim</th><th class="px-4 py-3"></th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($rows as $row)
                    @php $report = $row['report']; @endphp
                    <tr wire:key="rq-{{ $row['brand']->id }}">
                        <td class="px-4 py-2">@if ($report)<input type="checkbox" value="{{ $report->id }}" wire:model.live="bulkIds" aria-label="Seç" class="size-4 rounded border-gray-300">@endif</td>
                        <td class="py-2"><div class="font-medium text-gray-800 dark:text-gray-200">{{ $row['brand']->name }}</div><div class="text-xs text-gray-500">{{ $row['brand']->customer?->name }}</div></td>
                        <td class="py-2 text-xs">{{ $report === null ? 'Hazırlanmadı' : ($report->status === 'published' ? 'Yayında' : 'Taslak') }}</td>
                        <td class="py-2 text-xs">{{ match ($report?->commentary_status) { 'ready' => 'Hazır', 'queued' => 'Yazılıyor…', 'failed' => 'Yazılamadı', default => $report ? 'Yok' : '—' } }}</td>
                        <td class="py-2 text-xs">
                            @if ($report?->emailed_at)<span class="text-success-600">{{ $report->emailed_at->format('d.m H:i') }} · {{ $report->emailed_to }}</span>
                            @elseif ($report?->send_error)<span class="text-error-600">Gönderilemedi: {{ $report->send_error }}</span>
                            @else — @endif
                        </td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('operator.reports.monthly', ['brand' => $row['brand']->id, 'month' => $month]) }}" wire:navigate class="text-xs text-brand-600 hover:underline">Aç ve düzenle</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
