@php
    use App\Models\Reminder;
    use App\Services\Calendar\CalendarFeed;
    use Carbon\CarbonImmutable;

    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $weekdays = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
    $monthName = fn (CarbonImmutable $d): string => $d->locale('tr')->translatedFormat('F Y');
    $dayName = fn (string $date): string => CarbonImmutable::parse($date, CalendarFeed::TZ)->locale('tr')->translatedFormat('j F l');
    $workLine = function (array $w): ?string {
        $total = $w['done'] + $w['planned'] + $w['failed'];
        if ($total === 0) {
            return null;
        }

        return ($w['done'] === 0 && $w['failed'] === 0 ? 'Plan: ' : '').$total.' iş · '.$w['brands'].' marka';
    };
@endphp
{{-- Takvim (yakup 2026-11-05): own tasks and meetings in the cell, the brands' work as one line, the day opens per brand. --}}
<div class="pb-24" data-calendar>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Tak<span class="highlight">vim</span></h1>
            <p class="page-sub">İşlerin, toplantıların, yazacağın adaylar ve markalarda yapılan her iş tek takvimde. Bir güne tıkla, o günün tamamı açılır.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$toggle('report')" class="btn-quiet" data-calendar-report-toggle>{{ $report ? 'Takvime dön' : 'Ay dökümü' }}</button>
            <button type="button" wire:click="startAdding(null, 'meeting')" class="btn" data-calendar-add-meeting>+ Toplantı</button>
            <button type="button" wire:click="startAdding(null, 'task')" class="btn-primary" data-calendar-add-task>+ İş</button>
        </div>
    </header>

    @if ($failed)
        <div class="card mt-6 p-4 text-sm text-error-600">Takvim şu an okunamadı; birazdan yeniden dene. Hata kaydedildi.</div>
    @endif

    {{-- Month navigation --}}
    <div class="mt-6 {{ $report ? 'flex' : 'hidden md:flex' }} items-center gap-2">
        <button type="button" wire:click="$set('month', '{{ $prev }}')" class="btn-quiet px-2.5" aria-label="Önceki ay">‹</button>
        <h2 class="min-w-[9rem] text-center text-base font-semibold capitalize text-gray-900 dark:text-white" data-calendar-month>{{ $monthName($monthStart) }}</h2>
        <button type="button" wire:click="$set('month', '{{ $next }}')" class="btn-quiet px-2.5" aria-label="Sonraki ay">›</button>
        @if ($monthStart->format('Y-m') !== substr($today, 0, 7))
            <button type="button" wire:click="$set('month', '')" class="link-neutral ml-1 text-sm">Bugün</button>
        @endif
    </div>

    @if ($report && $monthReport !== null)
        {{-- Ay dökümü: what was done this month, per brand; drafts and failures are not counted as done. --}}
        <section class="mt-4" data-calendar-month-report>
            <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Ay dökümü: bu ay ne yapıldı</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach (CalendarFeed::KINDS as $kind => $label)
                    <div class="card p-3"><p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $monthReport['totals'][$kind] }}</p><p class="text-xs text-gray-500">{{ $label }}</p></div>
                @endforeach
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div class="card p-3"><p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $monthReport['meetings'] }}</p><p class="text-xs text-gray-500">toplantı yapıldı</p></div>
                <div class="card p-3"><p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $monthReport['tasks'] }}</p><p class="text-xs text-gray-500">iş bitti</p></div>
                <div class="card p-3"><p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $monthReport['leads_new'] }}</p><p class="text-xs text-gray-500">yeni aday</p></div>
                <div class="card p-3"><p class="text-xl font-semibold text-success-600">{{ $monthReport['leads_won'] }}</p><p class="text-xs text-gray-500">kazanıldı</p></div>
                <div class="card p-3"><p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $monthReport['leads_lost'] }}</p><p class="text-xs text-gray-500">kaybedildi</p></div>
            </div>
            <div class="card mt-4 overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead><tr class="border-b border-gray-100 text-left text-xs text-gray-500 dark:border-gray-800">
                        <th class="px-4 py-2.5 font-medium">Marka</th>
                        @foreach (CalendarFeed::KINDS as $label)<th class="px-3 py-2.5 text-right font-medium">{{ $label }}</th>@endforeach
                        <th class="px-4 py-2.5 text-right font-medium">Hata</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($monthReport['brands'] as $b)
                            <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60" data-calendar-report-row>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white"><a href="{{ route('operator.brand', $b['brand_id']) }}" wire:navigate class="hover:underline">{{ $b['brand'] }}</a></td>
                                @foreach (CalendarFeed::KINDS as $kind => $label)<td class="px-3 py-2 text-right tabular-nums {{ $b['counts'][$kind] === 0 ? 'text-gray-300 dark:text-gray-600' : 'text-gray-800 dark:text-gray-200' }}">{{ $b['counts'][$kind] }}</td>@endforeach
                                <td class="px-4 py-2 text-right tabular-nums {{ $b['failed'] > 0 ? 'text-error-600' : 'text-gray-300 dark:text-gray-600' }}">{{ $b['failed'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count(CalendarFeed::KINDS) + 2 }}" class="px-4 py-6 text-center text-gray-500">Bu ay markalarda yapılmış bir iş yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Yalnızca uygulanmış işler sayılır; taslaklar ve başarısız olanlar sayılmaz, başarısızlar "Hata" sütunundadır.</p>
        </section>
    @else
        {{-- Desktop: the month. --}}
        <section class="mt-4 hidden md:block" data-calendar-grid>
            <div class="grid grid-cols-7 text-center text-xs text-gray-500">
                @foreach ($weekdays as $w)<div class="pb-2">{{ $w }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                @foreach ($days as $date => $cell)
                    @php
                        $inMonth = substr($date, 0, 7) === $monthStart->format('Y-m');
                        $isToday = $date === $today;
                        $own = $cell['own'];
                        $line = $workLine($cell['work']);
                    @endphp
                    <button type="button" wire:click="openDay('{{ $date }}')" wire:key="cell-{{ $date }}"
                        class="group relative flex min-h-[112px] flex-col justify-start border-b border-r border-gray-100 p-2 text-left align-top transition hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03] {{ $inMonth ? '' : 'bg-gray-50/60 dark:bg-white/[0.01]' }} {{ $isToday ? 'ring-2 ring-inset ring-brand-500 bg-brand-50/50 dark:bg-brand-500/10' : '' }}"
                        data-calendar-day="{{ $date }}">
                        <span class="text-xs {{ $isToday ? 'font-semibold text-gray-900 dark:text-white' : ($inMonth ? 'text-gray-500' : 'text-gray-300 dark:text-gray-600') }}">{{ (int) substr($date, 8, 2) }}{{ $isToday ? ' · bugün' : '' }}</span>
                        <div class="mt-1 space-y-0.5">
                            @foreach (array_slice($own, 0, 3) as $item)
                                <p class="truncate text-[12px] leading-snug {{ $item['done'] ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-gray-200' }}">{{ $item['time'] ? $item['time'].' ' : '' }}{{ $item['title'] }}</p>
                            @endforeach
                            @if (count($own) > 3)<p class="text-[11px] text-gray-500">+{{ count($own) - 3 }} daha</p>@endif
                            @if ($cell['sales'] !== [])
                                <p class="truncate text-[12px] leading-snug text-gray-800 dark:text-gray-200">Satış: {{ count($cell['sales']) }} kişi</p>
                            @endif
                            @if ($isToday && $komutaToday)
                                <p class="truncate text-[12px] leading-snug text-gray-800 dark:text-gray-200">Komuta: {{ $komutaToday }} karar</p>
                            @endif
                            @if ($cell['work']['failed'] > 0)
                                <p class="truncate text-[11.5px] text-error-600">{{ $cell['work']['failed'] }} hata</p>
                            @endif
                            @if ($line)
                                <p class="truncate text-[11.5px] text-gray-500">{{ $line }}</p>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- Phone: the next 7 days as a list. --}}
        <section class="mt-4 space-y-3 md:hidden" data-calendar-agenda>
            @foreach ($agenda as $date => $cell)
                @php $line = $workLine($cell['work']); @endphp
                <button type="button" wire:click="openDay('{{ $date }}')" wire:key="agenda-{{ $date }}" class="card block w-full p-3 text-left">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $dayName($date) }}{{ $date === $today ? ' · bugün' : '' }}</p>
                    @forelse ($cell['own'] as $item)
                        <p class="mt-1 text-[13px] {{ $item['done'] ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-gray-200' }}">{{ $item['time'] ? $item['time'].' · ' : '' }}{{ $item['title'] }}</p>
                    @empty
                    @endforelse
                    @if ($cell['sales'] !== [])<p class="mt-1 text-[13px] text-gray-800 dark:text-gray-200">Satış: {{ count($cell['sales']) }} kişiye yazılacak</p>@endif
                    @if ($date === $today && $komutaToday)<p class="mt-1 text-[13px] text-gray-800 dark:text-gray-200">Komuta: {{ $komutaToday }} karar</p>@endif
                    @if ($cell['work']['failed'] > 0)<p class="mt-1 text-xs text-error-600">{{ $cell['work']['failed'] }} hata</p>@endif
                    @if ($line)<p class="mt-1 text-xs text-gray-500">{{ $line }}</p>@endif
                    @if ($cell['own'] === [] && $cell['sales'] === [] && ! $line)<p class="mt-1 text-xs text-gray-400">Boş</p>@endif
                </button>
            @endforeach
        </section>
    @endif

    {{-- The day window. --}}
    @if ($open !== null)
        <div class="fixed inset-0 z-[100000] flex items-end justify-center bg-gray-900/40 p-0 sm:items-center sm:p-4" wire:keydown.escape.window="closeDay" role="dialog" aria-label="{{ $dayName($day) }}" data-calendar-day-window>
            <div class="absolute inset-0" wire:click="closeDay"></div>
            <div class="relative max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl dark:bg-gray-900 sm:rounded-2xl">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-800">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $dayName($day) }}</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="startAdding('{{ $day }}', 'task')" class="btn-quiet">+ İş</button>
                        <button type="button" wire:click="startAdding('{{ $day }}', 'meeting')" class="btn-quiet">+ Toplantı</button>
                        <button type="button" wire:click="closeDay" class="btn-quiet px-2.5" aria-label="Kapat">✕</button>
                    </div>
                </div>

                <div class="mt-4 grid gap-6 lg:grid-cols-3">
                    {{-- 1. Own tasks + prospects to write to. --}}
                    <section data-calendar-tasks>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sizin işleriniz</h3>
                        <ul class="mt-2 space-y-2">
                            @if ($komuta)
                                <li class="flex items-center justify-between gap-2 text-[13px]"><span class="text-gray-800 dark:text-gray-200">Komuta: {{ $komuta }} karar bekliyor</span><a href="{{ route('operator.dashboard') }}" wire:navigate class="link-neutral text-xs">Aç →</a></li>
                            @endif
                            @foreach ($open['tasks'] as $t)
                                <li class="group flex items-start gap-2 text-[13px]" wire:key="task-{{ $t['id'] }}-{{ $t['date'] }}" data-calendar-task>
                                    <button type="button" wire:click="toggle({{ $t['id'] }})" class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border {{ $t['done'] ? 'border-success-500 bg-success-50 text-success-600' : 'border-gray-300 dark:border-gray-600' }}" aria-label="{{ $t['done'] ? 'Yapılmadı yap' : 'Yapıldı' }}">@if ($t['done'])✓@endif</button>
                                    <div class="min-w-0 flex-1">
                                        <p class="{{ $t['done'] ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-gray-200' }}">{{ $t['time'] ? $t['time'].' · ' : '' }}{{ $t['title'] }}</p>
                                        @if ($t['brand'] || $t['notes'] || $t['repeat'] !== 'none')<p class="text-xs text-gray-500">{{ implode(' · ', array_filter([$t['brand'], $t['repeat'] !== 'none' ? Reminder::REPEATS[$t['repeat']] : null, $t['notes'] ? mb_strimwidth($t['notes'], 0, 90, '…') : null])) }}</p>@endif
                                    </div>
                                    <button type="button" wire:click="remove({{ $t['id'] }})" wire:confirm="Bu iş silinsin mi?" class="text-xs text-gray-300 opacity-0 hover:text-error-600 group-hover:opacity-100" aria-label="Sil">Sil</button>
                                </li>
                            @endforeach
                            @foreach ($open['sales'] as $s)
                                <li class="flex items-start gap-2 text-[13px]" data-calendar-sales>
                                    <span class="mt-0.5 h-4 w-4 shrink-0 rounded-full border border-gray-300 dark:border-gray-600"></span>
                                    <a href="{{ $s['url'] }}" wire:navigate class="min-w-0 flex-1 text-gray-800 hover:underline dark:text-gray-200">{{ $s['step'] }}: {{ $s['title'] }}@if ($s['late'])<span class="text-xs text-error-600"> · gecikti</span>@endif</a>
                                </li>
                            @endforeach
                            @if ($open['tasks'] === [] && $open['sales'] === [] && ! $komuta)
                                <li class="text-[13px] text-gray-400">Bu gün için iş yok.</li>
                            @endif
                        </ul>
                        @if ($open['sales'] !== [])<p class="mt-2 text-xs text-gray-500">Adayların mesajları Satış'ta hazır; "Gönderdim" deyince buradan düşer.</p>@endif
                    </section>

                    {{-- 2. Meetings. --}}
                    <section data-calendar-meetings>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Toplantılar</h3>
                        <ul class="mt-2 space-y-3">
                            @forelse ($open['meetings'] as $m)
                                <li class="group text-[13px]" wire:key="meeting-{{ $m['id'] }}" data-calendar-meeting>
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="{{ $m['done'] ? 'text-gray-500' : 'text-gray-800 dark:text-gray-200' }}"><span class="tabular-nums">{{ $m['time'] }}{{ $m['end'] ? '–'.$m['end'] : '' }}</span> · {{ $m['title'] }}</p>
                                        <button type="button" wire:click="remove({{ $m['id'] }})" wire:confirm="Bu toplantı silinsin mi?" class="text-xs text-gray-300 opacity-0 hover:text-error-600 group-hover:opacity-100">Sil</button>
                                    </div>
                                    @if ($m['place'])
                                        <p class="text-xs text-gray-500">@if (str_starts_with($m['place'], 'http'))<a href="{{ $m['place'] }}" target="_blank" rel="noopener" class="link-neutral">Bağlantıyı aç ↗</a>@else{{ $m['place'] }}@endif</p>
                                    @endif
                                    @if ($m['lead_id'])<p class="text-xs"><a href="{{ route('operator.sales.lead', $m['lead_id']) }}" wire:navigate class="link-neutral">{{ $m['lead'] }} · kart →</a></p>@endif
                                    @if ($m['outcome'])
                                        <p class="mt-1 whitespace-pre-line rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-gray-700 dark:bg-white/[0.04] dark:text-gray-300">{{ $m['outcome'] }}</p>
                                    @endif
                                    @if ($noteFor === $m['id'])
                                        <form wire:submit="saveNote" class="mt-2">
                                            <textarea wire:model="note" rows="3" placeholder="Ne konuşuldu, ne karar verildi, sıradaki adım ne?" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-[13px] focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" data-calendar-note></textarea>
                                            @error('note')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                                            <div class="mt-1 flex gap-2"><button type="submit" class="btn-dark">Notu kaydet</button><button type="button" wire:click="$set('noteFor', null)" class="btn-quiet">Vazgeç</button></div>
                                        </form>
                                    @else
                                        <div class="mt-1.5 flex flex-wrap gap-2">
                                            @if ($m['over'] || $m['done'])
                                                <button type="button" wire:click="writeNote({{ $m['id'] }})" class="btn {{ $m['outcome'] ? '' : 'btn-primary' }}" data-calendar-note-open>{{ $m['outcome'] ? 'Notu düzenle' : 'Not yaz' }}</button>
                                            @endif
                                            <button type="button" wire:click="toggle({{ $m['id'] }})" class="btn-quiet">{{ $m['done'] ? 'Yapılmadı' : 'Yapıldı' }}</button>
                                        </div>
                                    @endif
                                </li>
                            @empty
                                <li class="text-[13px] text-gray-400">Toplantı yok.</li>
                            @endforelse
                        </ul>
                    </section>

                    {{-- 3. The brands' work, one line per brand. --}}
                    <section data-calendar-brands>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Markaların işleri</h3>
                        <ul class="mt-2 space-y-1">
                            @forelse ($open['brands'] as $b)
                                <li x-data="{ o: false }" wire:key="brand-{{ $b['brand_id'] }}" data-calendar-brand>
                                    <button type="button" x-on:click="o = ! o" class="flex w-full items-center justify-between gap-2 rounded-lg px-1.5 py-1 text-left text-[13px] hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                        <span class="truncate text-gray-800 dark:text-gray-200">{{ $b['brand'] }}</span>
                                        <span class="shrink-0 text-xs tabular-nums">
                                            @if ($b['failed'] > 0)<span class="text-error-600">{{ $b['failed'] }} hata</span>@endif
                                            @if ($b['done'] > 0)<span class="text-success-600"> {{ $b['done'] }} yapıldı</span>@endif
                                            @if ($b['planned'] > 0)<span class="text-gray-500"> {{ $b['planned'] }} planlı</span>@endif
                                        </span>
                                    </button>
                                    <ul x-show="o" x-cloak class="mb-2 ml-1.5 space-y-1 border-l border-gray-100 pl-3 dark:border-gray-800">
                                        @foreach ($b['items'] as $i)
                                            <li class="text-xs">
                                                <span class="{{ $i['state'] === 'failed' ? 'text-error-600' : ($i['state'] === 'done' ? 'text-success-600' : 'text-gray-500') }}">{{ $i['state'] === 'done' ? '✓' : ($i['state'] === 'failed' ? '✕' : '○') }}</span>
                                                <span class="text-gray-700 dark:text-gray-300">{{ $i['count'] }} {{ $i['label'] }}{{ $i['state'] === 'planned' ? ' (planlı)' : '' }}</span>
                                                @if ($i['titles'] !== [])<span class="block truncate pl-3.5 text-gray-400">{{ implode(' · ', $i['titles']) }}</span>@endif
                                                @if ($i['error'])<span class="block pl-3.5 text-error-600">{{ $i['error'] }}</span>@endif
                                            </li>
                                        @endforeach
                                        <li><a href="{{ route('operator.brand', $b['brand_id']) }}" wire:navigate class="link-neutral text-xs">Marka sayfası →</a></li>
                                    </ul>
                                </li>
                            @empty
                                <li class="text-[13px] text-gray-400">Bu gün markalarda iş yok.</li>
                            @endforelse
                        </ul>
                        @if ($open['brands'] !== [])<p class="mt-2 text-xs text-gray-500">Sistemin işlerini siz işaretlemezsiniz: uygulanınca yeşil, başarısızsa kırmızı olur.</p>@endif
                    </section>
                </div>
            </div>
        </div>
    @endif

    {{-- Add a task or a meeting. --}}
    @if ($adding)
        <div class="fixed inset-0 z-[100001] flex items-end justify-center bg-gray-900/40 sm:items-center sm:p-4" wire:keydown.escape.window="$set('adding', false)" role="dialog" aria-label="Takvime ekle">
            <div class="absolute inset-0" wire:click="$set('adding', false)"></div>
            <form wire:submit="save" class="relative w-full max-w-lg rounded-t-2xl bg-white p-5 shadow-xl dark:bg-gray-900 sm:rounded-2xl" data-calendar-form>
                <div class="flex items-center gap-2">
                    @foreach (Reminder::KINDS as $key => $label)
                        <button type="button" wire:click="$set('form.kind', '{{ $key }}')" class="chip {{ $form['kind'] === $key ? 'chip-accent' : 'chip-flat' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <label class="col-span-2 block text-xs text-gray-500">{{ $form['kind'] === 'meeting' ? 'Toplantı' : 'Ne yapılacak' }}
                        <input wire:model="form.title" type="text" placeholder="{{ $form['kind'] === 'meeting' ? 'Dentocare aylık görüşme' : 'Suada: ödeme hatırlat' }}" class="mt-1 {{ $input }}" autofocus />
                        @error('form.title')<span class="text-error-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="block text-xs text-gray-500">Tarih<input wire:model="form.date" type="date" class="mt-1 {{ $input }}" />@error('form.date')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block text-xs text-gray-500">Saat<input wire:model="form.time" type="time" class="mt-1 {{ $input }}" />@error('form.time')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                        <label class="block text-xs text-gray-500">Bitiş<input wire:model="form.end" type="time" class="mt-1 {{ $input }}" />@error('form.end')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                    </div>
                    @if ($form['kind'] === 'meeting')
                        <label class="col-span-2 block text-xs text-gray-500">Yer ya da bağlantı<input wire:model="form.place" type="text" placeholder="https://meet.google.com/… ya da klinik adresi" class="mt-1 {{ $input }}" /></label>
                        <label class="col-span-2 block text-xs text-gray-500">Aday (varsa)
                            <select wire:model="form.lead_id" class="mt-1 {{ $input }}">
                                <option value="">—</option>
                                @foreach ($leads as $lead)<option value="{{ $lead->id }}">{{ $lead->title() }}</option>@endforeach
                            </select>
                        </label>
                    @else
                        <label class="block text-xs text-gray-500">Tekrar
                            <select wire:model="form.repeat" class="mt-1 {{ $input }}">
                                @foreach (Reminder::REPEATS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                    <label class="{{ $form['kind'] === 'meeting' ? 'col-span-2' : '' }} block text-xs text-gray-500">Marka (varsa)
                        <select wire:model="form.brand_id" class="mt-1 {{ $input }}">
                            <option value="">—</option>
                            @foreach ($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="col-span-2 block text-xs text-gray-500">Not<textarea wire:model="form.notes" rows="2" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white"></textarea></label>
                </div>
                <div class="mt-4 flex items-center gap-2">
                    <button type="submit" class="btn-dark" wire:loading.attr="disabled">Ekle</button>
                    <button type="button" wire:click="$set('adding', false)" class="btn-quiet">Vazgeç</button>
                    <span class="text-xs text-gray-500">{{ $form['kind'] === 'meeting' ? '15 dakika önce telefona hatırlatılır; bitince not ister.' : 'Saat yazarsan o saatte telefona hatırlatılır.' }}</span>
                </div>
            </form>
        </div>
    @endif
</div>
