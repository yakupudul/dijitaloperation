@php
    $tone = fn (string $state): string => match ($state) {
        'ok' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'proposal' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'short' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'missing' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    };
    $writeLine = function (?\App\Models\ExternalWriteAction $write): string {
        if ($write === null) {
            return '';
        }

        return $write->statusLabel().' · '.$write->created_at?->locale('tr')->diffForHumans();
    };
@endphp
<div class="space-y-5 dark:text-gray-200" data-gbp-profile-fields>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme profilleri</h1>
            <p class="mt-1 max-w-3xl text-xs text-gray-500">Açıklama, Google’ın profili hangi hizmet ve bölge için göstereceğini anlamasına yardım eder (en çok {{ $max }} karakter, iletişim bilgisi yazılmaz). Resmî tatil ve bayram saatleri girilmezse Google “saatler doğru olmayabilir” uyarısı gösterir. İkisi de senin onayınla Google’a gider ve geri alınabilir.</p>
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-profile-fields', 'brandFilter' => $brand])
    @include('livewire.operator.gbp.partials.desk-message')

    <div class="inline-flex rounded-lg bg-gray-100 p-1 text-sm dark:bg-white/[0.06]">
        <button type="button" wire:click="setSection('aciklama')" @class(['rounded-md px-3 py-1 font-medium', 'bg-white shadow-sm text-gray-900 dark:bg-gray-800 dark:text-white' => $section === 'aciklama', 'text-gray-500' => $section !== 'aciklama'])>Açıklama</button>
        <button type="button" wire:click="setSection('saatler')" @class(['rounded-md px-3 py-1 font-medium', 'bg-white shadow-sm text-gray-900 dark:bg-gray-800 dark:text-white' => $section === 'saatler', 'text-gray-500' => $section !== 'saatler'])>Özel gün saatleri</button>
        <button type="button" wire:click="setSection('bilgiler')" @class(['rounded-md px-3 py-1 font-medium', 'bg-white shadow-sm text-gray-900 dark:bg-gray-800 dark:text-white' => $section === 'bilgiler', 'text-gray-500' => $section !== 'bilgiler'])>Bilgiler</button>
    </div>

    @if ($section === 'aciklama')
        <section class="flex flex-wrap items-center gap-2 text-xs">
            @if ($proposals > 0)<span class="rounded-full px-2.5 py-1 font-medium {{ $tone('proposal') }}">{{ $proposals }} hazır öneri</span>@endif
            @if ($weak > 0)<span class="rounded-full px-2.5 py-1 font-medium {{ $tone('short') }}">{{ $weak }} eksik ya da kısa</span>@endif
            @if ($canWrite)
                <span class="ml-auto flex flex-wrap gap-2">
                    @if ($weak > 0)<button type="button" wire:click="prepareWeak" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">Eksik ve kısa olanları yaz ({{ $weak }})</button>@endif
                    @if ($proposals > 0)<button type="button" wire:click="sendProposals" wire:confirm="{{ $proposals }} hazır açıklama olduğu gibi Google’a gönderilsin mi? Önce okumak istersen satırları aç." class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600">Hazır önerileri gönder ({{ $proposals }})</button>@endif
                </span>
            @endif
        </section>

        <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
            @forelse ($groups as $brandName => $locations)
                <div class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.03]">{{ $brandName }} · {{ $locations->count() }}</div>
                @foreach ($locations as $location)
                    @php
                        $d = $descriptions[$location->id];
                        $ai = $d['ai'];
                        $write = $d['last_write'];
                    @endphp
                    <div wire:key="desc-{{ $location->id }}" class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                            <span class="text-xs tabular-nums text-gray-500">{{ $d['length'] }} / {{ $max }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone($d['state']) }}">{{ $d['label'] }}</span>
                            @if ($canWrite && $editing !== (int) $location->id && $d['state'] !== 'no_data')
                                <span class="flex flex-wrap gap-3 text-xs font-medium">
                                    <button type="button" wire:click="startEdit({{ $location->id }})" class="text-brand-600 hover:underline">{{ $d['state'] === 'proposal' ? 'Oku / düzenle' : 'Düzenle' }}</button>
                                    <button type="button" wire:click="prepare({{ $location->id }})" @disabled(($ai['status'] ?? '') === 'running') class="text-gray-600 hover:underline disabled:opacity-50 dark:text-gray-300">{{ $d['proposed'] ? 'Yeniden yaz' : 'Yapay zekâ ile yaz' }}</button>
                                    @if ($d['suggestion'] && $d['state'] === 'proposal')<button type="button" wire:click="dismiss({{ $d['suggestion']->id }})" class="text-gray-500 hover:underline">Öneriyi kaldır</button>@endif
                                </span>
                            @endif
                        </div>
                        @if ($ai && ($ai['status'] ?? '') !== 'ready')
                            <p @class(['mt-1 text-xs', 'text-rose-600' => ($ai['status'] ?? '') === 'failed', 'text-gray-500' => ($ai['status'] ?? '') !== 'failed']) @if (($ai['status'] ?? '') === 'running') wire:poll.10s @endif>{{ ($ai['status'] ?? '') === 'running' ? ($ai['message'] ?? 'Yazılıyor…') : ($ai['message'] ?? '') }}</p>
                        @endif
                        @if ($write)
                            <p class="mt-1 text-xs {{ $write->status === 'failed' ? 'text-rose-600' : 'text-gray-500' }}" @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                Son gönderim: {{ $writeLine($write) }}@if ($write->status === 'failed' && $write->error) · {{ \Illuminate\Support\Str::limit($write->error, 160) }}@endif
                                @if ($canWrite && $write->isUndoable()) · <button type="button" wire:click="undo({{ $write->id }})" wire:confirm="Önceki açıklama geri yüklensin mi?" class="font-medium text-gray-600 hover:underline dark:text-gray-300">Geri al</button>@endif
                            </p>
                        @endif

                        @if ($editing === (int) $location->id)
                            <div class="mt-3 grid gap-4 lg:grid-cols-2" x-data="{ text: @entangle('editText') }">
                                <div>
                                    <p class="mb-1 text-[11px] uppercase text-gray-400">Google’a gidecek açıklama</p>
                                    <textarea x-model="text" rows="8" maxlength="{{ $max }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                                    <p class="text-xs text-gray-500"><span x-text="text.length"></span> / {{ $max }} karakter · iyi bir açıklama en az {{ \App\Services\Gbp\Desk\ProfileFields::DESCRIPTION_GOOD }} karakterdir</p>
                                    <div class="mt-2 flex gap-3">
                                        <button type="button" wire:click="sendDescription" wire:confirm="Bu açıklama Google’daki profile yazılsın mı? (Geri alınabilir.)" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Google’a gönder</button>
                                        <button type="button" wire:click="$set('editing', null)" class="text-xs text-gray-500 hover:underline">Vazgeç</button>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-1 text-[11px] uppercase text-gray-400">Şu an Google’da</p>
                                    <p class="whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $d['current'] !== '' ? $d['current'] : 'Açıklama yok.' }}</p>
                                </div>
                            </div>
                        @elseif ($d['state'] === 'proposal')
                            <p class="mt-2 line-clamp-3 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $d['proposed'] }}</p>
                        @elseif ($d['current'] !== '')
                            <p class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $d['current'] }}</p>
                        @endif
                    </div>
                @endforeach
            @empty
                <p class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok.</p>
            @endforelse
        </section>
    @elseif ($section === 'saatler')
        @if ($holidays === [])
            <p class="rounded-xl bg-white p-4 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">Önümüzdeki {{ \App\Services\Gbp\Desk\ProfileFields::HOLIDAY_WINDOW_DAYS }} günde resmî tatil yok.</p>
        @else
            <section class="flex flex-wrap gap-2">
                @foreach ($holidays as $h)
                    <button type="button" wire:click="$set('holiday', '{{ $h['key'] }}')" @class(['rounded-lg px-3 py-2 text-left text-sm ring-1 ring-inset', 'bg-brand-50 ring-brand-300 dark:bg-brand-500/10' => $h['key'] === $holiday, 'bg-white ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700' => $h['key'] !== $holiday])>
                        <span class="block font-medium text-gray-900 dark:text-white">{{ $h['name'] }}</span>
                        <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($h['dates'][0])->locale('tr')->translatedFormat('d M') }}@if (count($h['dates']) > 1) – {{ \Illuminate\Support\Carbon::parse(end($h['dates']))->locale('tr')->translatedFormat('d M') }}@endif</span>
                    </button>
                @endforeach
            </section>

            @if ($current)
                <section class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_1fr]">
                    <div class="space-y-2 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-white">{{ $current['name'] }} saatleri</h2>
                        <p class="text-xs text-gray-500">Her gün için seç; “Değiştirme” seçilen gün Google’da olduğu gibi kalır. Yalnız bu tarihler değişir, haftalık saatler aynı kalır.</p>
                        @foreach ($current['dates'] as $date)
                            <div wire:key="day-{{ $date }}" class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="w-24 text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($date)->locale('tr')->translatedFormat('d M D') }}</span>
                                <select wire:model.live="hours.{{ $date }}.mode" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900">
                                    <option value="closed">Kapalı</option>
                                    <option value="custom">Açık (saat gir)</option>
                                    <option value="skip">Değiştirme</option>
                                </select>
                                @if (($hours[$date]['mode'] ?? 'closed') === 'custom')
                                    <input type="time" wire:model="hours.{{ $date }}.open" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900">
                                    <span>–</span>
                                    <input type="time" wire:model="hours.{{ $date }}.close" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900">
                                @endif
                            </div>
                        @endforeach
                        @if ($canWrite)
                            <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 dark:border-gray-700">
                                <button type="button" wire:click="sendHours" wire:confirm="Bu saatler seçilen {{ count($selected) }} işletmenin Google profiline yazılsın mı? (Geri alınabilir.)" @disabled($selected === []) class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Seçilenlere gönder ({{ count($selected) }})</button>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-2 text-xs dark:border-gray-700">
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $missingHours }} işletmede bu tatilin saatleri eksik</span>
                            @if ($canWrite)
                                <button type="button" wire:click="$set('selected', {{ json_encode(collect($hourStates)->filter(fn ($h) => $h['missing'] !== [])->keys()->map(fn ($id) => (string) $id)->values()->all()) }})" class="ml-auto font-medium text-brand-600 hover:underline">Eksik olanları seç</button>
                                <button type="button" wire:click="$set('selected', [])" class="font-medium text-gray-500 hover:underline">Seçimi temizle</button>
                            @endif
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($groups as $brandName => $locations)
                                <div class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.03]">{{ $brandName }} · {{ $locations->count() }}</div>
                                @foreach ($locations as $location)
                                    @php
                                        $h = $hourStates[$location->id] ?? null;
                                        $write = $hourWrites->get($location->id);
                                    @endphp
                                    <label wire:key="hl-{{ $location->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
                                        @if ($canWrite && $h)<input type="checkbox" wire:model.live="selected" value="{{ $location->id }}" class="rounded border-gray-300 text-brand-500">@endif
                                        <span class="min-w-0 flex-1 text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                        @if ($h === null)
                                            <span class="text-xs text-gray-400">Profil verisi yok</span>
                                        @elseif ($h['missing'] === [])
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone('ok') }}">Girilmiş · {{ implode(', ', array_unique($h['set'])) }}</span>
                                        @else
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone('missing') }}">{{ count($h['missing']) }} gün eksik</span>
                                        @endif
                                        @if ($write)
                                            <span class="w-full text-xs {{ $write->status === 'failed' ? 'text-rose-600' : 'text-gray-500' }}" @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                                {{ data_get($write->request_payload, 'label', 'Özel gün saatleri') }}: {{ $writeLine($write) }}@if ($write->status === 'failed' && $write->error) · {{ \Illuminate\Support\Str::limit($write->error, 160) }}@endif
                                                @if ($canWrite && $write->isUndoable()) · <button type="button" wire:click.prevent="undo({{ $write->id }})" wire:confirm="Bu tarihlerin önceki saatleri geri yüklensin mi?" class="font-medium text-gray-600 hover:underline dark:text-gray-300">Geri al</button>@endif
                                            </span>
                                        @endif
                                    </label>
                                @endforeach
                            @empty
                                <p class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok.</p>
                            @endforelse
                        </div>
                        <p class="border-t border-gray-100 px-4 py-2 text-[11px] text-gray-400 dark:border-gray-700">Durum son toplanan profil verisinden okunur; gönderimden sonra bir sonraki toplamada güncellenir.</p>
                    </div>
                </section>
            @endif
        @endif
    @else
        <p class="max-w-3xl text-xs text-gray-500">Haftalık saatler, telefon, birincil kategori, randevu bağlantısı, evet / hayır özellikleri ve video. Markanın kendi verisinden doldurulabilenler (diğer şubelerin saatleri, kendi sitesi, sitedeki randevu sayfası) her gece hazırlanır ve burada ve Onarım masasında onayını bekler. Telefon, kategori ve özellikler tahmin edilmez; sen seçersin. Her gönderim geri alınabilir.</p>
        <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
            @forelse ($groups as $brandName => $locations)
                <div class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.03]">{{ $brandName }} · {{ $locations->count() }}</div>
                @foreach ($locations as $location)
                    @php
                        $snap = $snapshots[$location->id] ?? null;
                        $write = $infoWrites->get($location->id);
                        $ready = $prepared->get($location->id, collect());
                        $hoursRows = $snap ? \App\Services\Gbp\Desk\ProfileInfo::rows((array) $snap['regular_hours']) : [];
                    @endphp
                    <div wire:key="info-{{ $location->id }}" class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                            @if ($ready->isNotEmpty())<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone('proposal') }}">{{ $ready->count() }} hazır bilgi</span>@endif
                            @if ($canWrite && $snap && $infoEditing !== (int) $location->id)
                                <button type="button" wire:click="startInfo({{ $location->id }})" class="text-xs font-medium text-brand-600 hover:underline">Düzenle</button>
                            @endif
                        </div>
                        @if ($snap === null)
                            <p class="mt-1 text-xs text-gray-400">Profil verisi yok.</p>
                        @else
                            <dl class="mt-1 grid gap-x-6 gap-y-0.5 text-xs text-gray-600 sm:grid-cols-2 dark:text-gray-400">
                                <div><dt class="inline text-gray-400">Kategori:</dt> <dd class="inline">{{ $snap['primary_category'] ?: '—' }}</dd></div>
                                <div><dt class="inline text-gray-400">Telefon:</dt> <dd class="inline">{{ $snap['phone'] ?: '—' }}</dd></div>
                                <div class="sm:col-span-2"><dt class="inline text-gray-400">Saatler:</dt> <dd class="inline">{{ $hoursRows !== [] ? \App\Services\Gbp\Desk\ProfileInfo::hoursText($hoursRows) : 'Girilmemiş' }}</dd></div>
                                <div class="sm:col-span-2 truncate"><dt class="inline text-gray-400">Web sitesi:</dt> <dd class="inline">{{ $snap['website'] ?: '—' }}</dd></div>
                            </dl>
                        @endif
                        @foreach ($ready as $s)
                            <div wire:key="prep-{{ $s->id }}" class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-sky-50 px-3 py-2 text-xs dark:bg-sky-500/10">
                                <span class="font-medium text-sky-800 dark:text-sky-200">{{ \App\Services\Gbp\Desk\ProfileInfo::FIELD_LABELS[data_get($s->action, 'field')] ?? 'Bilgi' }}:</span>
                                <span class="min-w-0 flex-1 break-words text-gray-700 dark:text-gray-300">{{ data_get($s->action, 'proposed') }}</span>
                                <span class="w-full text-gray-500">{{ $s->reason }}</span>
                                @if ($canWrite)
                                    <button type="button" wire:click="sendPrepared({{ $s->id }})" wire:confirm="Bu bilgi Google’daki profile yazılsın mı? (Geri alınabilir.)" class="font-semibold text-brand-600 hover:underline">Google’a gönder</button>
                                    <button type="button" wire:click="dismissPrepared({{ $s->id }})" class="text-gray-500 hover:underline">Kaldır</button>
                                @endif
                            </div>
                        @endforeach
                        @if ($write)
                            <p class="mt-1 text-xs {{ $write->status === 'failed' ? 'text-rose-600' : 'text-gray-500' }}" @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                {{ data_get($write->request_payload, 'label', 'Profil bilgisi') }}: {{ $writeLine($write) }}@if ($write->status === 'failed' && $write->error) · {{ \Illuminate\Support\Str::limit($write->error, 160) }}@endif
                                @if ($canWrite && $write->isUndoable()) · <button type="button" wire:click="undo({{ $write->id }})" wire:confirm="Önceki değerler geri yüklensin mi?" class="font-medium text-gray-600 hover:underline dark:text-gray-300">Geri al</button>@endif
                            </p>
                        @endif

                        @if ($infoEditing === (int) $location->id)
                            <div class="mt-3 grid gap-5 rounded-lg bg-gray-50 p-4 lg:grid-cols-2 dark:bg-white/[0.03]">
                                <div class="space-y-2">
                                    <p class="text-[11px] font-semibold uppercase text-gray-400">Haftalık saatler</p>
                                    @foreach ($days as $day => $label)
                                        <div wire:key="wd-{{ $day }}" class="flex flex-wrap items-center gap-2 text-sm">
                                            <label class="flex w-28 items-center gap-2"><input type="checkbox" wire:model.live="info.hours.{{ $day }}.open" class="rounded border-gray-300 text-brand-500"> {{ $label }}</label>
                                            @if ($info['hours'][$day]['open'] ?? false)
                                                <input type="time" wire:model="info.hours.{{ $day }}.from" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900">
                                                <span>–</span>
                                                <input type="text" wire:model="info.hours.{{ $day }}.to" placeholder="18:00" maxlength="5" class="w-20 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900">
                                            @else
                                                <span class="text-xs text-gray-400">Kapalı</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    <p class="text-[11px] text-gray-400">Gece yarısı kapanış için 24:00 yaz. Gün içinde birden çok aralık varsa tek aralıkla değiştirilir.</p>
                                </div>
                                <div class="space-y-3 text-sm">
                                    <label class="block"><span class="text-[11px] font-semibold uppercase text-gray-400">Telefon</span>
                                        <input type="text" wire:model="info.phone" class="mt-1 w-full rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                    <div>
                                        <span class="text-[11px] font-semibold uppercase text-gray-400">Birincil kategori</span>
                                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $info['category']['name'] ?: '—' }}@if ($info['category']['id'] !== '') <span class="font-semibold text-sky-700">· yeni seçildi</span>@endif</p>
                                        <div class="mt-1 flex gap-2">
                                            <input type="text" wire:model="categoryTerm" wire:keydown.enter="searchCategory" placeholder="Google kategorisi ara (ör. diş kliniği)" class="min-w-0 flex-1 rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
                                            <button type="button" wire:click="searchCategory" class="rounded-lg bg-white px-3 text-xs font-semibold ring-1 ring-inset ring-gray-300 dark:bg-gray-800 dark:ring-gray-600">Ara</button>
                                        </div>
                                        @foreach ($categoryResults as $c)
                                            <button type="button" wire:key="cat-{{ $c['id'] }}" wire:click="pickCategory('{{ $c['id'] }}', @js($c['name']))" class="mr-2 mt-1 rounded-full bg-white px-2.5 py-0.5 text-xs ring-1 ring-inset ring-gray-300 hover:bg-brand-50 dark:bg-gray-800 dark:ring-gray-600">{{ $c['name'] }}</button>
                                        @endforeach
                                        <p class="mt-1 text-[11px] text-gray-400">Eski birincil kategori ek kategori olarak kalır; hizmetler silinmez.</p>
                                    </div>
                                    <label class="block"><span class="text-[11px] font-semibold uppercase text-gray-400">Randevu bağlantısı ekle</span>
                                        <input type="url" wire:model="info.appointment" placeholder="https://" class="mt-1 w-full rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                    <label class="block"><span class="text-[11px] font-semibold uppercase text-gray-400">Video ekle (MP4 adresi)</span>
                                        <input type="url" wire:model="info.video" placeholder="https://…/video.mp4" class="mt-1 w-full rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                </div>
                                @if ($attributeList !== [])
                                    <div class="lg:col-span-2">
                                        <p class="text-[11px] font-semibold uppercase text-gray-400">Özellikler (yalnız değiştirdiklerin gider)</p>
                                        <div class="mt-1 grid gap-x-6 gap-y-1 sm:grid-cols-2 xl:grid-cols-3">
                                            @foreach ($attributeList as $a)
                                                @php($key = str_replace('attributes/', '', $a['name']))
                                                <label wire:key="attr-{{ $key }}" class="flex items-center gap-2 text-xs">
                                                    <select wire:model="info.attributes.{{ $key }}" class="rounded-lg border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900">
                                                        <option value="">Belirtilmemiş</option>
                                                        <option value="yes">Evet</option>
                                                        <option value="no">Hayır</option>
                                                    </select>
                                                    <span class="text-gray-700 dark:text-gray-300">{{ $a['label'] }}@if ($a['group'] !== '') <span class="text-gray-400">· {{ $a['group'] }}</span>@endif</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                <div class="flex gap-3 lg:col-span-2">
                                    <button type="button" wire:click="sendInfo" wire:confirm="Değiştirdiğin bilgiler Google’daki profile yazılsın mı? (Geri alınabilir.)" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Değişenleri Google’a gönder</button>
                                    <button type="button" wire:click="$set('infoEditing', null)" class="text-xs text-gray-500 hover:underline">Vazgeç</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            @empty
                <p class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok.</p>
            @endforelse
        </section>
    @endif
</div>
