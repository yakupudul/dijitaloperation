@php
    use App\Livewire\Operator\Repair\RepairDeskPage;
    use App\Services\Repair\RepairDesk;
    // Renk kuralı (2026-10-11): turuncu yalnız ana eylem, kırmızı yalnız para / trafik kaybı, amber "bak", gri bilgi, yeşil tamam.
    // Sade panel (2026-10-20): no boxes around sections, risk is a dot + text, per-row actions are outline buttons.
    $riskTone = ['low' => 'ok', 'medium' => 'look', 'high' => 'look', 'manual' => 'info'];
    $outline = 'rounded-lg font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-700 dark:hover:bg-white/5';
    $laneDot = [RepairDesk::LANE_READY => 'bg-success-500', RepairDesk::LANE_REVIEW => 'bg-warning-500', RepairDesk::LANE_MANUAL => 'bg-gray-400'];
    $laneHint = [
        RepairDesk::LANE_READY => 'Düşük riskli, hazır işler. Paketin tamamını tek tıkla onaylayabilirsin.',
        RepairDesk::LANE_REVIEW => 'Birleştirme, sayfa metni, teknik düzeltme gibi işler. Eski ve yeni yan yana, değişen kelimeler renkli. Sayfa metni tek tek onaylanır.',
        RepairDesk::LANE_MANUAL => 'Sistemin henüz yapamadığı işler. "Yaptım" deyince bir hafta gizlenir; gece denetimi sorun kalmadıysa kapatır.',
    ];
    $editLabel = ['seo_title' => 'Başlığı düzelt', 'meta_description' => 'Açıklamayı düzelt', 'description' => 'Metni düzelt'];
    $words = function (array $parts, string $tone): string {
        return collect($parts)->map(fn (array $p): string => $p[1] ? '<mark class="rounded px-0.5 '.$tone.'">'.e($p[0]).'</mark>' : e($p[0]))->implode('');
    };
    $segment = '-mb-px flex h-10 items-center gap-2 whitespace-nowrap border-b-2';
    $segmentOn = 'border-gray-900 font-medium text-gray-900 dark:border-white dark:text-white';
    $segmentOff = 'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
    $pill = 'text-xs tabular-nums text-gray-500';
    $chip = 'rounded-md px-2 py-0.5';
    $chipOn = 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white';
    $chipOff = 'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5';
    /** Above this many rows a bulk approval opens the confirm window with samples. */
    $bulkLimit = 10;
    /** Satırda "Sayfa adı → yeni değer"; URL altta küçük gri. */
    $rowLabel = fn (array $row): string => ($row['page_title'] ?? '') !== '' ? $row['page_title'] : $row['title'];
    $samples = fn ($rows): array => collect($rows)->map(fn (array $r): string => $rowLabel($r).(($r['after'] ?? []) !== [] ? ' → '.$r['after'][0] : ''))->values()->all();
    $ask = fn (string $title, $rows, int $count, string $method, array $args = []): string => \Illuminate\Support\Js::from(['title' => $title, 'samples' => $samples($rows), 'more' => max(0, $count - count($samples($rows))), 'method' => $method, 'args' => $args])->toHtml();
