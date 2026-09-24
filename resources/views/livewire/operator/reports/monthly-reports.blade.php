@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
@endphp
<div class="space-y-5" @if ($report?->commentary_status === 'queued') wire:poll.5s @endif>
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Aylık rapor</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Looker raporunun yerine: kanal rakamları (önceki ay ve geçen yılın aynı ayıyla), günlük grafikler, dönüşümler, yerel görünürlük, bu ay yapılanlar ve ölçülen etkileri, gelecek ay. AI yorumu yalnız tıklayınca yazılır ve yayımlamadan önce düzenlenir. Müşteriye imzalı bir bağlantı gönderilir; otomatik gönderim yok.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif

    <div class="flex flex-wrap items-end gap-3">
        <label class="block min-w-64 text-sm"><span class="text-xs text-gray-500">Marka</span>
            <select wire:model.live="brand" class="{{ $input }}">@foreach ($brands as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select>
        </label>
        <label class="block text-sm"><span class="text-xs text-gray-500">Ay</span>
            <select wire:model.live="month" class="{{ $input }}">@foreach ($months as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
        </label>
        <x-ta.button type="button" wire:click="prepare" size="sm">{{ $report === null ? 'Raporu hazırla' : 'Rakamları yenile' }}</x-ta.button>
        @if ($report !== null)
            <x-ta.button type="button" wire:click="writeCommentary" size="sm" variant="outline">{{ $report->commentary_status === 'queued' ? 'AI yazıyor…' : 'AI yorumu yaz' }}</x-ta.button>
            <x-ta.button type="button" wire:click="startEdit" size="sm" variant="outline">Yorumu düzenle</x-ta.button>
            <x-ta.button :href="route('operator.reports.monthly.preview', ['report' => $report->id])" target="_blank" size="sm" variant="outline">Önizle / yazdır</x-ta.button>
            <x-ta.button type="button" wire:click="publish" size="sm" variant="outline">{{ $report->status === 'published' ? 'Bağlantıyı yenile' : 'Yayımla ve bağlantı al' }}</x-ta.button>
        @endif
    </div>

    @if ($clientUrl !== null)
        <div class="{{ $card }}">
            <p class="text-xs text-gray-500">Müşteri bağlantısı</p>
            <p class="mt-1 break-all font-mono text-xs">{{ $clientUrl }}</p>
        </div>
    @endif
    @if ($report?->commentary_status === 'failed')
        <p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $report->commentary['error'] ?? 'AI yorumu yazılamadı.' }}</p>
    @endif

    @if ($editing)
        <section class="{{ $card }} space-y-3">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Yorum ve not</h2>
            <label class="block text-sm"><span class="text-xs text-gray-500">Ayın özeti</span><textarea rows="4" wire:model="edit.summary" class="{{ $input }}"></textarea></label>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="block text-sm"><span class="text-xs text-gray-500">İyi gidenler (satır başına bir)</span><textarea rows="5" wire:model="edit.wins" class="{{ $input }}"></textarea></label>
                <label class="block text-sm"><span class="text-xs text-gray-500">Takip edilecekler</span><textarea rows="5" wire:model="edit.watch" class="{{ $input }}"></textarea></label>
                <label class="block text-sm"><span class="text-xs text-gray-500">Gelecek ay</span><textarea rows="5" wire:model="edit.next" class="{{ $input }}"></textarea></label>
            </div>
            <label class="block text-sm"><span class="text-xs text-gray-500">Ajansın notu (isteğe bağlı)</span><textarea rows="3" wire:model="edit.note" class="{{ $input }}"></textarea></label>
            <div class="flex gap-2"><x-ta.button type="button" wire:click="saveEdit" size="sm">Kaydet</x-ta.button><x-ta.button type="button" wire:click="$set('editing', false)" size="sm" variant="outline">Vazgeç</x-ta.button></div>
        </section>
    @endif

    @if ($report !== null)
        <p class="text-xs text-gray-500">{{ $report->status === 'published' ? 'Yayımlandı '.$report->published_at?->format('d.m.Y H:i') : 'Taslak' }} · rakamlar {{ \Illuminate\Support\Carbon::parse($report->payload['built_at'] ?? $report->updated_at)->format('d.m.Y H:i') }} tarihinde hazırlandı{{ ($report->commentary['source'] ?? null) === 'llm' ? ' · yorum AI taslağı' : (($report->commentary['source'] ?? null) === 'operator' ? ' · yorum düzenlendi' : '') }}</p>
        @include('reports.monthly.styles')
        @include('reports.monthly.body', ['payload' => $report->payload, 'commentary' => $report->commentary_status === 'ready' ? $report->commentary : null, 'note' => $report->operator_note])
    @else
        <section class="{{ $card }} text-sm text-gray-500">Bu ay için rapor yok. "Raporu hazırla" kayıtlı veriden rakamları çıkarır (AI kullanmaz).</section>
    @endif

    @if ($recent->isNotEmpty())
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Önceki raporlar</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($recent as $row)
                    <button type="button" wire:click="$set('month', '{{ $row->month }}')" class="rounded-full px-3 py-1 text-xs ring-1 ring-inset ring-gray-300">{{ $row->month }} · {{ $row->status === 'published' ? 'yayında' : 'taslak' }}</button>
                @endforeach
            </div>
        </section>
    @endif
</div>
