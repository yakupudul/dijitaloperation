@php
    use App\Livewire\Operator\Repair\RepairDeskPage;
    use App\Services\Repair\RepairDesk;
    // Panel concept (yakup approved 2026-10-20, prototype "Onarım masası"): the send card on top, then one tab row
    // (three lanes, Diğer işler, Geçmiş), a quiet toolbar and one card of packages; a package opens to its items
    // (old value struck through, new value under it). Red only for money / traffic loss, amber "bak", gray info.
    $riskTone = ['low' => 'ok', 'medium' => 'look', 'high' => 'look', 'manual' => 'info'];
    $laneHint = [
        RepairDesk::LANE_READY => 'Düşük riskli, hazır işler. Paketin tamamını tek tıkla onaylayabilirsin.',
        RepairDesk::LANE_REVIEW => 'Birleştirme, sayfa metni, teknik düzeltme gibi işler. Eski ve yeni yan yana, değişen kelimeler renkli. Sayfa metni tek tek onaylanır.',
        RepairDesk::LANE_MANUAL => 'Sistemin henüz yapamadığı işler. "Yaptım" deyince bir hafta gizlenir; gece denetimi sorun kalmadıysa kapatır.',
    ];
    $editLabel = ['seo_title' => 'Başlığı düzelt', 'meta_description' => 'Açıklamayı düzelt', 'description' => 'Metni düzelt'];
    $words = function (array $parts, string $tone): string {
        return collect($parts)->map(fn (array $p): string => $p[1] ? '<mark class="rounded px-0.5 '.$tone.'">'.e($p[0]).'</mark>' : e($p[0]))->implode('');
    };
    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    /** Above this many rows a bulk approval opens the confirm window with samples. */
    $bulkLimit = 10;
    /** Satırda sayfa adı; URL altında. */
    $rowLabel = fn (array $row): string => ($row['page_title'] ?? '') !== '' ? $row['page_title'] : $row['title'];
    $path = fn (string $url): string => ($p = parse_url($url, PHP_URL_PATH)) !== null && $p !== false && $p !== '' && str_starts_with($url, 'http') ? $p : $url;
    $samples = fn ($rows): array => collect($rows)->map(fn (array $r): string => $rowLabel($r).(($r['after'] ?? []) !== [] ? ': '.$r['after'][0] : ''))->values()->all();
    $ask = fn (string $title, $rows, int $count, string $method, array $args = []): string => \Illuminate\Support\Js::from(['title' => $title, 'samples' => $samples($rows), 'more' => max(0, $count - count($samples($rows))), 'method' => $method, 'args' => $args])->toHtml();
    $inDesk = $section === RepairDeskPage::SECTION_DESK;
    /** "Başlık: —" means there is no value yet: no struck-through line, the new value is shown plain. */
    $blank = fn (?string $line): bool => $line === null || in_array(trim(str_contains($line, ':') ? \Illuminate\Support\Str::after($line, ':') : $line), ['', '—', '-'], true);
    $chevron = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
