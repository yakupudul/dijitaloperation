@php
    use App\Panel\Models\PanelEvent;
    use App\Panel\Support\PanelSources;
    use App\Panel\Support\PanelStages;
    use App\Panel\Support\PhoneKey;
    $tz = 'Europe/Istanbul';
    $input = 'h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:h-9 sm:text-sm';
    $digits = preg_replace('/\D+/', '', (string) $person->phone);
    $wa = strlen($digits) === 11 && str_starts_with($digits, '0') ? '9'.$digits : (strlen($digits) === 10 ? '90'.$digits : $digits);
    $facts = array_filter([
        'Telefon' => $showPhone ? $person->phone : PhoneKey::mask($person->phone),
        'E-posta' => $showPhone ? $person->email : ($person->email ? '***' : null),
        'İlgilendiği' => $person->interest,
        'Kaynak' => PanelSources::label($person->source),
        'Şehir' => collect([$person->district, $person->city])->filter()->implode(', '),
        'Cinsiyet' => ['female' => 'Kadın', 'male' => 'Erkek'][$person->gender] ?? null,
        'Yaş' => $person->age(),
        'Randevu' => $person->appointment_at?->timezone($tz)->translatedFormat('j F Y, H:i'),
        'Kayıt' => $person->created_at->timezone($tz)->translatedFormat('j F Y, H:i'),
        'Talep sayısı' => $requestCount > 1 ? $requestCount : null,
    ], fn ($value) => $value !== null && $value !== '');
    $line = function (PanelEvent $event): string {
        $meta = (array) $event->meta;
        return match ($event->type) {
            PanelEvent::REQUEST => (($meta['first'] ?? false) ? 'İlk talep' : 'Yeni talep').': '.PanelSources::label($meta['source'] ?? null).(! empty($meta['interest']) ? ', '.$meta['interest'] : ''),
            PanelEvent::CALL => 'Arama',
            PanelEvent::NOTE => 'Not',
            PanelEvent::STAGE => 'Aşama: '.PanelStages::label($meta['from'] ?? null).' › '.PanelStages::label($meta['to'] ?? null).(! empty($meta['reason']) ? ' ('.(PanelStages::lostReasons()[$meta['reason']] ?? '').')' : ''),
            PanelEvent::ASSIGNED => 'İlgilenen kişi değişti',
            PanelEvent::FILE => 'Dosya yüklendi',
            PanelEvent::REMINDER => 'Hatırlatma kuruldu',
            PanelEvent::EDITED => 'Bilgiler güncellendi',
            default => 'Kayıt',
        };
    };
