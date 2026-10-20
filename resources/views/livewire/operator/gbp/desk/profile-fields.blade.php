@php
    // İşletme profilleri › Açıklama ve saatler (panel concept 2026-10-20). The question: "Which profiles lack a description,
    // holiday hours or basic info, and what goes to Google?" Each part opens with what is missing and the button that fixes
    // it, then one card per brand, one row per profile. A missing description is worth a look, not money loss: amber, not red.
    $tone = fn (string $state): string => match ($state) {
        'ok' => 'ok',
        'short', 'missing' => 'look',
        default => 'info',
    };
    $writeLine = function (?\App\Models\ExternalWriteAction $write): string {
        if ($write === null) {
            return '';
        }

        return $write->statusLabel().', '.$write->created_at?->locale('tr')->diffForHumans();
    };
    $input = 'rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:focus:ring-white/10';
    $check = 'h-4 w-4 shrink-0 rounded border-gray-300 accent-gray-900 dark:accent-white';
    $row = 'border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800';
    $sections = ['aciklama' => 'Açıklama', 'saatler' => 'Özel gün saatleri', 'bilgiler' => 'Bilgiler'];
    $sub = match ($section) {
        'aciklama' => $weak === 0 && $proposals === 0
            ? 'Açıklamalar yeterli uzunlukta.'
            : trim(($weak > 0 ? $weak.' açıklama eksik ya da kısa' : '').($weak > 0 && $proposals > 0 ? ', ' : '').($proposals > 0 ? $proposals.' hazır öneri gönderilmeyi bekliyor' : '')).'.',
        'saatler' => $current ? ($missingHours > 0 ? $missingHours.' işletmede '.$current['name'].' saatleri eksik.' : $current['name'].' saatleri bütün işletmelerde girilmiş.') : 'Önümüzdeki '.\App\Services\Gbp\Desk\ProfileFields::HOLIDAY_WINDOW_DAYS.' günde resmî tatil yok.',
        default => 'Markanın verisinden doldurulabilenler her gece hazırlanır; telefon, kategori ve özellikleri sen seçersin.',
    };
