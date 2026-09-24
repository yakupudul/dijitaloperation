@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Grafik notları</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Google algoritma güncellemeleri ve mevzuat / platform kuralı değişiklikleri tüm markaların raporunda görünür; kampanya ve site değişikliği notları yalnız kendi markasında. Notlar aylık rapor grafiklerinde kesikli çizgi ve "Bu ay dikkat edilecek olaylar" listesi olarak çıkar. Yöntemleri etkileyen değişiklikleri Yöntem Kütüphanesi'nde eşiklere de işleyin.</p>
    </div>
    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Not ekle</h2>
        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="text-sm"><span class="text-xs text-gray-500">Tür</span><select wire:model.live="form.kind" class="{{ $input }}">@foreach ($kinds as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Marka {{ in_array($form['kind'], \App\Services\MonthlyReport\ChartAnnotations::GLOBAL_KINDS, true) ? '(boş = tüm markalar)' : '' }}</span><select wire:model="form.brand_id" class="{{ $input }}"><option value="">Tüm markalar</option>@foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Başlangıç</span><input type="date" wire:model="form.starts_on" class="{{ $input }}"></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Bitiş (isteğe bağlı)</span><input type="date" wire:model="form.ends_on" class="{{ $input }}"></label>
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Başlık</span><input type="text" wire:model="form.title" placeholder="Mart 2026 core update" class="{{ $input }}"></label>
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Kaynak bağlantısı</span><input type="url" wire:model="form.source_url" class="{{ $input }}"></label>
            <label class="text-sm sm:col-span-2 lg:col-span-4"><span class="text-xs text-gray-500">Not</span><textarea rows="2" wire:model="form.note" class="{{ $input }}"></textarea></label>
        </div>
        @foreach ($errors->all() as $err)<p class="mt-1 text-xs text-rose-600">{{ $err }}</p>@endforeach
        <x-ta.button type="button" wire:click="save" size="sm" class="mt-3">Ekle</x-ta.button>
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Notlar</h2>
            <select wire:model.live="filter" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"><option value="all">Hepsi</option><option value="global">Yalnız tüm markalar</option>@foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        </div>
        @forelse ($rows as $row)
            <div wire:key="ann-{{ $row->id }}" class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 py-2 text-sm last:border-0 dark:border-gray-800">
                <div>
                    <p><strong>{{ \Illuminate\Support\Carbon::parse($row->starts_on)->format('d.m.Y') }}@if ($row->ends_on)–{{ \Illuminate\Support\Carbon::parse($row->ends_on)->format('d.m.Y') }}@endif</strong> · {{ $kinds[$row->kind] ?? $row->kind }} · {{ $row->brand_id ? ($brands[$row->brand_id] ?? 'Marka #'.$row->brand_id) : 'Tüm markalar' }}</p>
                    <p class="text-gray-800 dark:text-gray-200">{{ $row->title }}@if ($row->source_url) <a href="{{ $row->source_url }}" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600">kaynak</a>@endif</p>
                    @if ($row->note)<p class="text-xs text-gray-500">{{ $row->note }}</p>@endif
                </div>
                <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="Not silinsin mi?" class="text-xs text-gray-500 hover:text-rose-600">Sil</button>
            </div>
        @empty
            <p class="mt-2 text-sm text-gray-500">Not yok.</p>
        @endforelse
    </section>
</div>
