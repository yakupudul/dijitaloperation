@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $tz = config('app.timezone');
    $outcomeButtons = ['contacted' => 'Ulaşıldı', 'appointment' => 'Randevu', 'sale' => 'Satış', 'junk' => 'Geçersiz', 'unreachable' => 'Ulaşılamadı'];
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← {{ $brand->name }}</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">Leadler ve sonuçları</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Markanın reklam ve formlarından gelen leadlerin klinikten öğrenilen sonucu. Leadleri form aracının ya da Meta Lead Center'ın dışa aktarma dosyasından yükleyin veya elle ekleyin; sonucu tek tıkla işaretleyin. Bu bir CRM değildir: hasta, randevu takvimi ya da satış hattı tutulmaz; ad, telefon ve e-posta saklanmaz.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif

    <section class="{{ $card }}">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Lead kalitesi</h2>
            <select wire:model.live="days" aria-label="Dönem" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                <option value="30">Son 30 gün</option>
                <option value="90">Son 90 gün</option>
            </select>
        </div>
        <x-operator.lead-quality :quality="$quality" :days="$periodDays" />
    </section>

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['new' => 'Sonuç bekleyen', 'all' => 'Hepsi'] + array_diff_key($statuses, ['new' => true]) as $key => $label)
            <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset', 'bg-brand-50 text-brand-700 ring-brand-300' => $status === $key, 'text-gray-600 ring-gray-300' => $status !== $key])>{{ $label }}@if ($key !== 'all' && isset($counts[$key])) ({{ $counts[$key] }})@endif</button>
        @endforeach
    </div>

    <section class="{{ $card }}">
        @forelse ($leads as $lead)
            <div wire:key="lead-outcome-{{ $lead->id }}" class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 py-3 last:border-0 dark:border-gray-800">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                        {{ $lead->lead_received_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $lead->sourceLabel() }}
                        @if ($lead->contact_hint)<span class="text-gray-500">· {{ $lead->contact_hint }}</span>@endif
                    </p>
                    <p class="text-xs text-gray-500">
                        @if ($lead->campaign_label){{ $lead->campaign_label }} · @endif
                        <span @class(['font-medium', 'text-amber-700' => $lead->status === 'new', 'text-emerald-700' => in_array($lead->status, \App\Models\LeadOutcome::QUALIFIED, true)])>{{ $lead->statusLabel() }}</span>
                        @if ($lead->marked_at) · {{ $lead->marked_at->timezone($tz)->format('d.m.Y') }}@if ($lead->marker) · {{ $lead->marker->name }}@endif @endif
                        @if ($lead->value_try !== null) · {{ number_format((float) $lead->value_try, 0, ',', '.') }} ₺@endif
                    </p>
                    @if ($lead->note)<p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $lead->note }}</p>@endif
                    @if ($editing === $lead->id)
                        <div class="mt-2 flex flex-wrap items-end gap-2">
                            <label class="text-xs text-gray-500">Değer (TL)<input type="text" inputmode="decimal" wire:model="values.{{ $lead->id }}" class="{{ $input }} w-32" placeholder="ör. 12500"></label>
                            <label class="min-w-[16rem] flex-1 text-xs text-gray-500">Not<input type="text" maxlength="500" wire:model="notes.{{ $lead->id }}" class="{{ $input }}"></label>
                            <x-ta.button type="button" size="sm" wire:click="saveDetails({{ $lead->id }})">Kaydet</x-ta.button>
                            <button type="button" wire:click="$set('editing', null)" class="text-xs text-gray-500 hover:underline">Vazgeç</button>
                        </div>
                        @error('values.'.$lead->id)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ($outcomeButtons as $key => $label)
                        <button type="button" wire:click="mark({{ $lead->id }}, '{{ $key }}')" @class(['rounded-md px-2 py-1 text-xs ring-1 ring-inset', 'bg-brand-500 text-white ring-brand-500' => $lead->status === $key, 'text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $lead->status !== $key])>{{ $label }}</button>
                    @endforeach
                    <button type="button" wire:click="edit({{ $lead->id }})" class="px-1 text-xs text-brand-600 hover:underline">Değer / not</button>
                    <button type="button" wire:click="remove({{ $lead->id }})" wire:confirm="Bu lead kaydı silinsin mi?" class="px-1 text-xs text-gray-400 hover:text-rose-600">Sil</button>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Bu filtrede lead yok.</p>
        @endforelse
        <div class="mt-3">{{ $leads->links() }}</div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Dosyadan yükle</h2>
            <p class="mt-1 text-xs text-gray-500">CSV / TSV (UTF-8 veya Meta Lead Center'ın UTF-16 dosyası). Tanınan sütunlar: id, created_time / tarih, campaign_name / form_name, full_name, phone_number. Aynı dosya tekrar yüklenirse var olan leadler atlanır.</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <label class="text-xs text-gray-500">Kaynak
                    <select wire:model="importSource" class="{{ $input }}">@foreach ($sources as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="text-xs text-gray-500">Dosya<input type="file" wire:model="importFile" accept=".csv,.tsv,.txt" class="mt-1 block w-full text-sm"></label>
            </div>
            @error('importFile')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            @error('importSource')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            <x-ta.button type="button" size="sm" class="mt-3" wire:click="import" wire:loading.attr="disabled">Yükle</x-ta.button>
        </section>

        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Elle ekle</h2>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label class="text-xs text-gray-500">Kaynak
                    <select wire:model="manual.lead_source" class="{{ $input }}">@foreach ($sources as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="text-xs text-gray-500">Geliş tarihi<input type="date" wire:model="manual.lead_received_at" class="{{ $input }}"></label>
                <label class="text-xs text-gray-500">Kampanya / form<input type="text" maxlength="160" wire:model="manual.campaign_label" class="{{ $input }}"></label>
                <label class="text-xs text-gray-500">Eşleştirme ipucu<input type="text" maxlength="40" wire:model="manual.contact_hint" class="{{ $input }}" placeholder="ör. A.Y. ••4512 (tam ad/telefon yazmayın)"></label>
            </div>
            @error('manual.lead_source')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            @error('manual.lead_received_at')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            <x-ta.button type="button" size="sm" class="mt-3" wire:click="addManual">Ekle</x-ta.button>
        </section>
    </div>
</div>