@endphp
<div class="dark:text-gray-200" data-gbp-profile-fields>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-profile-fields', 'brandFilter' => $brand])

    <div class="space-y-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="page-title" title="Açıklama, Google’ın profili hangi hizmet ve bölge için göstereceğini anlamasına yardım eder (en çok {{ $max }} karakter, iletişim bilgisi yazılmaz). Resmî tatil ve bayram saatleri girilmezse Google “saatler doğru olmayabilir” uyarısı gösterir. İkisi de senin onayınla Google’a gider ve geri alınabilir.">İşletme profilleri</h1>
                <p class="page-sub">{{ $sub }}</p>
            </div>
            @include('livewire.operator.gbp.partials.brand-filter')
        </header>
        @include('livewire.operator.gbp.partials.desk-message')

        <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Açıklama ve saatler">
            @foreach ($sections as $key => $label)
                <button type="button" wire:click="setSection('{{ $key }}')" @class(['tab', 'tab-active' => $section === $key]) @if ($section === $key) aria-current="page" @endif>{{ $label }}</button>
            @endforeach
        </nav>

        @if ($section === 'aciklama')
            {{-- 1. What waits: weak descriptions and ready proposals, with the two bulk buttons. --}}
            @if ($weak > 0 || $proposals > 0)
                <section class="card">
                    <div class="card-row flex-wrap sm:flex-nowrap">
                        <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $weak > 0 ? 'Eksik ve kısa açıklamaları yapay zekâ yazsın' : 'Hazır önerileri oku ve gönder' }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500">İyi bir açıklama en az {{ \App\Services\Gbp\Desk\ProfileFields::DESCRIPTION_GOOD }} karakterdir. Öneriyi satırında okursun; Google’a senin onayınla gider ve geri alınabilir.</p>
                        </div>
                        @if ($canWrite)
                            <span class="grid w-full gap-2 sm:flex sm:w-auto">
                                @if ($weak > 0)<button type="button" wire:click="prepareWeak" class="btn">Eksik ve kısa olanları yaz ({{ $weak }})</button>@endif
                                @if ($proposals > 0)<button type="button" wire:click="sendProposals" wire:confirm="{{ $proposals }} hazır açıklama olduğu gibi Google’a gönderilsin mi? Önce okumak istersen satırları aç." class="btn-primary">Hazır önerileri gönder ({{ $proposals }})</button>@endif
                            </span>
                        @endif
                    </div>
                </section>
            @endif

            {{-- 2. One card per brand, one row per profile: length, state, the text itself. --}}
            @forelse ($groups as $brandName => $locations)
                <section wire:key="desc-brand-{{ md5((string) $brandName) }}">
                    <div class="section-head">
                        <h2 class="section-title flex min-w-0 items-center gap-2"><x-ta.brand-avatar :name="(string) $brandName" :logo="$locations->first()?->brand?->logo_url" size="sm" /> <span class="min-w-0 break-words">{{ $brandName }} <span class="section-count">{{ $locations->count() }}</span></span></h2>
                    </div>
                    <div class="card">
                        @foreach ($locations as $location)
                            @php
                                $d = $descriptions[$location->id];
                                $ai = $d['ai'];
                                $write = $d['last_write'];
                            @endphp
                            <div wire:key="desc-{{ $location->id }}" class="{{ $row }}">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                    <span class="min-w-0 basis-full truncate text-sm font-medium text-gray-900 sm:flex-1 sm:basis-0 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                    <span class="text-[13px] tabular-nums text-gray-500">{{ $d['length'] }} / {{ $max }}</span>
                                    <x-ta.pill :tone="$tone($d['state'])">{{ $d['label'] }}</x-ta.pill>
                                    @if ($canWrite && $editing !== (int) $location->id && $d['state'] !== 'no_data')
                                        <span class="-mr-2 flex w-full flex-wrap gap-0.5 sm:w-auto">
                                            <button type="button" wire:click="startEdit({{ $location->id }})" @class(['btn-quiet h-7! px-2!', 'text-gray-900! dark:text-white!' => $d['state'] === 'proposal'])>{{ $d['state'] === 'proposal' ? 'Oku / düzenle' : 'Düzenle' }}</button>
                                            <button type="button" wire:click="prepare({{ $location->id }})" @disabled(($ai['status'] ?? '') === 'running') class="btn-quiet h-7! px-2!">{{ $d['proposed'] ? 'Yeniden yaz' : 'Yapay zekâ ile yaz' }}</button>
                                            @if ($d['suggestion'] && $d['state'] === 'proposal')<button type="button" wire:click="dismiss({{ $d['suggestion']->id }})" class="btn-quiet h-7! px-2!">Öneriyi kaldır</button>@endif
                                        </span>
                                    @endif
                                </div>
                                @if ($ai && ($ai['status'] ?? '') !== 'ready')
                                    <p @class(['mt-1 text-[13px]', 'text-error-600 dark:text-error-400' => ($ai['status'] ?? '') === 'failed', 'text-gray-500' => ($ai['status'] ?? '') !== 'failed']) @if (($ai['status'] ?? '') === 'running') wire:poll.10s @endif>{{ ($ai['status'] ?? '') === 'running' ? ($ai['message'] ?? 'Yazılıyor…') : ($ai['message'] ?? '') }}</p>
                                @endif
                                @if ($write)
                                    <p class="mt-1 text-[13px] {{ $write->status === 'failed' ? 'text-error-600 dark:text-error-400' : 'text-gray-500' }}" @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                        Son gönderim: {{ $writeLine($write) }}@if ($write->status === 'failed' && $write->error). {{ \Illuminate\Support\Str::limit($write->error, 160) }}@endif
                                        @if ($canWrite && $write->isUndoable()) <button type="button" wire:click="undo({{ $write->id }})" wire:confirm="Önceki açıklama geri yüklensin mi?" class="link-neutral font-medium">Geri al</button>@endif
                                    </p>
                                @endif

                                @if ($editing === (int) $location->id)
                                    <div class="mt-3 grid gap-4 lg:grid-cols-2" x-data="{ text: @entangle('editText') }">
                                        <div>
                                            <p class="mb-1 text-[13px] text-gray-500">Google’a gidecek açıklama</p>
                                            <textarea x-model="text" rows="8" maxlength="{{ $max }}" class="w-full {{ $input }}"></textarea>
                                            <p class="mt-1 text-[13px] text-gray-500"><span x-text="text.length"></span> / {{ $max }} karakter, iyi bir açıklama en az {{ \App\Services\Gbp\Desk\ProfileFields::DESCRIPTION_GOOD }} karakterdir</p>
                                            <div class="mt-2 grid gap-2 sm:flex">
                                                <button type="button" wire:click="sendDescription" wire:confirm="Bu açıklama Google’daki profile yazılsın mı? (Geri alınabilir.)" class="btn-primary">Google’a gönder</button>
                                                <button type="button" wire:click="$set('editing', null)" class="btn-quiet">Vazgeç</button>
                                            </div>
                                        </div>
                                        <div>
                                            <p class="mb-1 text-[13px] text-gray-500">Şu an Google’da</p>
                                            <p class="whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm leading-6 text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $d['current'] !== '' ? $d['current'] : 'Açıklama yok.' }}</p>
                                        </div>
                                    </div>
                                @elseif ($d['state'] === 'proposal')
                                    <p class="mt-2 line-clamp-3 max-w-3xl whitespace-pre-line text-sm leading-6 text-gray-800 dark:text-gray-200">{{ $d['proposed'] }}</p>
                                @elseif ($d['current'] !== '')
                                    <p class="mt-1 line-clamp-2 max-w-3xl text-[13px] text-gray-500">{{ $d['current'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="card"><x-ta.empty-state size="sm" title="Operasyonel markaya bağlı İşletme Profili yok." /></div>
            @endforelse
        @elseif ($section === 'saatler')
            @if ($holidays === [])
                <div class="card"><x-ta.empty-state size="sm" :title="'Önümüzdeki '.\App\Services\Gbp\Desk\ProfileFields::HOLIDAY_WINDOW_DAYS.' günde resmî tatil yok.'" /></div>
            @else
                {{-- 1. Which holiday. --}}
                <nav class="no-scrollbar -mx-1 flex gap-2 overflow-x-auto px-1 pb-1" aria-label="Resmî tatil">
                    @foreach ($holidays as $h)
                        <button type="button" wire:click="$set('holiday', '{{ $h['key'] }}')" @class(['shrink-0 rounded-lg border bg-white px-3 py-2 text-left shadow-card dark:bg-white/[0.03]', 'border-gray-900 ring-1 ring-gray-900 dark:border-white dark:ring-white' => $h['key'] === $holiday, 'border-gray-200 hover:bg-gray-50 dark:border-gray-800' => $h['key'] !== $holiday])>
                            <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $h['name'] }}</span>
                            <span class="block text-[13px] text-gray-500">{{ \Illuminate\Support\Carbon::parse($h['dates'][0])->locale('tr')->translatedFormat('d M').(count($h['dates']) > 1 ? ' – '.\Illuminate\Support\Carbon::parse(end($h['dates']))->locale('tr')->translatedFormat('d M') : '') }}</span>
                        </button>
                    @endforeach
                </nav>

                @if ($current)
                    <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,22rem)_1fr]">
                        {{-- 2. The hours to send. --}}
                        <section class="lg:sticky lg:top-4">
                            <div class="section-head"><h2 class="section-title" title="Her gün için seç; “Değiştirme” seçilen gün Google’da olduğu gibi kalır. Yalnız bu tarihler değişir, haftalık saatler aynı kalır.">{{ $current['name'] }} saatleri</h2></div>
                            <div class="card">
                                @foreach ($current['dates'] as $date)
                                    <div wire:key="day-{{ $date }}" class="card-row flex-wrap gap-2 py-3">
                                        <span class="w-24 shrink-0 text-sm text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($date)->locale('tr')->translatedFormat('d M D') }}</span>
                                        <select wire:model.live="hours.{{ $date }}.mode" class="h-8 min-w-0 flex-1 {{ $input }} py-0 text-[13px]">
                                            <option value="closed">Kapalı</option>
                                            <option value="custom">Açık (saat gir)</option>
                                            <option value="skip">Değiştirme</option>
                                        </select>
                                        @if (($hours[$date]['mode'] ?? 'closed') === 'custom')
                                            <span class="flex w-full items-center gap-2 pl-26">
                                                <input type="time" wire:model="hours.{{ $date }}.open" class="h-8 {{ $input }} py-0 text-[13px]">
                                                <span class="text-gray-500">–</span>
                                                <input type="time" wire:model="hours.{{ $date }}.close" class="h-8 {{ $input }} py-0 text-[13px]">
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($canWrite)
                                    <div class="card-row bg-gray-50/70 dark:bg-white/[0.02]">
                                        <button type="button" wire:click="sendHours" wire:confirm="Bu saatler seçilen {{ count($selected) }} işletmenin Google profiline yazılsın mı? (Geri alınabilir.)" @disabled($selected === []) class="btn-primary w-full">Seçilenlere gönder ({{ count($selected) }})</button>
                                    </div>
                                @endif
                            </div>
                        </section>

                        {{-- 3. Which profiles miss them. --}}
                        <section class="min-w-0">
                            <div class="section-head">
                                <h2 class="section-title">İşletmeler <span class="section-count">{{ $missingHours }} eksik</span></h2>
                                @if ($canWrite)
                                    <span class="ml-auto flex gap-1">
                                        <button type="button" wire:click="$set('selected', {{ json_encode(collect($hourStates)->filter(fn ($h) => $h['missing'] !== [])->keys()->map(fn ($id) => (string) $id)->values()->all()) }})" class="btn-quiet h-7! px-2!">Eksik olanları seç</button>
                                        <button type="button" wire:click="$set('selected', [])" class="btn-quiet h-7! px-2!">Seçimi temizle</button>
                                    </span>
                                @endif
                            </div>
                            <div class="card">
                                @forelse ($groups as $brandName => $locations)
                                    <div wire:key="hb-{{ md5((string) $brandName) }}" class="flex min-w-0 items-center gap-2 border-t border-gray-200 bg-gray-50/70 px-4 py-2 first:border-t-0 dark:border-gray-800 dark:bg-white/[0.02]">
                                        <x-ta.brand-avatar :name="(string) $brandName" :logo="$locations->first()?->brand?->logo_url" size="sm" />
                                        <span class="min-w-0 truncate text-[13px] font-medium text-gray-900 dark:text-white">{{ $brandName }}</span>
                                        <span class="text-[13px] tabular-nums text-gray-500">{{ $locations->count() }}</span>
                                    </div>
                                    @foreach ($locations as $location)
                                        @php
                                            $h = $hourStates[$location->id] ?? null;
                                            $write = $hourWrites->get($location->id);
                                        @endphp
                                        <label wire:key="hl-{{ $location->id }}" class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 border-t border-gray-200 px-4 py-3 text-sm dark:border-gray-800">
                                            @if ($canWrite && $h)<input type="checkbox" wire:model.live="selected" value="{{ $location->id }}" class="{{ $check }}">@endif
                                            <span class="line-clamp-2 min-w-0 flex-1 break-words text-gray-900 sm:truncate dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                            @if ($h === null)
                                                <span class="text-[13px] text-gray-500">Profil verisi yok</span>
                                            @elseif ($h['missing'] === [])
                                                <x-ta.pill tone="ok">Girilmiş: {{ implode(', ', array_unique($h['set'])) }}</x-ta.pill>
                                            @else
                                                <x-ta.pill tone="look">{{ count($h['missing']) }} gün eksik</x-ta.pill>
                                            @endif
                                            @if ($write)
                                                <span class="w-full text-[13px] {{ $write->status === 'failed' ? 'text-error-600 dark:text-error-400' : 'text-gray-500' }}" @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                                    {{ data_get($write->request_payload, 'label', 'Özel gün saatleri') }}: {{ $writeLine($write) }}@if ($write->status === 'failed' && $write->error). {{ \Illuminate\Support\Str::limit($write->error, 160) }}@endif
                                                    @if ($canWrite && $write->isUndoable()) <button type="button" wire:click.prevent="undo({{ $write->id }})" wire:confirm="Bu tarihlerin önceki saatleri geri yüklensin mi?" class="link-neutral font-medium">Geri al</button>@endif
                                                </span>
                                            @endif
                                        </label>
                                    @endforeach
                                @empty
                                    <x-ta.empty-state size="sm" title="Operasyonel markaya bağlı İşletme Profili yok." />
                                @endforelse
                            </div>
                            <p class="mt-2 text-[13px] text-gray-500">Durum son toplanan profil verisinden okunur; gönderimden sonra bir sonraki toplamada güncellenir.</p>
                        </section>
                    </div>
                @endif
            @endif
        @else
            @forelse ($groups as $brandName => $locations)
                <section wire:key="info-brand-{{ md5((string) $brandName) }}">
                    <div class="section-head">
                        <h2 class="section-title flex min-w-0 items-center gap-2"><x-ta.brand-avatar :name="(string) $brandName" :logo="$locations->first()?->brand?->logo_url" size="sm" /> <span class="min-w-0 break-words">{{ $brandName }} <span class="section-count">{{ $locations->count() }}</span></span></h2>
                    </div>
                    <div class="card">
                        @foreach ($locations as $location)
                            @php
                                $snap = $snapshots[$location->id] ?? null;
                                $write = $infoWrites->get($location->id);
                                $ready = $prepared->get($location->id, collect());
                                $hoursRows = $snap ? \App\Services\Gbp\Desk\ProfileInfo::rows((array) $snap['regular_hours']) : [];
                            @endphp
                            <div wire:key="info-{{ $location->id }}" class="{{ $row }}">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="min-w-0 basis-full truncate text-sm font-medium text-gray-900 sm:flex-1 sm:basis-0 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                    @if ($ready->isNotEmpty())<x-ta.pill tone="info">{{ $ready->count() }} hazır bilgi</x-ta.pill>@endif
                                    @if ($canWrite && $snap && $infoEditing !== (int) $location->id)
                                        <button type="button" wire:click="startInfo({{ $location->id }})" class="btn-quiet -mr-2 h-7! px-2!">Düzenle</button>
                                    @endif
                                </div>
                                @if ($snap === null)
                                    <p class="mt-1 text-[13px] text-gray-500">Profil verisi yok.</p>
                                @else
                                    <dl class="mt-1.5 grid gap-x-6 gap-y-0.5 text-[13px] text-gray-700 sm:grid-cols-2 dark:text-gray-300">
                                        <div class="min-w-0"><dt class="inline text-gray-500">Kategori:</dt> <dd class="inline">{{ $snap['primary_category'] ?: '—' }}</dd></div>
                                        <div class="min-w-0"><dt class="inline text-gray-500">Telefon:</dt> <dd class="inline tabular-nums">{{ $snap['phone'] ?: '—' }}</dd></div>
                                        <div class="min-w-0 sm:col-span-2"><dt class="inline text-gray-500">Saatler:</dt> <dd @class(['inline', 'text-warning-700 dark:text-warning-400' => $hoursRows === []])>{{ $hoursRows !== [] ? \App\Services\Gbp\Desk\ProfileInfo::hoursText($hoursRows) : 'Girilmemiş' }}</dd></div>
                                        <div class="min-w-0 truncate sm:col-span-2"><dt class="inline text-gray-500">Web sitesi:</dt> <dd class="inline">{{ $snap['website'] ?: '—' }}</dd></div>
                                    </dl>
                                @endif
                                @foreach ($ready as $s)
                                    <div wire:key="prep-{{ $s->id }}" class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg bg-gray-50 px-3 py-2.5 text-[13px] dark:bg-white/[0.03]">
                                        <span class="min-w-0 flex-1 break-words"><span class="font-medium text-gray-900 dark:text-white">{{ \App\Services\Gbp\Desk\ProfileInfo::FIELD_LABELS[data_get($s->action, 'field')] ?? 'Bilgi' }}:</span> <span class="text-gray-700 dark:text-gray-300">{{ data_get($s->action, 'proposed') }}</span>
                                            <span class="block text-gray-500">{{ $s->reason }}</span></span>
                                        @if ($canWrite)
                                            <span class="flex gap-1">
                                                <button type="button" wire:click="sendPrepared({{ $s->id }})" wire:confirm="Bu bilgi Google’daki profile yazılsın mı? (Geri alınabilir.)" class="btn h-7! px-2.5!">Google’a gönder</button>
                                                <button type="button" wire:click="dismissPrepared({{ $s->id }})" class="btn-quiet h-7! px-2!">Kaldır</button>
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($write)
                                    <p class="mt-1.5 text-[13px] {{ $write->status === 'failed' ? 'text-error-600 dark:text-error-400' : 'text-gray-500' }}" @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                        {{ data_get($write->request_payload, 'label', 'Profil bilgisi') }}: {{ $writeLine($write) }}@if ($write->status === 'failed' && $write->error). {{ \Illuminate\Support\Str::limit($write->error, 160) }}@endif
                                        @if ($canWrite && $write->isUndoable()) <button type="button" wire:click="undo({{ $write->id }})" wire:confirm="Önceki değerler geri yüklensin mi?" class="link-neutral font-medium">Geri al</button>@endif
                                    </p>
                                @endif

                                @if ($infoEditing === (int) $location->id)
                                    <div class="mt-3 grid gap-5 rounded-lg border border-gray-200 bg-gray-50/60 p-4 lg:grid-cols-2 dark:border-gray-800 dark:bg-white/[0.02]">
                                        <div class="space-y-2">
                                            <p class="text-[13px] font-medium text-gray-500">Haftalık saatler</p>
                                            @foreach ($days as $day => $label)
                                                <div wire:key="wd-{{ $day }}" class="flex flex-wrap items-center gap-2 text-sm">
                                                    <label class="flex w-28 items-center gap-2"><input type="checkbox" wire:model.live="info.hours.{{ $day }}.open" class="{{ $check }}"> {{ $label }}</label>
                                                    @if ($info['hours'][$day]['open'] ?? false)
                                                        <input type="time" wire:model="info.hours.{{ $day }}.from" class="h-8 {{ $input }} py-0 text-[13px]">
                                                        <span class="text-gray-500">–</span>
                                                        <input type="text" wire:model="info.hours.{{ $day }}.to" placeholder="18:00" maxlength="5" class="h-8 w-20 {{ $input }} py-0 text-[13px]">
                                                    @else
                                                        <span class="text-[13px] text-gray-500">Kapalı</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                            <p class="text-[13px] text-gray-500">Gece yarısı kapanış için 24:00 yaz. Gün içinde birden çok aralık varsa tek aralıkla değiştirilir.</p>
                                        </div>
                                        <div class="space-y-3 text-sm">
                                            <label class="block"><span class="text-[13px] font-medium text-gray-500">Telefon</span>
                                                <input type="text" wire:model="info.phone" class="mt-1 w-full {{ $input }}"></label>
                                            <div>
                                                <span class="text-[13px] font-medium text-gray-500">Birincil kategori</span>
                                                <p class="text-[13px] text-gray-700 dark:text-gray-300">{{ $info['category']['name'] ?: '—' }}@if ($info['category']['id'] !== '') <span class="font-medium text-gray-900 dark:text-white">, yeni seçildi</span>@endif</p>
                                                <div class="mt-1 flex gap-2">
                                                    <input type="text" wire:model="categoryTerm" wire:keydown.enter="searchCategory" placeholder="Google kategorisi ara (ör. diş kliniği)" class="min-w-0 flex-1 {{ $input }}">
                                                    <button type="button" wire:click="searchCategory" class="btn">Ara</button>
                                                </div>
                                                @foreach ($categoryResults as $c)
                                                    <button type="button" wire:key="cat-{{ $c['id'] }}" wire:click="pickCategory('{{ $c['id'] }}', @js($c['name']))" class="mr-3 mt-1 text-[13px] text-gray-800 underline decoration-gray-300 underline-offset-2 hover:decoration-gray-500 dark:text-gray-200">{{ $c['name'] }}</button>
                                                @endforeach
                                                <p class="mt-1 text-[13px] text-gray-500">Eski birincil kategori ek kategori olarak kalır; hizmetler silinmez.</p>
                                            </div>
                                            <label class="block"><span class="text-[13px] font-medium text-gray-500">Randevu bağlantısı ekle</span>
                                                <input type="url" wire:model="info.appointment" placeholder="https://" class="mt-1 w-full {{ $input }}"></label>
                                            <label class="block"><span class="text-[13px] font-medium text-gray-500">Video ekle (MP4 adresi)</span>
                                                <input type="url" wire:model="info.video" placeholder="https://…/video.mp4" class="mt-1 w-full {{ $input }}"></label>
                                        </div>
                                        @if ($attributeList !== [])
                                            <div class="lg:col-span-2">
                                                <p class="text-[13px] font-medium text-gray-500">Özellikler (yalnız değiştirdiklerin gider)</p>
                                                <div class="mt-1.5 grid gap-x-6 gap-y-1.5 sm:grid-cols-2 xl:grid-cols-3">
                                                    @foreach ($attributeList as $a)
                                                        @php($key = str_replace('attributes/', '', $a['name']))
                                                        <label wire:key="attr-{{ $key }}" class="flex min-w-0 items-center gap-2 text-[13px]">
                                                            <select wire:model="info.attributes.{{ $key }}" class="h-7 shrink-0 {{ $input }} py-0 text-[13px]">
                                                                <option value="">Belirtilmemiş</option>
                                                                <option value="yes">Evet</option>
                                                                <option value="no">Hayır</option>
                                                            </select>
                                                            <span class="min-w-0 text-gray-700 dark:text-gray-300">{{ $a['label'] }}@if ($a['group'] !== '') <span class="text-gray-500">({{ $a['group'] }})</span>@endif</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                        <div class="grid gap-2 sm:flex lg:col-span-2">
                                            <button type="button" wire:click="sendInfo" wire:confirm="Değiştirdiğin bilgiler Google’daki profile yazılsın mı? (Geri alınabilir.)" class="btn-primary">Değişenleri Google’a gönder</button>
                                            <button type="button" wire:click="$set('infoEditing', null)" class="btn-quiet">Vazgeç</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="card"><x-ta.empty-state size="sm" title="Operasyonel markaya bağlı İşletme Profili yok." /></div>
            @endforelse
        @endif
    </div>
</div>