@endphp
<div class="space-y-8">
    <header class="space-y-4">
        <a href="{{ route('panel.leads') }}" wire:navigate class="link-neutral text-[13px]">Adaylar</a>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="page-title">{{ $person->name }}</h1>
                <p class="page-sub">{{ collect([PanelStages::label($person->stage), $person->lost_reason ? (PanelStages::lostReasons()[$person->lost_reason] ?? null) : null, $person->assignee ? $person->assignee->name.' ilgileniyor' : null])->filter()->implode(', ') }}</p>
            </div>
            @if ($showPhone && $digits !== '')
                <div class="flex w-full gap-2 sm:w-auto">
                    <a href="tel:{{ $person->phone }}" class="btn-primary h-10! flex-1 px-4! sm:flex-none">Ara</a>
                    <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="btn h-10! flex-1 px-4! sm:flex-none">WhatsApp'tan yaz</a>
                </div>
            @endif
        </div>
        @if ($can['stage'])
            <div class="no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4 md:mx-0 md:px-0" data-panel-stages>
                @foreach (PanelStages::labels() as $key => $label)
                    <button type="button" wire:click="setStage('{{ $key }}')" @class(['seg', 'seg-active' => $person->stage === $key])>{{ $label }}</button>
                @endforeach
            </div>
        @endif
        @if ($losing)
            <form wire:submit="lose" class="card flex flex-wrap items-end gap-3 p-5" data-panel-lose>
                <label class="block min-w-56 flex-1"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Neden olumsuz kapandı?</span>
                    <select wire:model="reason" class="{{ $input }}">
                        <option value="">Seçin</option>
                        @foreach (PanelStages::lostReasons() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('reason')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                </label>
                <button type="submit" class="btn-primary">Olumsuz olarak kapat</button>
                <button type="button" wire:click="$set('losing', false)" class="btn-quiet">Vazgeç</button>
            </form>
        @endif
    </header>

    <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-5 xl:order-1">
            <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="Kayıtlar">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['tab', 'tab-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</button>
                @endforeach
            </nav>

            @if (in_array($tab, ['history', 'notes'], true))
                @if ($can['note'])
                    <form wire:submit="addNote" class="space-y-2" data-panel-note-form>
                        <textarea wire:model="note" rows="3" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm" placeholder="Ne konuşuldu, ne istedi, sırada ne var" aria-label="Not"></textarea>
                        @error('note')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        <div class="flex flex-wrap items-center gap-2">
                            <select wire:model="noteType" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px]" aria-label="Kayıt türü">
                                <option value="note">Not</option>
                                <option value="call">Arama kaydı</option>
                            </select>
                            <button type="submit" class="btn-primary">Kaydet</button>
                        </div>
                    </form>
                @endif
                <ol class="space-y-0" data-panel-history>
                    @forelse ($events as $event)
                        <li class="border-t border-gray-200 py-3.5 first:border-t-0" wire:key="event-{{ $event->id }}">
                            <p class="flex flex-wrap items-baseline gap-x-2 text-[13px] text-gray-500">
                                <span class="font-medium text-gray-900">{{ $line($event) }}</span>
                                <span>{{ $event->user?->name ?? ($event->type === PanelEvent::REQUEST ? 'Kendiliğinden' : '') }}</span>
                                <time class="ml-auto tabular-nums" datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</time>
                            </p>
                            @if ($event->body)
                                <p class="mt-1 max-w-[75ch] whitespace-pre-line text-[15px] leading-6 text-gray-800">{{ $event->body }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="py-3.5 text-sm text-gray-500">{{ $tab === 'notes' ? 'Henüz not yok.' : 'Henüz kayıt yok.' }}</li>
                    @endforelse
                </ol>
            @elseif ($tab === 'reminders')
                @if ($can['reminder'])
                    <form wire:submit="addReminder" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-end" data-panel-reminder-form>
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Ne yapılacak</span>
                            <input type="text" wire:model="reminder.title" class="{{ $input }}" placeholder="Fiyat için tekrar ara" autocomplete="off">
                        </label>
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Ne zaman</span>
                            <input type="datetime-local" wire:model="reminder.due" class="{{ $input }}">
                        </label>
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Kim</span>
                            <select wire:model="reminder.user" class="{{ $input }}">
                                @foreach ($team as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        </label>
                        <button type="submit" class="btn-primary h-10! sm:h-9!">Hatırlatma kur</button>
                        @error('reminder.title')<span class="text-[13px] text-error-600 sm:col-span-4">{{ $message }}</span>@enderror
                        @error('reminder.due')<span class="text-[13px] text-error-600 sm:col-span-4">{{ $message }}</span>@enderror
                    </form>
                @endif
                <div class="card" data-panel-person-reminders>
                    @forelse ($reminders as $item)
                        <div class="card-row" wire:key="rem-{{ $item->id }}">
                            <div class="min-w-0 flex-1">
                                <p @class(['truncate text-sm font-medium', 'text-gray-400 line-through' => $item->done_at, 'text-gray-900' => ! $item->done_at])>{{ $item->title }}</p>
                                <p class="truncate text-[13px] text-gray-500">{{ $item->due_at->timezone($tz)->translatedFormat('j F Y, H:i') }}@if ($item->user), {{ $item->user->name }}@endif</p>
                            </div>
                            @if (! $item->done_at && $can['reminder'])
                                <button type="button" wire:click="closeReminder({{ $item->id }})" class="btn">Yapıldı</button>
                            @endif
                        </div>
                    @empty
                        <x-ta.empty-state size="sm" icon="none" title="Bu kişi için hatırlatma yok." />
                    @endforelse
                </div>
            @elseif ($tab === 'files')
                @if ($can['upload'])
                    <div data-panel-upload>
                        <label class="btn cursor-pointer">
                            <input type="file" wire:model="upload" class="sr-only">Dosya yükle
                        </label>
                        <span wire:loading wire:target="upload" class="ml-2 text-[13px] text-gray-500">Yükleniyor</span>
                        @error('upload')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                    </div>
                @endif
                <div class="card" data-panel-files>
                    @forelse ($files as $file)
                        <div class="card-row" wire:key="file-{{ $file->id }}">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('panel.file', $file->id) }}" class="block truncate text-sm font-medium text-gray-900 hover:underline">{{ $file->name }}</a>
                                <p class="truncate text-[13px] text-gray-500">{{ number_format($file->size / 1024, 0, ',', '.') }} KB, {{ $file->created_at->timezone($tz)->translatedFormat('j F Y') }}@if ($file->user), {{ $file->user->name }}@endif</p>
                            </div>
                        </div>
                    @empty
                        <x-ta.empty-state size="sm" icon="none" title="Bu kişiye dosya eklenmedi." />
                    @endforelse
                </div>
            @endif
        </div>

        <aside class="min-w-0 xl:order-2" data-panel-facts>
            @if ($editing)
                <form wire:submit="save" class="space-y-3">
                    @foreach (array_filter(['name' => ['Ad soyad', 'text'], 'phone' => $showPhone ? ['Telefon', 'tel'] : null, 'email' => $showPhone ? ['E-posta', 'email'] : null, 'interest' => ['İlgilendiği konu', 'text'], 'city' => ['Şehir', 'text'], 'district' => ['İlçe', 'text'], 'birth_year' => ['Doğum yılı', 'number'], 'appointment' => ['Randevu', 'datetime-local']]) as $field => [$label, $type])
                        <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700">{{ $label }}</span>
                            <input type="{{ $type }}" wire:model="form.{{ $field }}" class="{{ $input }}" autocomplete="off">
                            @error('form.'.$field)<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700">Cinsiyet</span>
                        <select wire:model="form.gender" class="{{ $input }}"><option value="">Belirtilmedi</option><option value="female">Kadın</option><option value="male">Erkek</option></select>
                    </label>
                    <div class="flex gap-2 pt-1">
                        <button type="submit" class="btn-primary">Kaydet</button>
                        <button type="button" wire:click="$set('editing', false)" class="btn-quiet">Vazgeç</button>
                    </div>
                </form>
            @else
                <dl class="divide-y divide-gray-200 border-y border-gray-200 text-sm">
                    @foreach ($facts as $label => $value)
                        <div class="flex gap-4 py-2.5"><dt class="w-28 shrink-0 text-gray-500">{{ $label }}</dt><dd class="min-w-0 text-gray-900">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                @if ($can['assign'])
                    <label class="mt-4 block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">İlgilenen kişi</span>
                        <select wire:change="assign($event.target.value)" class="{{ $input }}">
                            <option value="">Atanmadı</option>
                            @foreach ($team as $id => $name)<option value="{{ $id }}" @selected($id === $person->assigned_user_id)>{{ $name }}</option>@endforeach
                        </select>
                    </label>
                @endif
                @if ($can['edit'])
                    <button type="button" wire:click="edit" class="btn mt-4">Bilgileri düzenle</button>
                @endif
            @endif
        </aside>
    </div>
</div>
