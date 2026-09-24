@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $running = $rows->contains('status', 'queued');
@endphp
<div class="space-y-5" @if ($running) wire:poll.10s @endif>
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">AI görünürlüğü</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Müşterinin soracağı gibi sorular (marka adı geçmeden) AI asistanına sorulur; cevapta marka öneriliyor mu, kaçıncı sırada, hangi rakipler geçiyor. Sonuç, Ayarlar › AI'da seçili modelin kendi bilgisidir; canlı web araması değildir ve insanların kullandığı her asistanı temsil etmez. Her soru bir AI çağrısıdır (AI bütçesinden); yalnız tıklayınca çalışır.</p>
    </div>
    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif

    <label class="block max-w-sm text-sm"><span class="text-xs text-gray-500">Marka</span>
        <select wire:model.live="brand" class="{{ $input }}">@foreach ($brands as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select>
    </label>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Sorular (satır başına bir, en fazla {{ $max }})</h2>
        <textarea rows="5" wire:model="prompts" class="{{ $input }}" placeholder="Kadıköy bölgesinde implant için hangi yeri önerirsin?"></textarea>
        <div class="mt-2 flex flex-wrap gap-2">
            <x-ta.button type="button" wire:click="check" size="sm">Kontrol et (AI)</x-ta.button>
            <x-ta.button type="button" wire:click="savePrompts" size="sm" variant="outline">Soruları kaydet</x-ta.button>
        </div>
    </section>

    @if ($history !== [])
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Kontroller</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($history as $h)
                    <button type="button" wire:click="$set('batch', '{{ $h['batch'] }}')" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset', 'bg-brand-50 text-brand-700 ring-brand-300' => $batch === $h['batch'], 'text-gray-600 ring-gray-300' => $batch !== $h['batch']])>
                        {{ \Illuminate\Support\Carbon::parse($h['at'])->format('d.m.Y') }} · {{ $h['rate'] !== null ? '%'.$h['rate'].' ('.$h['mentioned'].'/'.$h['done'].')' : 'sürüyor' }}
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    @if ($rows->isNotEmpty())
        <section class="{{ $card }}">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($rows as $row)
                    <details wire:key="aiv-{{ $row->id }}" class="py-2 text-sm">
                        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                            <span class="text-gray-800 dark:text-gray-200">{{ $row->prompt }}</span>
                            <span class="text-xs">
                                @if ($row->status === 'queued')<span class="text-gray-500">bekliyor…</span>
                                @elseif ($row->status === 'failed')<span class="text-rose-700">hata</span>
                                @elseif ($row->mentioned)<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">önerildi{{ $row->position ? ' · '.$row->position.'. sırada' : '' }}</span>
                                @else<span class="rounded-full bg-rose-50 px-2 py-0.5 text-rose-700">geçmiyor</span>@endif
                            </span>
                        </summary>
                        @if ($row->status === 'done')
                            @php
                                $businesses = (array) json_decode((string) $row->businesses, true);
                                $competitors = (array) json_decode((string) $row->competitors_mentioned, true);
                            @endphp
                            @if ($businesses !== [])<p class="mt-1 text-xs text-gray-600">Önerilenler: {{ implode(' · ', $businesses) }}</p>@endif
                            @if ($competitors !== [])<p class="mt-1 text-xs text-amber-700">Rakipler: {{ implode(', ', $competitors) }}</p>@endif
                            <p class="mt-1 whitespace-pre-line text-xs text-gray-500">{{ $row->answer }}</p>
                            <p class="mt-1 text-[11px] text-gray-400">{{ $row->provider }} · {{ $row->model }}</p>
                        @elseif ($row->status === 'failed')
                            <p class="mt-1 text-xs text-rose-700">{{ $row->error }}</p>
                        @endif
                    </details>
                @endforeach
            </div>
        </section>
    @endif
</div>