@endphp
<div class="pb-24" data-repair-desk x-data="{ ask: null, go() { const a = this.ask; this.ask = null; this.$wire.call(a.method, ...a.args) } }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="page-title">Onarım <span class="highlight">masası</span></h1>
            <p class="page-sub"><span data-desk-total>{{ $brandId !== null ? ($brands[$brandId] ?? 'Marka').': ' : '' }}{{ $n($scope['decide']) }} iş karar bekliyor{{ $scope['manual'] > 0 ? ' · '.$n($scope['manual']).' iş senin elinle' : '' }}</span>. Onayladığın her şey geri alınabilir.</p>
        </div>
        @if ($brandId !== null)
            <a href="{{ route('operator.brand', ['brand' => $brandId]) }}" wire:navigate class="btn" data-brand-link>Marka sayfası</a>
        @endif
    </div>

    <div class="mt-7">
        <livewire:operator.repair.daily-send-strip lazy />
    </div>

    {{-- One tab row (2026-10-20 concept): the three lanes, the rest of the work and the history; old ?serit= / ?bolum= links land on the same place. --}}
    <nav class="no-scrollbar mt-8 flex gap-1.5 overflow-x-auto" role="tablist" aria-label="Karar bekleyenler: Hazır düzeltmeler, diğer işler ve geçmiş" data-repair-lanes data-desk-sections data-desk-layer="karar">
        @foreach (RepairDesk::LANES as $key => $label)
            @php $active = $inDesk && $lane === $key; @endphp
            <button type="button" role="tab" wire:click="showLane('{{ $key }}')" aria-selected="{{ $active ? 'true' : 'false' }}" title="{{ $laneHint[$key] }}" @class(['seg', 'seg-active' => $active])>
                {{ $label }} <span @class(['tabular-nums text-xs', 'text-accent' => $active, 'text-gray-400 dark:text-gray-500' => ! $active])>{{ $n($laneCounts[$key] ?? 0) }}</span>
            </button>
        @endforeach
        <button type="button" role="tab" wire:click="showOther" aria-selected="{{ $section === RepairDeskPage::SECTION_OTHER ? 'true' : 'false' }}" @class(['seg', 'seg-active' => $section === RepairDeskPage::SECTION_OTHER])>
            Diğer işler
        </button>
        <button type="button" role="tab" wire:click="showHistory" aria-selected="{{ $section === RepairDeskPage::SECTION_HISTORY ? 'true' : 'false' }}" @class(['seg', 'seg-active' => $section === RepairDeskPage::SECTION_HISTORY]) data-desk-layer="gecmis">
            Geçmiş
        </button>
    </nav>

    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">{{ $message }}</p>
    @endif
    @if ($queuedCount > 0)
        <p wire:poll.10s class="mt-4 flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300" data-desk-queued><span class="live-dot"></span>Onaylanan {{ $n($queuedCount) }} iş arka planda uygulanıyor; bitince Geçmiş'te görünür.</p>
    @endif

    @if ($section !== RepairDeskPage::SECTION_HISTORY)
        {{-- Toolbar: brand (sorted by expected gain) and kind on the left, the bulk action on the right --}}
        <div class="mt-4 mb-3 flex flex-wrap items-center gap-2" data-repair-health>
            <label class="block w-full sm:w-auto" data-repair-brand-select>
                <span class="sr-only">Marka</span>
                <select x-on:change="$wire.set('brandId', $event.target.value === '' ? null : Number($event.target.value))" class="{{ $select }} w-full sm:w-64">
                    <option value="" @selected($brandId === null)>Tüm markalar · {{ $n($summary['decide']) }} iş</option>
                    @if ($brandId !== null && ! $health->contains('brand_id', $brandId))
                        <option value="{{ $brandId }}" selected>{{ $brands[$brandId] ?? 'Marka' }} · bekleyen iş yok</option>
                    @endif
                    @foreach ($health as $h)
                        <option value="{{ $h['brand_id'] }}" @selected($brandId === $h['brand_id']) data-health-brand="{{ $h['brand_id'] }}">{{ $h['brand'] }} · {{ $n($summary['brands'][$h['brand_id']]['decide'] ?? 0) }} iş{{ $h['critical'] > 0 ? ' · '.$h['critical'].' kritik' : '' }}</option>
                    @endforeach
                </select>
            </label>
            @if ($inDesk && (count(array_filter($counts['kinds'])) > 1 || $kind !== ''))
                <label class="block w-full sm:w-auto" data-repair-kinds>
                    <span class="sr-only">Tür</span>
                    <select x-on:change="$wire.set('kind', $event.target.value)" class="{{ $select }} w-full sm:w-56">
                        <option value="" @selected($kind === '')>Tüm türler</option>
                        @foreach (RepairDesk::KINDS as $key => $label)
                            @if (($counts['kinds'][$key] ?? 0) > 0 || $kind === $key)
                                <option value="{{ $key }}" @selected($kind === $key)>{{ $label }} · {{ $n($counts['kinds'][$key] ?? 0) }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
            @endif
            <span class="hidden cursor-help text-[13px] text-gray-500 sm:inline" title="Sıra: açık işlerin beklenen kazancı (kritik iş 3, önemli 2, diğerleri 1 puan; sayfa işinde puan sayfanın 90 günlük Search Console gösterim ve tıklamasıyla büyür), eşitse kritik iş sayısı. Paketler yalnız hazır düzeltmelerdir; küme çakışması, içerik gibi Diğer işler ayrı sayılır." data-health-help>Kazandıracak işe göre sıralı</span>
            @if ($inDesk && $isAdmin && $laneTotal > 0)
                <span class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                    <button type="button" wire:click="selectAllMatching" class="btn-quiet">Tümünü seç ({{ $n($laneTotal) }})</button>
                    @if ($lane === RepairDesk::LANE_READY && $lowTotal > 0)
                        @if ($lowTotal > $bulkLimit)
                            <button type="button" @click="ask = {{ $ask('Görünen bütün düşük riskli işler uygulansın mı?', $lowSample, $lowTotal, 'approveAllLow') }}" class="btn flex-1 sm:flex-none" data-approve-low>Hepsini onayla ({{ $n($lowTotal) }})</button>
                        @else
                            <button type="button" wire:click="approveAllLow" wire:confirm="Görünen bütün düşük riskli işler uygulansın mı?" class="btn flex-1 sm:flex-none" data-approve-low>Hepsini onayla ({{ $n($lowTotal) }})</button>
                        @endif
                    @endif
                </span>
            @endif
        </div>
    @endif

    @if ($section === RepairDeskPage::SECTION_OTHER)
        <livewire:operator.work.work-page :embedded="true" :brand-filter="$brandId" :key="'desk-other-'.($brandId ?? 'all')" />
    @elseif ($inDesk)
        {{-- Paketler: one row per brand and kind; the first package shows its first items until another one is opened. --}}
        {{-- overflow-visible: the "⋯" menu of the last rows must not be cut off by the card edge. --}}
        <div class="card overflow-visible!" data-repair-packages>
            @forelse ($packages as $package)
                @php
                    /** Later packages of the same brand drop the logo and name and read as lines of the brand's group. */
                    $sameBrand = ! $loop->first && $packages[$loop->index - 1]['brand_id'] === $package['brand_id'];
                    $isOpen = $open === $package['key'];
                    $preview = ! $isOpen && $open === '' && $loop->first;
                    $approvable = $package['count'] - $package['high'];
                    $isManual = $package['lane'] === RepairDesk::LANE_MANUAL;
                    $monthly = $package['measured'] && $package['clicks'] > 0 ? (int) round($package['clicks'] / 3) : null;
                @endphp
                <article @class(['relative', 'border-t border-gray-200 first:border-t-0 dark:border-gray-800' => ! $sameBrand,
                    'before:absolute before:left-4 before:right-0 before:top-0 before:h-px before:bg-gray-100 sm:before:left-[86px] dark:before:bg-gray-800/70' => $sameBrand]) wire:key="package-{{ $package['key'] }}" data-package="{{ $package['key'] }}">
                    <div class="flex items-center gap-3 px-4 py-3.5">
                        <button type="button" wire:click="togglePackage('{{ $package['key'] }}')" class="flex min-w-0 flex-1 items-center gap-3 text-left" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                            <span @class(['hidden shrink-0 text-gray-400 transition-transform sm:block', 'rotate-90' => $isOpen || $preview])>{!! $chevron !!}</span>
                            @if ($sameBrand)
                                <span class="hidden h-7 w-7 shrink-0 sm:block" aria-hidden="true"></span>
                            @else
                                <x-ta.brand-avatar :name="$package['brand']" :logo="$logos[$package['brand_id']] ?? null" />
                            @endif
                            <span class="min-w-0 flex-1">
                                @if ($sameBrand)
                                    <span class="block text-sm tabular-nums text-gray-700 dark:text-gray-300"><span class="sr-only">{{ $package['brand'] }}: </span>{{ $n($package['count']) }} iş: {{ RepairDesk::KINDS[$package['kind']] }}</span>
                                @else
                                    <span class="block truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $package['brand'] }}">{{ $package['brand'] }}</span>
                                    <span class="block text-[13px] tabular-nums text-gray-500 dark:text-gray-400">{{ $n($package['count']) }} iş: {{ RepairDesk::KINDS[$package['kind']] }}</span>
                                @endif
                                @if ($package['high'] > 0)<span class="block text-[13px] text-warning-700 dark:text-warning-400">{{ $package['high'] }} iş yüksek riskli: paketi açıp tek tek onaylanır.</span>@endif
                                @if (($package['track'] ?? '') !== '')<span class="block text-xs text-gray-500" data-package-track>{{ $package['track'] }}</span>@endif
                            </span>
                        </button>
                        @if ($monthly !== null)
                            <span class="chip chip-up hidden md:inline-flex" title="Bu işlerin dokunduğu sayfalar son 90 günde ayda ortalama bu kadar tık aldı.">≈ {{ $n($monthly) }} tık/ay</span>
                        @endif
                        @if ($isAdmin)
                            <div class="flex shrink-0 items-center gap-1">
                                @if ($approvable > 0)
                                    @if (! $isManual && $approvable > $bulkLimit)
                                        <button type="button" @click="ask = {{ $ask($package['brand'].': '.$approvable.' iş uygulansın mı?', $package['sample'], $approvable, 'approvePackage', [$package['key']]) }}" class="btn-dark" data-approve-package>Onayla</button>
                                    @else
                                        <button type="button" wire:click="approvePackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $approvable }} iş {{ $isManual ? 'yapıldı olarak işaretlensin' : 'uygulansın' }} mı?" class="btn-dark" data-approve-package>{{ $isManual ? 'Yaptım' : 'Onayla' }}</button>
                                    @endif
                                @elseif (! $isOpen)
                                    <button type="button" wire:click="togglePackage('{{ $package['key'] }}')" class="btn">Tek tek bak</button>
                                @endif
                                <div class="relative" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape.window="menu = false">
                                    <button type="button" @click="menu = ! menu" class="btn-quiet w-8 px-0!" aria-label="Diğer seçenekler" :aria-expanded="menu">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                                    </button>
                                    <div x-show="menu" x-cloak x-transition.opacity.duration.120ms class="absolute right-0 z-40 mt-1 w-48 rounded-xl bg-white p-1.5 text-[13px] shadow-theme-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700" role="menu">
                                        <button type="button" role="menuitem" wire:click="togglePackage('{{ $package['key'] }}')" @click="menu = false" class="block w-full rounded-lg px-2.5 py-1.5 text-left text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">{{ $isOpen ? 'Kapat' : 'İçini aç' }}</button>
                                        <button type="button" role="menuitem" wire:click="selectPackage('{{ $package['key'] }}')" @click="menu = false" class="block w-full rounded-lg px-2.5 py-1.5 text-left text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">Paketi seç</button>
                                        <a href="{{ route('operator.brand', ['brand' => $package['brand_id']]) }}" wire:navigate role="menuitem" class="block rounded-lg px-2.5 py-1.5 text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">Marka sayfası</a>
                                        <button type="button" role="menuitem" wire:click="rejectPackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $package['count'] }} iş reddedilsin mi? Kanıt değişmedikçe geri gelmezler." class="block w-full rounded-lg px-2.5 py-1.5 text-left text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5" data-reject-package>Hepsini reddet</button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    @if ($preview)
                        {{-- Preview of the first package: its first items, read-only; "İçini aç" shows all with their actions. --}}
                        <div class="border-t border-gray-200 bg-gray-50/70 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]" data-package-preview>
                            @foreach ($package['sample'] as $row)
                                <div class="grid gap-x-4 gap-y-1 border-t border-gray-200 px-4 py-3 first:border-t-0 sm:grid-cols-[minmax(0,200px)_minmax(0,1fr)] sm:pl-[58px] dark:border-gray-800">
                                    <div class="min-w-0">
                                        <p class="truncate text-gray-500" title="{{ $rowLabel($row) }}">{{ $rowLabel($row) }}</p>
                                        @if ($row['target'] !== '')<p class="break-all font-medium text-gray-900 dark:text-white" title="{{ $row['target'] }}">{{ $path($row['target']) }}</p>@endif
                                    </div>
                                    <div class="min-w-0 space-y-0.5">
                                        @foreach (array_slice($row['before'], 0, 2) as $line)@unless ($blank($line))<p class="break-words text-gray-500 line-through decoration-gray-300 dark:decoration-gray-600">{{ $line }}</p>@endunless @endforeach
                                        @foreach (array_slice($row['after'], 0, 2) as $line)<p class="break-words text-gray-900 dark:text-white/90">{{ $line }}</p>@endforeach
                                    </div>
                                </div>
                            @endforeach
                            @if ($package['count'] > count($package['sample']))
                                <button type="button" wire:click="togglePackage('{{ $package['key'] }}')" class="block w-full border-t border-gray-200 px-4 py-2.5 text-left font-medium text-gray-700 hover:bg-gray-100/70 sm:pl-[58px] dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/5">{{ $n($package['count'] - count($package['sample'])) }} iş daha, hepsini aç</button>
                            @endif
                        </div>
                    @elseif ($isOpen)
                        <div class="border-t border-gray-200 bg-gray-50/70 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]"
                             x-data="{ i: -1,
                                rows() { return [...$el.querySelectorAll('[data-row]')] },
                                focus(n) { const r = this.rows(); if (! r.length) return; this.i = Math.max(0, Math.min(r.length - 1, n)); r.forEach((el, k) => el.toggleAttribute('data-focused', k === this.i)); r[this.i].scrollIntoView({ block: 'nearest' }) },
                                key(e) {
                                    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.metaKey || e.ctrlKey || e.altKey) return;
                                    const r = this.rows()[this.i];
                                    if (e.key === 'j') this.focus(this.i + 1);
                                    else if (e.key === 'k') this.focus(this.i - 1);
                                    else if (r && e.key === 'x') r.querySelector('input[type=checkbox]')?.click();
                                    else if (r && e.key === 'Enter') r.querySelector('[data-toggle]')?.click();
                                    else if (r && e.key === 'a') $wire.approve(+r.dataset.row);
                                    else if (r && e.key === 'r') { if (confirm('Bu öneri reddedilsin mi?')) $wire.rejectOne(+r.dataset.row) }
                                    else return;
                                    e.preventDefault();
                                } }"
                             @keydown.window="key($event)" data-repair-rows>
                            <p class="hidden px-4 pt-2.5 text-xs text-gray-400 sm:block sm:pl-[58px]">Klavye: J / K satır değiştir, X seç, Enter ayrıntı, A onayla, R reddet</p>
                            @foreach ($openRows as $row)
                                <div class="border-t border-gray-200 px-4 py-3 first-of-type:border-t-0 data-[focused]:bg-white sm:pl-4 dark:border-gray-800 dark:data-[focused]:bg-white/5" wire:key="repair-{{ $row['id'] }}" data-row="{{ $row['id'] }}" x-data="{ more: false }">
                                    <div class="flex flex-wrap items-start gap-x-3 gap-y-2 sm:flex-nowrap">
                                        <span class="hidden w-[26px] shrink-0 justify-center pt-0.5 sm:flex">
                                            @if ($isAdmin)<input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" class="rounded border-gray-300 dark:border-gray-600" aria-label="Seç">@endif
                                        </span>
                                        <div class="grid min-w-0 flex-1 gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,200px)_minmax(0,1fr)]">
                                            <button type="button" data-toggle @click="more = ! more" class="min-w-0 self-start text-left" title="{{ $row['title'] }}">
                                                <span class="flex flex-wrap items-center gap-x-2 text-gray-500">
                                                    @if ($row['risk'] !== 'low')<x-ta.pill :tone="$riskTone[$row['risk']]">{{ RepairDesk::RISKS[$row['risk']] }}</x-ta.pill>@endif
                                                    <span class="min-w-0 break-words">{{ $rowLabel($row) }}</span>
                                                </span>
                                                @if ($row['target'] !== '')<span class="block break-all font-medium text-gray-900 dark:text-white" data-row-target title="{{ $row['target'] }}">{{ $path($row['target']) }}</span>@endif
                                            </button>
                                            <div class="min-w-0 space-y-0.5">
                                                @foreach ($row['before'] as $k => $line)
                                                    @continue($blank($line))
                                                    @php $pair = $row['after'][$k] ?? null; $same = $pair !== null && \Illuminate\Support\Str::before($line, ':') === \Illuminate\Support\Str::before($pair, ':'); @endphp
                                                    <p class="break-words text-gray-500">{!! $same ? $words(RepairDesk::diff($line, $pair)['before'], 'bg-gray-200/80 text-gray-600 line-through dark:bg-gray-700 dark:text-gray-300') : '<span class="line-through decoration-gray-300 dark:decoration-gray-600">'.e($line).'</span>' !!}</p>
                                                @endforeach
                                                @foreach ($row['after'] as $k => $line)
                                                    @php $pair = $row['before'][$k] ?? null; $same = ! $blank($pair) && \Illuminate\Support\Str::before($line, ':') === \Illuminate\Support\Str::before($pair, ':'); @endphp
                                                    <p class="break-words text-gray-900 dark:text-white/90">{!! $same ? $words(RepairDesk::diff($pair, $line)['after'], 'bg-success-50 text-success-800 dark:bg-success-500/20 dark:text-success-200') : e($line) !!}</p>
                                                @endforeach
                                                <div x-show="more" x-cloak class="space-y-1 pt-1.5 text-xs">
                                                    @if ($rowLabel($row) !== $row['title'])<p class="text-gray-500">{{ $row['title'] }}</p>@endif
                                                    @if ($row['reason'] !== '')<p class="text-gray-500">Neden: {{ $row['reason'] }}</p>@endif
                                                    @if ($isAdmin && $row['editable'] !== [])
                                                        <p class="flex flex-wrap gap-3">
                                                            @foreach ($row['editable'] as $field)
                                                                <button type="button" wire:click="startEdit({{ $row['id'] }}, '{{ $field }}', @js((string) ($field === 'description' ? ($row['after'][0] ?? '') : \Illuminate\Support\Str::after(collect($row['after'])->first(fn ($l) => str_starts_with($l, $field === 'seo_title' ? 'Başlık: ' : 'Açıklama: ')) ?? '', ': '))))" class="font-medium text-gray-700 hover:underline dark:text-gray-300">{{ $editLabel[$field] ?? 'Düzelt' }}</button>
                                                            @endforeach
                                                        </p>
                                                    @endif
                                                </div>
                                                <button type="button" @click="more = ! more" class="text-xs text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" x-text="more ? 'Gizle' : 'Neden?'">Neden?</button>
                                                <x-operator.open-link :link="$row['open'] ?? null" class="ml-3 text-gray-400!" />
                                            </div>
                                        </div>
                                        @if ($isAdmin)
                                            <div class="flex w-full shrink-0 items-center gap-1 sm:w-auto">
                                                <button type="button" wire:click="approve({{ $row['id'] }})" class="btn h-7! flex-1 px-2.5! text-xs! sm:flex-none" @if ($row['risk'] === 'manual') data-repair-done @endif>{{ $row['risk'] === 'manual' ? 'Yaptım' : 'Onayla' }}</button>
                                                <button type="button" wire:click="rejectOne({{ $row['id'] }})" wire:confirm="Bu öneri reddedilsin mi?" class="btn-quiet h-7! px-2! text-xs!">Çıkar</button>
                                            </div>
                                        @endif
                                    </div>
                                    @if ($editing === $row['id'])
                                        <form wire:submit="saveEdit" class="card mt-2 flex flex-wrap gap-2 p-2 sm:ml-[42px]">
                                            <textarea wire:model="editValue" rows="2" class="min-w-0 flex-1 rounded-lg border-gray-200 px-2 py-1 text-[13px] focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900"></textarea>
                                            <button type="submit" class="btn-primary h-7! px-3! text-xs!">Kaydet</button>
                                            <button type="button" wire:click="$set('editing', null)" class="btn-quiet h-7! text-xs!">Vazgeç</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                            @if ($openTotal > $openRows->count())
                                <button type="button" wire:click="showMore" class="block w-full border-t border-gray-200 px-4 py-2.5 text-left font-medium text-gray-700 hover:bg-gray-100/70 sm:pl-[58px] dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/5">{{ $n($openTotal - $openRows->count()) }} iş daha göster</button>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <x-ta.empty-state size="sm" title="Bu sekmede bekleyen iş yok" message="Onay bekleyen hazır düzeltme yok." />
            @endforelse
            @if ($packagesTotal > $packages->count())
                <button type="button" wire:click="showMorePackages" wire:loading.attr="disabled" class="block w-full border-t border-gray-200 px-4 py-3 text-left text-[13px] font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/5" data-packages-more>
                    {{ $n(min(RepairDeskPage::PACKAGES_PAGE, $packagesTotal - $packages->count())) }} paket daha göster
                    <span class="font-normal text-gray-500">({{ $n($packages->count()) }} / {{ $n($packagesTotal) }})</span>
                </button>
            @endif
        </div>

        {{-- Seçim çubuğu --}}
        @if ($isAdmin && $selected !== [])
            @php $selectedCount = count($selected); @endphp
            <div class="sticky bottom-3 z-30 mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-theme-lg ring-1 ring-white/10 dark:bg-gray-800" data-repair-selection>
                <span class="font-medium">{{ $selectedCount }} iş seçili</span>
                @if ($selectedCount > $bulkLimit)
                    <button type="button" @click="ask = {{ $ask($selectedCount.' seçili iş uygulansın mı?', $selectedSample, $selectedCount, 'approveSelected') }}" class="h-8 w-full rounded-lg bg-white px-3 text-[13px] font-medium text-gray-900 hover:bg-gray-100 sm:w-auto">Seçilenleri onayla ({{ $selectedCount }})</button>
                @else
                    <button type="button" wire:click="approveSelected" class="h-8 w-full rounded-lg bg-white px-3 text-[13px] font-medium text-gray-900 hover:bg-gray-100 sm:w-auto">Seçilenleri onayla ({{ $selectedCount }})</button>
                @endif
                <input type="text" wire:model="rejectReason" placeholder="Red sebebi (isteğe bağlı)" class="h-8 w-full rounded-lg border-0 bg-white/10 px-2 text-[13px] text-white placeholder:text-gray-400 focus:ring-2 focus:ring-white/20 sm:w-56">
                <button type="button" wire:click="rejectSelected" class="h-8 rounded-lg px-2.5 text-[13px] text-gray-300 ring-1 ring-inset ring-white/20 hover:text-white">Seçilenleri reddet ({{ $selectedCount }})</button>
                <button type="button" wire:click="clearSelection" class="ml-auto text-[13px] text-gray-300 hover:underline">Seçimi temizle</button>
            </div>
        @endif
    @else
        {{-- Geçmiş: after approval (14 days), what was written (7 days, undoable) and the 30 / 90 day results. --}}
        <div class="mt-6 space-y-10">
            <section data-repair-pipeline title="Son {{ RepairDesk::TRACK_DAYS }} günde onayladıkların. Yazılamayan ya da geri alınan iş hatasıyla birlikte masaya kendiliğinden döner.">
                <div class="section-head"><h2 class="section-title">Onaydan sonra</h2><span class="text-[13px] text-gray-500">son {{ RepairDesk::TRACK_DAYS }} gün</span></div>
                <div class="metric-strip grid-cols-2 lg:grid-cols-4">
                    @foreach ([['queued', 'Onaylandı, sırada', 'neutral'], ['written', 'Siteye yazıldı', 'neutral'], ['verified', 'Doğrulandı', 'neutral'], ['returned', 'Hata, masaya döndü', $pipeline['returned'] > 0 ? 'look' : 'neutral']] as [$stage, $label, $tone])
                        <x-ta.metric-card :label="$label" :value="$n((int) $pipeline[$stage])" :tone="$tone" />
                    @endforeach
                </div>
            </section>

            {{-- Yapılanlar: a group's single writes come from the server only when opened (yakup 2026-10-19: a 989-row hidden list made the page 1.8 MB). --}}
            <section id="yapilanlar" class="scroll-mt-20" data-repair-writes x-data="{ all: false }">
                <div class="section-head">
                    <h2 class="section-title">Yapılanlar</h2><span class="text-[13px] text-gray-500">son 7 gün</span>
                    @if ($done->isNotEmpty())
                        <span class="text-[13px] text-gray-500 sm:ml-auto">{{ $n($done->sum('ok')) }} iş tam, {{ $n($done->sum('partial')) }} kısmen, {{ $n($doneFailed) }} yazılamadı @if ($done->sum('pending') > 0), {{ $done->sum('pending') }} iş sırada @endif</span>
                    @endif
                </div>
                @if ($doneReturned > 0)
                    <p class="mb-3 text-[13px] text-gray-500" data-done-returned>Yazılamayan {{ $n($doneReturned) }} iş kaybolmadı: hatasıyla birlikte masaya geri döndü ve bir sonraki gönderimde yeniden gider.</p>
                @endif
                <ul class="card">
                    @forelse ($done as $k => $group)
                        @php $isDoneOpen = $doneOpen === $group['key']; @endphp
                        <li class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="done-{{ $group['key'] }}" @if ($k >= 6 && ! $isDoneOpen) x-show="all" x-cloak @endif>
                            <div class="flex flex-wrap items-start gap-x-3 gap-y-2 sm:flex-nowrap">
                                <span aria-hidden="true" @class(['mt-2 h-1.5 w-1.5 shrink-0 rounded-full', 'bg-warning-500' => $group['failed'] > 0 || $group['partial'] > 0, 'bg-success-500' => $group['failed'] === 0 && $group['partial'] === 0])></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $group['sentence'] }}@if ($group['failed'] > 0 && $group['ok'] + $group['partial'] > 0); {{ $n($group['failed']) }} iş yazılamadı@elseif ($group['failed'] > 1) ({{ $n($group['failed']) }} iş)@endif.</p>
                                    <p class="text-[13px] text-gray-500">{{ $group['asset'] }}, {{ $group['label'] }}, {{ $group['at'] }}</p>
                                    @foreach ($group['problems'] as $problem)
                                        <p class="text-[13px] text-warning-700 dark:text-warning-400">Yapılamayan: {{ $problem }}</p>
                                    @endforeach
                                    @if (($group['returned'] ?? 0) > 0)
                                        <p class="text-[13px] text-gray-500" data-done-group-returned>{{ $n($group['returned']) }} iş masaya geri döndü; sonraki gönderimde gider.</p>
                                    @endif
                                </div>
                                <div class="flex w-full shrink-0 items-center gap-1 sm:w-auto">
                                    <button type="button" wire:click="toggleDone('{{ $group['key'] }}')" class="btn h-7! px-2.5! text-xs!" data-done-toggle>{{ $isDoneOpen ? 'Kapat' : 'Ayrıntı ('.count($group['items']).')' }}</button>
                                    @if ($isAdmin && collect($group['items'])->contains('undoable', true))
                                        <button type="button" wire:click="undoGroup('{{ $group['key'] }}')" wire:confirm="{{ $group['asset'] }}: bu gruptaki işler geri alınsın mı?" class="btn-quiet h-7! px-2! text-xs!">Hepsini geri al</button>
                                    @endif
                                </div>
                            </div>
                            @if ($isDoneOpen)
                                <ul class="mt-3 divide-y divide-gray-200 rounded-lg border border-gray-200 bg-gray-50/70 text-[13px] dark:divide-gray-800 dark:border-gray-800 dark:bg-white/[0.02]" data-done-items>
                                    @foreach (array_slice($group['items'], 0, $donePerPage) as $item)
                                        <li class="flex flex-wrap items-start justify-between gap-2 px-3 py-2" wire:key="write-{{ $item['id'] }}">
                                            <span class="min-w-0 flex-1">
                                                <span class="font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span> <span class="ml-1 text-gray-500">{{ $item['status'] }}</span>@if ($item['error'] !== '') <span class="block text-warning-700 dark:text-warning-400">{{ \Illuminate\Support\Str::limit($item['error'], 160) }}</span>@endif
                                                @if ($item['page'] !== '')<span class="block break-all text-gray-500">{{ $item['page'] }}@if (str_starts_with($item['page'], 'http')) <a href="{{ $item['page'] }}" target="_blank" rel="noopener noreferrer" class="link-neutral ml-1 whitespace-nowrap text-xs" title="Değişikliği sitede kontrol et" data-open-link="written-page">Sitede gör ↗</a>@endif</span>@endif
                                                @foreach ($item['lines'] as $line)<span @class(['block break-words', 'text-gray-700 dark:text-gray-300' => $line['ok'], 'text-warning-700 dark:text-warning-400' => ! $line['ok']])>{{ $line['ok'] ? '' : 'Yazılamadı: ' }}{{ $line['text'] }}</span>@endforeach
                                            </span>
                                            @if ($isAdmin && $item['undoable'])
                                                <button type="button" wire:click="undo({{ $item['id'] }})" wire:confirm="Bu değişiklik geri alınsın mı?" class="btn-quiet h-7! px-2! text-xs!">Geri al</button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                @if (count($group['items']) > $donePerPage)
                                    <button type="button" wire:click="showMoreDone" class="mt-1 w-full py-1.5 text-[13px] font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">{{ $n(count($group['items']) - $donePerPage) }} iş daha göster</button>
                                @endif
                            @endif
                        </li>
                    @empty
                        <li><x-ta.empty-state size="sm" title="Henüz yok" message="Son 7 günde siteye yazılan iş yok." /></li>
                    @endforelse
                </ul>
                @if ($done->count() > 6)
                    <button type="button" @click="all = ! all" class="link-neutral mt-2 text-[13px]" x-text="all ? 'Daha az göster' : 'Tümünü göster ({{ $done->count() }})'"></button>
                @endif
            </section>

            {{-- Sonuçlar: the 30th and 90th day measurement of applied work (nightly OutcomeTracker); the per-type record nudges the desk order. --}}
            <section data-repair-results x-data="{ all: false }">
                <div class="section-head">
                    <h2 class="section-title">Sonuçlar</h2><span class="text-[13px] text-gray-500">uygulanan işlerin 30. ve 90. günü</span>
                    @if ($results['items'] !== [])
                        <span class="text-[13px] text-gray-500 sm:ml-auto">
                            <span class="text-success-700 dark:text-success-400">{{ $results['counts']['worked'] }} işe yaradı</span>,
                            {{ $results['counts']['neutral'] }} değişmedi,
                            <span class="text-error-700 dark:text-error-400">{{ $results['counts']['not_worked'] }} kötüleşti</span>,
                            {{ $results['counts']['unclear'] }} ölçülemedi
                        </span>
                    @endif
                </div>
                <ul class="card text-[13px]">
                    @forelse ($results['items'] as $k => $item)
                        <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" wire:key="result-{{ $item['id'] }}" data-result="{{ $item['id'] }}" @if ($k >= 8) x-show="all" x-cloak @endif>
                            <span class="font-medium text-gray-900 dark:text-white">{{ $item['brand'] }}</span>
                            <span class="text-gray-500">{{ $item['type'] }}</span>
                            <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300" title="{{ $item['page'] }}">{{ $item['title'] }}</span>
                            <span @class(['font-medium', 'text-success-700 dark:text-success-400' => $item['verdict'] === 'worked', 'text-error-700 dark:text-error-400' => $item['verdict'] === 'not_worked', 'text-gray-500' => ! in_array($item['verdict'], ['worked', 'not_worked'], true)]) title="{{ $item['label'] }}">{{ $item['line'] }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-3.5 text-gray-500" data-results-next>Henüz ölçülen iş yok: onaylanan işler 30. ve 90. günde, önceki aynı uzunluktaki dönemle karşılaştırılır.@if (($results['next'] ?? null) !== null) İlk sonuç {{ $results['next'] }} civarında gelir.@endif</li>
                    @endforelse
                </ul>
                @if (count($results['items']) > 8)
                    <button type="button" @click="all = ! all" class="link-neutral mt-2 text-[13px]" x-text="all ? 'Daha az göster' : 'Tümünü göster ({{ count($results['items']) }})'"></button>
                @endif
                @if ($results['types'] !== [])
                    <p class="mt-3 text-[13px] text-gray-500" data-result-types title="Sürekli işe yarayan türler masada biraz öne, kötüleştirenler biraz geriye alınır.">İş türü sicili:
                        @foreach ($results['types'] as $type){{ $type['label'] }} {{ $type['won'] }}/{{ $type['measured'] }}@if ($type['weight'] > 1) (öne alındı)@elseif ($type['weight'] < 1) (geriye alındı)@endif{{ $loop->last ? '' : ', ' }}@endforeach
                    </p>
                @endif
            </section>
        </div>
    @endif

    {{-- Footer line: what the last 7 days wrote; failures say so (yakup 2026-10-19: 999 failures hid under a green "1 iş yapıldı"). --}}
    @if ($section !== RepairDeskPage::SECTION_HISTORY)
        <p class="mt-4 text-[13px] text-gray-500" data-done-badge>
            <button type="button" wire:click="showHistory" class="hover:text-gray-900 hover:underline dark:hover:text-white">Son 7 gün: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $n($doneCount) }}</span> iş yapıldı@if ($doneFailed > 0), <span class="font-medium text-warning-700 dark:text-warning-400">{{ $n($doneFailed) }}</span> iş yazılamadı @endif</button>
        </p>
    @endif

    {{-- Toplu onay penceresi: 10'dan fazla iş tek tıkla gitmez; 3 örnek ve "Geri alınabilir" notu --}}
    <div x-show="ask" x-cloak @keydown.escape.window="ask = null" class="fixed inset-0 z-[99999] flex items-center justify-center p-4" role="dialog" aria-modal="true" data-bulk-confirm>
        <div class="fixed inset-0 bg-gray-900/50" @click="ask = null"></div>
        <div class="card relative w-full max-w-lg p-5 shadow-theme-lg">
            <h3 class="text-[15px] font-semibold text-gray-900 dark:text-white" x-text="ask?.title"></h3>
            <p class="mt-1 text-[13px] text-gray-500">Örnek olarak ilk üç iş:</p>
            <ul class="mt-2 space-y-1.5 text-sm">
                <template x-for="(line, k) in (ask?.samples ?? [])" :key="k">
                    <li class="break-words border-b border-gray-200 py-1.5 text-gray-800 dark:border-gray-800 dark:text-gray-200" x-text="line"></li>
                </template>
                <li class="text-[13px] text-gray-500" x-show="(ask?.more ?? 0) > 0" x-text="(ask?.more ?? 0) > 0 ? 've ' + ask.more + ' iş daha' : ''"></li>
            </ul>
            <p class="mt-3 text-[13px] text-gray-500">Geri alınabilir: her uygulanan iş kayda geçer, Geçmiş sekmesinden tek tek ya da topluca geri alınır.</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="ask = null" class="btn">Vazgeç</button>
                <button type="button" @click="go()" class="btn-primary">Evet, onayla</button>
            </div>
        </div>
    </div>
</div>
