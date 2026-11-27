@php
    use App\Livewire\Panel\LeadsPage;
    use App\Panel\Support\PanelSources;
    use App\Panel\Support\PanelStages;
    use App\Panel\Support\PhoneKey;
    $tz = 'Europe/Istanbul';
    $input = 'h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:h-9 sm:text-sm';
    $cell = function ($person, string $column) use ($showPhone, $tz): string {
        return (string) match ($column) {
            'phone' => $showPhone ? $person->phone : PhoneKey::mask($person->phone),
            'email' => $showPhone ? $person->email : ($person->email ? '***' : ''),
            'source' => PanelSources::label($person->source),
            'interest' => $person->interest,
            'assignee' => $person->assignee?->name,
            'last_activity' => $person->last_activity_at?->timezone($tz)->diffForHumans(),
            'city' => collect([$person->district, $person->city])->filter()->implode(', '),
            'age' => $person->age(),
            'gender' => ['female' => 'Kadın', 'male' => 'Erkek'][$person->gender] ?? '',
            'appointment' => $person->appointment_at?->timezone($tz)->format('d.m.Y H:i'),
            'created' => $person->created_at?->timezone($tz)->format('d.m.Y'),
            default => '',
        };
    };
@endphp
<div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <p class="min-w-0 self-center text-[15px] text-gray-500">{{ $total }} kayıt, {{ (int) ($counts[PanelStages::NEW] ?? 0) }} tanesi aranmayı bekliyor.</p>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            @if ($isManager)
                <a href="{{ route('panel.export.leads', array_filter(['asama' => $stage])) }}" class="btn flex-1 sm:flex-none" data-panel-export>Excel'e aktar</a>
            @endif
            @if ($canImport)
                <a href="{{ route('panel.import') }}" wire:navigate class="btn flex-1 sm:flex-none">Excel'den yükle</a>
            @endif
            <a href="{{ route('panel.presets') }}" wire:navigate class="btn flex-1 sm:flex-none">Hazır listeler</a>
            @if ($canAdd)
                <button type="button" wire:click="$set('adding', true)" class="btn-primary flex-1 sm:flex-none">Aday ekle</button>
            @endif
        </div>
    </header>

    @if ($adding)
        <form wire:submit="add" class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4" data-panel-lead-form>
            <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Ad soyad</span>
                <input type="text" wire:model="form.name" class="{{ $input }}" autocomplete="off">
                @error('form.name')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
            </label>
            <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Telefon</span>
                <input type="tel" wire:model="form.phone" class="{{ $input }}" autocomplete="off" placeholder="05xx xxx xx xx">
                @error('form.phone')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
            </label>
            <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Nereden geldi</span>
                <select wire:model="form.source" class="{{ $input }}">
                    @foreach (PanelSources::labels() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            @include('panel.partials.interest', ['model' => 'form.interest', 'interests' => $interests, 'input' => $input])
            <div class="sm:col-span-2 lg:col-span-4">
                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                    <label for="lead-note" class="text-[13px] font-medium text-gray-700">Not</label>
                    @if ($templates->isNotEmpty())
                        <select wire:change="useTemplate($event.target.value); $event.target.value = ''" class="h-8 max-w-full rounded-lg border border-gray-200 bg-white px-2 text-[13px] text-gray-700" aria-label="Şablondan seç" data-panel-template-pick>
                            <option value="">Şablondan seç</option>
                            @foreach ($templates as $template)<option value="{{ $template->id }}">{{ $template->label }}</option>@endforeach
                        </select>
                    @endif
                </div>
                <textarea id="lead-note" wire:model="form.message" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm" placeholder="Görüşmede ne konuşuldu, ne istedi"></textarea>
            </div>
            <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="btn-primary">Kaydet</button>
                <button type="button" wire:click="add(true)" class="btn">Kaydet ve yenisini ekle</button>
                <button type="button" wire:click="$set('adding', false)" class="btn-quiet">Vazgeç</button>
            </div>
        </form>
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
            <button type="button" wire:click="$set('losing', null)" class="btn-quiet">Vazgeç</button>
        </form>
    @endif

    @if ($stale)
        <p class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-800" data-panel-stale-filter>
            <span class="min-w-0 flex-1">Yalnız {{ \App\Panel\Services\PanelWatch::STALE_DAYS }} gündür dokunulmayan açık adaylar gösteriliyor.</span>
            <button type="button" wire:click="$set('stale', false)" class="font-medium text-gray-900 underline">Hepsini göster</button>
        </p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <div class="flex gap-1 rounded-full border border-gray-200 p-0.5">
            <button type="button" wire:click="$set('view', 'table')" @class(['h-8 rounded-full px-3 text-[13px] font-medium', 'bg-primary text-white' => $view !== 'board', 'text-gray-600' => $view === 'board'])>Liste</button>
            <button type="button" wire:click="$set('view', 'board')" @class(['h-8 rounded-full px-3 text-[13px] font-medium', 'bg-primary text-white' => $view === 'board', 'text-gray-600' => $view !== 'board'])>Pano</button>
        </div>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ad{{ $showPhone ? ', telefon' : '' }} ya da konu ara" class="{{ $input }} sm:w-64" aria-label="Ara">
        <select wire:model.live="stage" class="{{ $input }} sm:w-auto" aria-label="Aşama">
            <option value="">Tüm aşamalar</option>
            @foreach (PanelStages::labels() as $key => $label)<option value="{{ $key }}">{{ $label }} ({{ (int) ($counts[$key] ?? 0) }})</option>@endforeach
        </select>
        <select wire:model.live="source" class="{{ $input }} sm:w-auto" aria-label="Kaynak">
            <option value="">Tüm kaynaklar</option>
            @foreach (PanelSources::labels() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        @if ($stage === PanelStages::LOST)
            <select wire:model.live="lostReason" class="{{ $input }} sm:w-auto" aria-label="Neden" data-panel-reason-filter>
                <option value="">Tüm nedenler</option>
                @foreach (PanelStages::lostReasons() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                <option value="none">Neden yazılmamış</option>
            </select>
        @endif
        <select wire:model.live="period" class="{{ $input }} sm:w-auto" aria-label="Dönem" data-panel-period-filter>
            <option value="">Tüm zamanlar</option>
            @foreach (LeadsPage::PERIODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        @if ($team->isNotEmpty())
            <select wire:model.live="assignee" class="{{ $input }} sm:w-auto" aria-label="İlgilenen">
                <option value="">Herkes</option>
                <option value="none">Kimseye atanmamış</option>
                @foreach ($team as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        @endif
        @if ($view !== 'board')
            <div class="relative ml-auto hidden sm:block" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = ! open" class="btn" :aria-expanded="open">Sütunlar</button>
                <div x-show="open" x-cloak class="absolute right-0 top-full z-30 mt-1 w-52 rounded-xl bg-white p-2 shadow-theme-lg ring-1 ring-gray-200" data-panel-columns>
                    @foreach (LeadsPage::COLUMNS as $key => $label)
                        <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                            <input type="checkbox" wire:model.live="columns" value="{{ $key }}" class="size-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900/20">{{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if ($view === 'board')
        <div class="no-scrollbar -mx-4 flex gap-3 overflow-x-auto px-4 pb-2 md:-mx-10 md:px-10" data-panel-board>
            @foreach ($lanes as $laneStage => $lane)
                <section class="w-[272px] shrink-0 rounded-2xl bg-gray-100/80 p-2.5">
                    <h2 class="flex items-center justify-between px-1.5 pb-2 text-[13px] font-semibold text-gray-900">{{ PanelStages::label($laneStage) }}<span class="font-normal tabular-nums text-gray-500">{{ (int) ($counts[$laneStage] ?? 0) }}</span></h2>
                    <div class="space-y-2">
                        @forelse ($lane as $person)
                            <article class="rounded-xl border border-gray-200 bg-white p-3 shadow-card" wire:key="lane-{{ $person->id }}">
                                <a href="{{ route('panel.person', $person->id) }}" wire:navigate class="block truncate text-sm font-medium text-gray-900 hover:underline">{{ $person->name }}</a>
                                <p class="mt-0.5 truncate text-[13px] text-gray-500">{{ collect([$person->interest, PanelSources::label($person->source)])->filter()->implode(', ') }}</p>
                                <p class="mt-0.5 text-[13px] tabular-nums text-gray-700">{{ $showPhone ? $person->phone : PhoneKey::mask($person->phone) }}</p>
                                @if ($canMove)
                                    <select wire:change="move({{ $person->id }}, $event.target.value)" class="mt-2 h-8 w-full rounded-lg border border-gray-200 bg-white px-2 text-[13px] text-gray-700" aria-label="{{ $person->name }} aşaması">
                                        @foreach (PanelStages::labels() as $key => $label)<option value="{{ $key }}" @selected($key === $person->stage)>{{ $label }}</option>@endforeach
                                    </select>
                                @endif
                            </article>
                        @empty
                            <p class="px-1.5 pb-1 text-[13px] text-gray-500">Bu aşamada kimse yok.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    @else
        <section class="card overflow-x-auto" data-panel-leads>
            <table class="data-table table-stack">
                <thead><tr><th>Ad</th>@foreach ($columns as $column)<th>{{ LeadsPage::COLUMNS[$column] }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($people as $person)
                        <tr wire:key="person-{{ $person->id }}">
                            <td><a href="{{ route('panel.person', $person->id) }}" wire:navigate class="font-medium text-gray-900 hover:underline">{{ $person->name }}</a></td>
                            @foreach ($columns as $column)
                                @if ($column === 'stage')
                                    <td data-label="Aşama">
                                        @if ($canMove)
                                            <select wire:change="move({{ $person->id }}, $event.target.value)" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px] text-gray-700" aria-label="{{ $person->name }} aşaması">
                                                @foreach (PanelStages::labels() as $key => $label)<option value="{{ $key }}" @selected($key === $person->stage)>{{ $label }}</option>@endforeach
                                            </select>
                                        @else
                                            <x-ta.pill :tone="PanelStages::tone($person->stage)">{{ PanelStages::label($person->stage) }}</x-ta.pill>
                                        @endif
                                    </td>
                                @else
                                    <td data-label="{{ LeadsPage::COLUMNS[$column] }}" @class(['text-gray-600', 'whitespace-nowrap tabular-nums' => in_array($column, ['phone', 'last_activity', 'appointment', 'created', 'age'], true)])>{{ $cell($person, $column) }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) + 1 }}">
                            <x-ta.empty-state size="sm" icon="none" :title="$total === 0 ? 'Henüz aday yok.' : 'Bu süzgeçlere uyan aday yok.'" :message="$total === 0 ? 'Sitenizdeki formdan gelenler buraya kendiliğinden düşer. Telefonla arayanları “Aday ekle” ile yazabilirsiniz.' : null" class="px-0! py-0!" />
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        @if ($people->hasPages())<div>{{ $people->links() }}</div>@endif
    @endif
</div>