@endphp
<div class="space-y-10 pb-24" data-repair-desk x-data="{ ask: null, go() { const a = this.ask; this.ask = null; this.$wire.call(a.method, ...a.args) } }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Onarım masası</h1>
            <p class="mt-1 text-sm text-gray-500" title="Hazırlanan düzeltmeler paket paket; onayladığın an uygulanır ve geri alınabilir."><span data-desk-total>@if ($brandId !== null){{ $brands[$brandId] ?? 'Marka' }}: onay bekleyen {{ number_format($counts['brand_packages'], 0, ',', '.') }} paket · {{ number_format($counts['brand_total'], 0, ',', '.') }} iş <span class="font-normal text-gray-500">(tüm markalarda {{ number_format($counts['total'], 0, ',', '.') }} iş)</span>@else Onay bekleyen {{ number_format($counts['packages'], 0, ',', '.') }} paket · {{ number_format($counts['total'], 0, ',', '.') }} iş @endif</span></p>
        </div>
        {{-- Rozet yazılamayanı da söyler (yakup 2026-10-19: 999 hata yeşil "1 iş yapıldı" altında kalıyordu) --}}
        <a href="#yapilanlar" @click="$dispatch('show-done')" @class(['inline-flex shrink-0 items-center gap-1.5 text-sm hover:underline',
            'text-gray-600 dark:text-gray-300' => $doneFailed === 0,
            'text-warning-700 dark:text-warning-400' => $doneFailed > 0]) data-done-badge><span class="h-1.5 w-1.5 rounded-full {{ $doneFailed === 0 ? 'bg-success-500' : 'bg-warning-500' }}"></span>Son 7 gün: {{ number_format($doneCount, 0, ',', '.') }} iş yapıldı@if ($doneFailed > 0) · {{ number_format($doneFailed, 0, ',', '.') }} yazılamadı@endif</a>
    </div>

    <livewire:operator.repair.daily-send-strip lazy />

    <div class="space-y-6">
    <h2 class="section-title text-gray-900 dark:text-white" data-desk-layer="karar">Karar bekleyenler</h2>

    @if ($message !== '')
        <p class="tone-info rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif
    @if ($queuedCount > 0)
        <p wire:poll.10s class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300" data-desk-queued><span class="live-dot"></span>Onaylanan {{ number_format($queuedCount, 0, ',', '.') }} iş arka planda uygulanıyor; bitince "Yapılanlar"da görünür.</p>
    @endif

    {{-- Tek anahtar (2026-10-11): üç şerit ve diğer işler; eski ?serit= / ?bolum= bağlantıları aynı yere düşer --}}
    <div class="space-y-2">
        <div class="flex max-w-full flex-wrap gap-x-6 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" role="tablist" aria-label="Hazır düzeltmeler ya da diğer işler" data-repair-lanes data-desk-sections>
            @foreach (RepairDesk::LANES as $key => $label)
                @php $active = $section === RepairDeskPage::SECTION_DESK && $lane === $key; @endphp
                <button type="button" role="tab" wire:click="showLane('{{ $key }}')" aria-selected="{{ $active ? 'true' : 'false' }}" class="{{ $segment }} {{ $active ? $segmentOn : $segmentOff }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $laneDot[$key] }}"></span>{{ $label }}
                    <span class="{{ $pill }}">{{ number_format($laneCounts[$key] ?? 0, 0, ',', '.') }}</span>
                </button>
            @endforeach
            <button type="button" role="tab" wire:click="showOther" aria-selected="{{ $section === RepairDeskPage::SECTION_OTHER ? 'true' : 'false' }}" class="{{ $segment }} {{ $section === RepairDeskPage::SECTION_OTHER ? $segmentOn : $segmentOff }}">
                Diğer işler <span class="{{ $pill }}">{{ number_format($otherCount, 0, ',', '.') }}</span>
            </button>
        </div>
        @if ($section === RepairDeskPage::SECTION_DESK)
            <p class="text-xs text-gray-500">{{ $laneHint[$lane] ?? '' }}</p>
        @endif
    </div>

    <div class="space-y-4">
        {{-- Marka: tek açılır menü (yakup 2026-10-19; 13 markalık sol liste yerine), kazandıracak işe göre sıralı --}}
        <div class="flex flex-wrap items-end gap-x-3 gap-y-1" data-repair-health>
            <label class="block w-full sm:w-80" data-repair-brand-select>
                <span class="text-xs text-gray-500">Marka</span>
                <select x-on:change="$wire.set('brandId', $event.target.value === '' ? null : Number($event.target.value))" class="mt-1 block h-9 w-full rounded-lg border-gray-200 bg-white py-0 text-sm focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="" @selected($brandId === null)>Tüm markalar · {{ number_format($counts['total'], 0, ',', '.') }} iş</option>
                    @if ($brandId !== null && ! $health->contains('brand_id', $brandId))
                        <option value="{{ $brandId }}" selected>{{ $brands[$brandId] ?? 'Marka' }} · bekleyen iş yok</option>
                    @endif
                    @foreach ($health as $h)
                        <option value="{{ $h['brand_id'] }}" @selected($brandId === $h['brand_id']) data-health-brand="{{ $h['brand_id'] }}">{{ $h['brand'] }} · {{ number_format($h['rows'], 0, ',', '.') }} iş bekliyor{{ $h['critical'] > 0 ? ' · '.$h['critical'].' kritik' : '' }}</option>
                    @endforeach
                </select>
            </label>
            <p class="cursor-help pb-2 text-xs text-gray-500" title="Sıra: açık işlerin beklenen kazancı (kritik iş 3, önemli 2, diğerleri 1 puan; sayfa işinde puan sayfanın 90 günlük Search Console gösterim ve tıklamasıyla büyür), eşitse kritik iş sayısı. Paketler yalnız hazır düzeltmelerdir; küme çakışması, içerik gibi Diğer işler ayrı sayılır." data-health-help>Kazandıracak işe göre sıralı</p>
            @if ($brandId !== null)
                <a href="{{ route('operator.brand', ['brand' => $brandId]) }}" wire:navigate class="link-neutral pb-2 text-xs" data-brand-link>Marka sayfası</a>
            @endif
        </div>

        <div class="min-w-0 space-y-4">
        @if ($section === RepairDeskPage::SECTION_OTHER)
            <livewire:operator.work.work-page :embedded="true" :brand-filter="$brandId" :key="'desk-other-'.($brandId ?? 'all')" />
        @else
            {{-- Onaydan sonra --}}
            <section class="grid grid-cols-2 gap-x-8 gap-y-6 text-sm sm:grid-cols-4" data-repair-pipeline title="Son {{ RepairDesk::TRACK_DAYS }} günde onayladıkların. Yazılamayan ya da geri alınan iş hatasıyla birlikte masaya kendiliğinden döner.">
                @foreach ([['queued', 'Onaylandı, sırada', 'text-gray-900 dark:text-white'], ['written', 'Siteye yazıldı', 'text-gray-900 dark:text-white'], ['verified', 'Doğrulandı', 'text-gray-900 dark:text-white'], ['returned', 'Hata, masaya döndü', 'text-warning-700 dark:text-warning-400']] as [$stage, $label, $tone])
                    <div class="flex flex-col gap-1">
                        <p class="text-[26px] leading-8 font-semibold tabular-nums tracking-tight {{ $tone }}">{{ $pipeline[$stage] }}</p>
                        <p class="text-xs text-gray-500">{{ $label }}</p>
                    </div>
                @endforeach
            </section>

            {{-- Türler: paket listesinin içinde küçük ikincil süzgeç --}}
            <div class="flex flex-wrap items-center gap-1.5 text-xs" data-repair-kinds>
                @if (count(array_filter($counts['kinds'])) > 1 || $kind !== '')
                    <span class="text-gray-500">Tür:</span>
                    <button type="button" wire:click="$set('kind', '')" class="{{ $chip }} {{ $kind === '' ? $chipOn : $chipOff }}">Hepsi</button>
                    @foreach (RepairDesk::KINDS as $key => $label)
                        @if (($counts['kinds'][$key] ?? 0) > 0)
                            <button type="button" wire:click="$set('kind', '{{ $kind === $key ? '' : $key }}')" class="{{ $chip }} {{ $kind === $key ? $chipOn : $chipOff }}">{{ $label }} <span class="tabular-nums text-gray-500">{{ $counts['kinds'][$key] }}</span></button>
                        @endif
                    @endforeach
                @endif
                @if ($isAdmin && $laneTotal > 0)
                    <span class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                        <button type="button" wire:click="selectAllMatching" class="h-7 rounded-lg px-2.5 text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">Bu şeritteki tümünü seç ({{ $laneTotal }})</button>
                        @if ($lane === RepairDesk::LANE_READY && $lowTotal > 0)
                            @if ($lowTotal > $bulkLimit)
                                <button type="button" @click="ask = {{ $ask('Görünen bütün düşük riskli işler uygulansın mı?', $lowSample, $lowTotal, 'approveAllLow') }}" class="h-8 w-full {{ $outline }} px-3 sm:h-7 sm:w-auto" data-approve-low>Düşük risklilerin hepsini onayla ({{ $lowTotal }})</button>
                            @else
                                <button type="button" wire:click="approveAllLow" wire:confirm="Görünen bütün düşük riskli işler uygulansın mı?" class="h-8 w-full {{ $outline }} px-3 sm:h-7 sm:w-auto" data-approve-low>Düşük risklilerin hepsini onayla ({{ $lowTotal }})</button>
                            @endif
                        @endif
                    </span>
                @endif
            </div>

            {{-- Paketler --}}
            <div class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-repair-packages>
                @forelse ($packages as $package)
                    @php
                        $isOpen = $open === $package['key'];
                        $approvable = $package['count'] - $package['high'];
                        $isManual = $package['lane'] === RepairDesk::LANE_MANUAL;
                    @endphp
                    <article class="py-4" wire:key="package-{{ $package['key'] }}" data-package="{{ $package['key'] }}">
                        <div class="flex flex-wrap items-start gap-3">
                            <x-ta.brand-avatar :name="$package['brand']" class="mt-0.5" />
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('operator.brand', ['brand' => $package['brand_id']]) }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-800 hover:underline">{{ $package['brand'] }}</a>
                                <h3 class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $package['count'] }} · {{ RepairDesk::KINDS[$package['kind']] }}</h3>
                                @if ($package['high'] > 0)<p class="text-xs text-warning-700 dark:text-warning-400">{{ $package['high'] }} iş yüksek riskli: paketi açıp tek tek onaylanır.</p>@endif
                                @if (($package['track'] ?? '') !== '')<p class="text-xs text-gray-500" data-package-track>{{ $package['track'] }}</p>@endif
                            </div>
                            @if ($isAdmin)
                                <div class="flex w-full flex-wrap items-center gap-2 text-xs sm:w-auto">
                                    @if ($approvable > 0)
                                        @if (! $isManual && $approvable > $bulkLimit)
                                            <button type="button" @click="ask = {{ $ask($package['brand'].': '.$approvable.' iş uygulansın mı?', $package['sample'], $approvable, 'approvePackage', [$package['key']]) }}" class="h-9 w-full {{ $outline }} px-3 text-sm sm:h-8 sm:w-auto sm:text-xs" data-approve-package>Hepsini onayla ({{ $approvable }})</button>
                                        @else
                                            <button type="button" wire:click="approvePackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $approvable }} iş {{ $isManual ? 'yapıldı olarak işaretlensin' : 'uygulansın' }} mı?" class="h-9 w-full {{ $outline }} px-3 text-sm sm:h-8 sm:w-auto sm:text-xs" data-approve-package>{{ $isManual ? 'Hepsini yaptım' : 'Hepsini onayla' }} ({{ $approvable }})</button>
                                        @endif
                                    @endif
                                    <button type="button" wire:click="togglePackage('{{ $package['key'] }}')" class="h-8 rounded-lg px-2 font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">{{ $isOpen ? 'Kapat' : 'İçini aç' }}</button>
                                    <button type="button" wire:click="selectPackage('{{ $package['key'] }}')" class="px-1.5 py-1 text-gray-500 hover:text-gray-800 hover:underline">Seç</button>
                                    <button type="button" wire:click="rejectPackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $package['count'] }} iş reddedilsin mi? Kanıt değişmedikçe geri gelmezler." class="px-1.5 py-1 text-xs text-gray-500 hover:text-gray-800 hover:underline dark:text-gray-400" data-reject-package>Hepsini reddet</button>
                                </div>
                            @endif
                        </div>

                        @unless ($isOpen)
                            <ul class="mt-3 space-y-1.5 text-xs text-gray-600 dark:text-gray-400">
                                @foreach ($package['sample'] as $row)
                                    <li class="break-words" title="{{ $rowLabel($row) }}{{ $row['after'] !== [] ? ' → '.$row['after'][0] : '' }}">
                                        <span class="text-gray-700 dark:text-gray-300">{{ $rowLabel($row) }}</span> @if ($row['after'] !== [])<span class="text-gray-500">→</span> <span class="font-medium text-gray-900 dark:text-white/90">{{ $row['after'][0] }}</span>@endif
                                        @if ($row['target'] !== '')<span class="block break-all text-xs text-gray-500">{{ $row['target'] }}</span>@endif
                                    </li>
                                @endforeach
                                @if ($package['count'] > 3)<li class="text-gray-500">ve {{ $package['count'] - 3 }} iş daha</li>@endif
                            </ul>
                        @else
                            <div class="mt-3 divide-y divide-gray-200 border-t border-gray-200 dark:divide-gray-800 dark:border-gray-800"
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
                                <p class="hidden py-2 text-xs text-gray-500 sm:block">Klavye: J / K satır değiştir, X seç, Enter ayrıntı, A onayla, R reddet</p>
                                @foreach ($openRows as $row)
                                    <div class="py-2.5 data-[focused]:rounded-lg data-[focused]:bg-gray-50 data-[focused]:px-2 dark:data-[focused]:bg-white/5" wire:key="repair-{{ $row['id'] }}" data-row="{{ $row['id'] }}" x-data="{ more: false }">
                                        <div class="flex flex-wrap items-start gap-3 text-sm sm:flex-nowrap">
                                            @if ($isAdmin)
                                                <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" class="mt-1 rounded border-gray-300">
                                            @endif
                                            <button type="button" data-toggle @click="more = ! more" class="min-w-0 flex-1 text-left" title="{{ $row['title'] }}">
                                                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                    @if ($row['risk'] !== 'low')<x-ta.pill :tone="$riskTone[$row['risk']]">{{ RepairDesk::RISKS[$row['risk']] }}</x-ta.pill>@endif
                                                    <span class="break-words text-gray-700 dark:text-gray-300">{{ $rowLabel($row) }}</span>
                                                    @if ($row['after'] !== [])<span class="text-gray-500">→</span> <span class="break-words font-medium text-gray-900 dark:text-white/90">{{ $row['after'][0] }}</span>@endif
                                                </span>
                                                @if ($row['target'] !== '')<span class="block break-all text-xs text-gray-500" data-row-target>{{ $row['target'] }}</span>@endif
                                            </button>
                                            @if ($isAdmin)
                                                <button type="button" wire:click="approve({{ $row['id'] }})" class="h-9 w-full shrink-0 {{ $outline }} px-3 text-sm sm:h-7 sm:w-auto sm:text-xs" @if ($row['risk'] === 'manual') data-repair-done @endif>{{ $row['risk'] === 'manual' ? 'Yaptım' : 'Onayla' }}</button>
                                            @endif
                                        </div>
                                        <div x-show="more" x-cloak class="mt-2 space-y-2 pl-7 text-xs">
                                            @if ($rowLabel($row) !== $row['title'])<p class="text-gray-500">{{ $row['title'] }}</p>@endif
                                            @if ($row['reason'] !== '')<p class="text-gray-500">Neden: {{ $row['reason'] }}</p>@endif
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                <div class="text-gray-500">
                                                    <p class="text-gray-500">Şimdi</p>
                                                    @forelse ($row['before'] as $n => $line)
                                                        @php $pair = $row['after'][$n] ?? null; $same = $pair !== null && \Illuminate\Support\Str::before($line, ':') === \Illuminate\Support\Str::before($pair, ':'); @endphp
                                                        <p class="break-words">{!! $same ? $words(RepairDesk::diff($line, $pair)['before'], 'bg-gray-200 text-gray-700 line-through dark:bg-gray-700 dark:text-gray-200') : e($line) !!}</p>
                                                    @empty<p>—</p>@endforelse
                                                </div>
                                                <div class="text-gray-800 dark:text-white/90">
                                                    <p class="text-gray-500">{{ $row['risk'] === 'manual' ? 'Yapılacak' : 'Onaylanınca' }}</p>
                                                    @foreach ($row['after'] as $n => $line)
                                                        @php $pair = $row['before'][$n] ?? null; $same = $pair !== null && \Illuminate\Support\Str::before($line, ':') === \Illuminate\Support\Str::before($pair, ':'); @endphp
                                                        <p class="break-words">{!! $same ? $words(RepairDesk::diff($pair, $line)['after'], 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-200') : e($line) !!}</p>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @if ($isAdmin)
                                                <div class="flex flex-wrap gap-3">
                                                    @foreach ($row['editable'] as $field)
                                                        <button type="button" wire:click="startEdit({{ $row['id'] }}, '{{ $field }}', @js((string) ($field === 'description' ? ($row['after'][0] ?? '') : \Illuminate\Support\Str::after(collect($row['after'])->first(fn ($l) => str_starts_with($l, $field === 'seo_title' ? 'Başlık: ' : 'Açıklama: ')) ?? '', ': '))))" class="font-medium text-gray-700 hover:underline dark:text-gray-300">{{ $editLabel[$field] ?? 'Düzelt' }}</button>
                                                    @endforeach
                                                    <button type="button" wire:click="rejectOne({{ $row['id'] }})" wire:confirm="Bu öneri reddedilsin mi?" class="text-gray-500 hover:underline">Reddet</button>
                                                </div>
                                            @endif
                                        </div>
                                        @if ($editing === $row['id'])
                                            <form wire:submit="saveEdit" class="surface mt-2 ml-7 flex flex-wrap gap-2 p-2">
                                                <textarea wire:model="editValue" rows="2" class="min-w-0 flex-1 rounded-lg border-gray-200 px-2 py-1 text-xs focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900"></textarea>
                                                <button type="submit" class="h-7 rounded-lg bg-brand-500 px-3 text-xs font-semibold text-white hover:bg-brand-600">Kaydet</button>
                                                <button type="button" wire:click="$set('editing', null)" class="text-xs text-gray-500">Vazgeç</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($openTotal > $openRows->count())
                                    <button type="button" wire:click="showMore" class="w-full py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">{{ $openTotal - $openRows->count() }} iş daha göster</button>
                                @endif
                            </div>
                        @endunless
                    </article>
                @empty
                    <p class="py-4 text-sm text-gray-500">Onay bekleyen hazır düzeltme yok.</p>
                @endforelse
                @if ($packagesTotal > $packages->count())
                    <x-ta.button type="button" variant="outline" size="sm" wire:click="showMorePackages" wire:loading.attr="disabled" class="w-full" data-packages-more>
                        {{ number_format(min(RepairDeskPage::PACKAGES_PAGE, $packagesTotal - $packages->count()), 0, ',', '.') }} paket daha göster
                        <span class="font-normal text-gray-500">({{ number_format($packages->count(), 0, ',', '.') }} / {{ number_format($packagesTotal, 0, ',', '.') }})</span>
                    </x-ta.button>
                @endif
            </div>

            {{-- Seçim çubuğu --}}
            @if ($isAdmin && $selected !== [])
                @php $selectedCount = count($selected); @endphp
                <div class="sticky bottom-3 z-30 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-theme-lg ring-1 ring-white/10 dark:bg-gray-800" data-repair-selection>
                    <span class="font-semibold">{{ $selectedCount }} iş seçili</span>
                    @if ($selectedCount > $bulkLimit)
                        <button type="button" @click="ask = {{ $ask($selectedCount.' seçili iş uygulansın mı?', $selectedSample, $selectedCount, 'approveSelected') }}" class="h-9 w-full rounded-lg bg-brand-500 px-3 font-semibold hover:bg-brand-600 sm:h-8 sm:w-auto">Seçilenleri onayla ({{ $selectedCount }})</button>
                    @else
                        <button type="button" wire:click="approveSelected" class="h-9 w-full rounded-lg bg-brand-500 px-3 font-semibold hover:bg-brand-600 sm:h-8 sm:w-auto">Seçilenleri onayla ({{ $selectedCount }})</button>
                    @endif
                    <input type="text" wire:model="rejectReason" placeholder="Red sebebi (isteğe bağlı)" class="h-8 w-full rounded-lg border-0 bg-white/10 px-2 text-sm text-white placeholder:text-gray-400 focus:ring-2 focus:ring-white/20 sm:w-56">
                    <button type="button" wire:click="rejectSelected" class="rounded-md border border-white/20 px-2 py-1 text-xs text-gray-300 hover:text-white">Seçilenleri reddet ({{ $selectedCount }})</button>
                    <button type="button" wire:click="clearSelection" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
                </div>
            @endif
        @endif
        </div>
    </div>
    </div>

    <div class="space-y-6">
    <h2 class="section-title text-gray-900 dark:text-white" data-desk-layer="gecmis">Geçmiş</h2>

    {{-- Yapılanlar: üstteki "Son 7 gün" rozeti buraya getirir ve listeyi açar. Bir grubun tek tek işleri yalnız açılınca
         sunucudan gelir (yakup 2026-10-19: 989 satırlık gizli liste sayfayı 1,8 MB yapıyordu). --}}
    <section id="yapilanlar" class="scroll-mt-20 text-sm" data-repair-writes x-data="{ all: false }" x-on:show-done.window="all = true">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold text-gray-900 dark:text-white">Yapılanlar <span class="text-sm font-normal text-gray-500">son 7 gün</span></h3>
            @if ($done->isNotEmpty())
                <p class="text-xs text-gray-500">{{ number_format($done->sum('ok'), 0, ',', '.') }} iş tam, {{ number_format($done->sum('partial'), 0, ',', '.') }} kısmen, {{ number_format($doneFailed, 0, ',', '.') }} yazılamadı @if ($done->sum('pending') > 0), {{ $done->sum('pending') }} sırada @endif</p>
            @endif
        </div>
        @if ($doneReturned > 0)
            <p class="mt-2 text-xs text-gray-500" data-done-returned>Yazılamayan {{ number_format($doneReturned, 0, ',', '.') }} iş kaybolmadı: hatasıyla birlikte masaya geri döndü ve bir sonraki gönderimde yeniden gider.</p>
        @endif
        <ul class="mt-3 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @forelse ($done as $n => $group)
                @php $isDoneOpen = $doneOpen === $group['key']; @endphp
                <li class="py-2.5" wire:key="done-{{ $group['key'] }}" @if ($n >= 6 && ! $isDoneOpen) x-show="all" x-cloak @endif>
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap gap-x-3 text-xs text-gray-500"><span>{{ $group['at'] }}</span><span>{{ $group['asset'] }}</span><span>{{ $group['label'] }}</span></p>
                            <p class="flex items-baseline gap-2 font-medium text-gray-900 dark:text-white">
                                <span aria-hidden="true" @class(['h-1.5 w-1.5 shrink-0 -translate-y-0.5 rounded-full', 'bg-warning-500' => $group['failed'] > 0 || $group['partial'] > 0, 'bg-success-500' => $group['failed'] === 0 && $group['partial'] === 0])></span><span>
                                {{ $group['sentence'] }}@if ($group['failed'] > 0 && $group['ok'] + $group['partial'] > 0); {{ number_format($group['failed'], 0, ',', '.') }} iş yazılamadı@elseif ($group['failed'] > 1) ({{ number_format($group['failed'], 0, ',', '.') }} iş)@endif.</span>
                            </p>
                            @foreach ($group['problems'] as $problem)
                                <p class="text-xs text-warning-700 dark:text-warning-400">Yapılamayan: {{ $problem }}</p>
                            @endforeach
                            @if (($group['returned'] ?? 0) > 0)
                                <p class="text-xs text-gray-500" data-done-group-returned>{{ number_format($group['returned'], 0, ',', '.') }} iş masaya geri döndü; sonraki gönderimde gider.</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            <button type="button" wire:click="toggleDone('{{ $group['key'] }}')" class="font-medium text-gray-700 hover:underline dark:text-gray-300" data-done-toggle>{{ $isDoneOpen ? 'Kapat' : 'Ayrıntı ('.count($group['items']).')' }}</button>
                            @if ($isAdmin && collect($group['items'])->contains('undoable', true))
                                <button type="button" wire:click="undoGroup('{{ $group['key'] }}')" wire:confirm="{{ $group['asset'] }}: bu gruptaki işler geri alınsın mı?" class="text-gray-500 hover:underline">Hepsini geri al</button>
                            @endif
                        </div>
                    </div>
                    @if ($isDoneOpen)
                        <ul class="mt-2 divide-y divide-gray-200 text-xs dark:divide-gray-800" data-done-items>
                            @foreach (array_slice($group['items'], 0, $donePerPage) as $item)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="write-{{ $item['id'] }}">
                                    <span class="min-w-0 flex-1">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span> <span class="ml-1 text-gray-500">{{ $item['status'] }}</span>@if ($item['error'] !== '') <span class="block text-warning-700 dark:text-warning-400">{{ \Illuminate\Support\Str::limit($item['error'], 160) }}</span>@endif
                                        @if ($item['page'] !== '')<span class="block break-all text-gray-500">{{ $item['page'] }}</span>@endif
                                        @foreach ($item['lines'] as $line)<span @class(['block break-words', 'text-gray-700 dark:text-gray-300' => $line['ok'], 'text-warning-700 dark:text-warning-400' => ! $line['ok']])>{{ $line['ok'] ? '' : 'Yazılamadı: ' }}{{ $line['text'] }}</span>@endforeach
                                    </span>
                                    @if ($isAdmin && $item['undoable'])
                                        <button type="button" wire:click="undo({{ $item['id'] }})" wire:confirm="Bu değişiklik geri alınsın mı?" class="font-medium text-gray-700 hover:underline dark:text-gray-300">Geri al</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if (count($group['items']) > $donePerPage)
                            <button type="button" wire:click="showMoreDone" class="mt-1 w-full py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">{{ number_format(count($group['items']) - $donePerPage, 0, ',', '.') }} iş daha göster</button>
                        @endif
                    @endif
                </li>
            @empty
                <li class="py-2.5 text-gray-500">Henüz yok.</li>
            @endforelse
        </ul>
        @if ($done->count() > 6)
            <button type="button" @click="all = ! all" class="mt-2 text-xs font-medium text-gray-700 hover:underline dark:text-gray-300" x-text="all ? 'Daha az göster' : 'Tümünü göster ({{ $done->count() }})'"></button>
        @endif
    </section>

    {{-- Sonuçlar: uygulanan işlerin 30. ve 90. gün ölçümü (gece, OutcomeTracker); iş türü sicili masa sırasını hafifçe etkiler --}}
    <section class="text-sm" data-repair-results x-data="{ all: false }">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold text-gray-900 dark:text-white">Sonuçlar <span class="text-sm font-normal text-gray-500">uygulanan işlerin 30. ve 90. günü</span></h3>
            @if ($results['items'] !== [])
            <p class="text-xs text-gray-500">
                <span class="text-success-700 dark:text-success-400">{{ $results['counts']['worked'] }} işe yaradı</span>,
                {{ $results['counts']['neutral'] }} değişmedi,
                <span class="text-error-700 dark:text-error-400">{{ $results['counts']['not_worked'] }} kötüleşti</span>,
                {{ $results['counts']['unclear'] }} ölçülemedi
            </p>
            @endif
        </div>
        <ul class="mt-3 divide-y divide-gray-200 border-y border-gray-200 text-xs dark:divide-gray-800 dark:border-gray-800">
            @forelse ($results['items'] as $n => $item)
                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-2.5" wire:key="result-{{ $item['id'] }}" data-result="{{ $item['id'] }}" @if ($n >= 8) x-show="all" x-cloak @endif>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $item['brand'] }}</span>
                    <span class="text-gray-500">{{ $item['type'] }}</span>
                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300" title="{{ $item['page'] }}">{{ $item['title'] }}</span>
                    <span @class(['font-medium', 'text-success-700 dark:text-success-400' => $item['verdict'] === 'worked', 'text-error-700 dark:text-error-400' => $item['verdict'] === 'not_worked', 'text-gray-500' => ! in_array($item['verdict'], ['worked', 'not_worked'], true)]) title="{{ $item['label'] }}">{{ $item['line'] }}</span>
                </li>
            @empty
                <li class="py-2.5 text-gray-500" data-results-next>Henüz ölçülen iş yok: onaylanan işler 30. ve 90. günde, önceki aynı uzunluktaki dönemle karşılaştırılır.@if (($results['next'] ?? null) !== null) İlk sonuç {{ $results['next'] }} civarında gelir.@endif</li>
            @endforelse
        </ul>
        @if (count($results['items']) > 8)
            <button type="button" @click="all = ! all" class="mt-2 text-xs font-medium text-gray-700 hover:underline dark:text-gray-300" x-text="all ? 'Daha az göster' : 'Tümünü göster ({{ count($results['items']) }})'"></button>
        @endif
        @if ($results['types'] !== [])
            <p class="mt-3 text-xs text-gray-500" data-result-types title="Sürekli işe yarayan türler masada biraz öne, kötüleştirenler biraz geriye alınır.">İş türü sicili:
                @foreach ($results['types'] as $type){{ $type['label'] }} {{ $type['won'] }}/{{ $type['measured'] }}@if ($type['weight'] > 1) (öne alındı)@elseif ($type['weight'] < 1) (geriye alındı)@endif{{ $loop->last ? '' : ', ' }}@endforeach
            </p>
        @endif
    </section>
    </div>

    {{-- Toplu onay penceresi: 10'dan fazla iş tek tıkla gitmez; 3 örnek ve "Geri alınabilir" notu --}}
    <div x-show="ask" x-cloak @keydown.escape.window="ask = null" class="fixed inset-0 z-[99999] flex items-center justify-center p-4" role="dialog" aria-modal="true" data-bulk-confirm>
        <div class="fixed inset-0 bg-gray-900/50" @click="ask = null"></div>
        <div class="surface relative w-full max-w-lg p-5 shadow-theme-lg">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white" x-text="ask?.title"></h3>
            <p class="mt-1 text-xs text-gray-500">Örnek olarak ilk üç iş:</p>
            <ul class="mt-2 space-y-1.5 text-sm">
                <template x-for="(line, n) in (ask?.samples ?? [])" :key="n">
                    <li class="break-words border-b border-gray-200 py-1.5 text-gray-800 dark:border-gray-800 dark:text-gray-200" x-text="line"></li>
                </template>
                <li class="text-xs text-gray-500" x-show="(ask?.more ?? 0) > 0" x-text="(ask?.more ?? 0) > 0 ? 've ' + ask.more + ' iş daha' : ''"></li>
            </ul>
            <p class="mt-3 text-xs text-gray-500">Geri alınabilir: her uygulanan iş kayda geçer, "Yapılanlar"dan tek tek ya da topluca geri alınır.</p>
            <div class="mt-4 flex justify-end gap-2 text-sm">
                <button type="button" @click="ask = null" class="h-9 rounded-lg px-3 text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Vazgeç</button>
                <button type="button" @click="go()" class="h-9 rounded-lg bg-brand-500 px-4 font-semibold text-white hover:bg-brand-600">Evet, onayla</button>
            </div>
        </div>
    </div>
</div>
