@php
    use App\Livewire\Operator\Repair\RepairDeskPage;
    use App\Services\Repair\RepairDesk;
    // Renk kuralı (2026-10-11): turuncu yalnız ana eylem, kırmızı yalnız para / trafik kaybı, amber "bak", gri bilgi, yeşil tamam.
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $riskTone = ['low' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'medium' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'high' => 'bg-amber-100 font-semibold text-amber-900 dark:bg-amber-500/20 dark:text-amber-200', 'manual' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'];
    $laneDot = [RepairDesk::LANE_READY => 'bg-emerald-500', RepairDesk::LANE_REVIEW => 'bg-amber-500', RepairDesk::LANE_MANUAL => 'bg-gray-400'];
    $laneHint = [
        RepairDesk::LANE_READY => 'Düşük riskli, hazır işler. Paketin tamamını tek tıkla onaylayabilirsin.',
        RepairDesk::LANE_REVIEW => 'Birleştirme, sayfa metni, teknik düzeltme gibi işler. Eski ve yeni yan yana, değişen kelimeler renkli. Sayfa metni tek tek onaylanır.',
        RepairDesk::LANE_MANUAL => 'Sistemin henüz yapamadığı işler. "Yaptım" deyince bir hafta gizlenir; gece denetimi sorun kalmadıysa kapatır.',
    ];
    $editLabel = ['seo_title' => 'Başlığı düzelt', 'meta_description' => 'Açıklamayı düzelt', 'description' => 'Metni düzelt'];
    $words = function (array $parts, string $tone): string {
        return collect($parts)->map(fn (array $p): string => $p[1] ? '<mark class="rounded px-0.5 '.$tone.'">'.e($p[0]).'</mark>' : e($p[0]))->implode('');
    };
    $segment = 'flex items-center gap-2 rounded-lg px-3 py-1.5 whitespace-nowrap';
    $segmentOn = 'bg-white font-semibold text-gray-900 shadow-sm dark:bg-gray-900 dark:text-white';
    $segmentOff = 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
    $pill = 'rounded-full bg-gray-200/70 px-1.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-300';
    /** Above this many rows a bulk approval opens the confirm window with samples. */
    $bulkLimit = 10;
    /** Satırda "Sayfa adı → yeni değer"; URL altta küçük gri. */
    $rowLabel = fn (array $row): string => ($row['page_title'] ?? '') !== '' ? $row['page_title'] : $row['title'];
    $samples = fn ($rows): array => collect($rows)->map(fn (array $r): string => $rowLabel($r).(($r['after'] ?? []) !== [] ? ' → '.$r['after'][0] : ''))->values()->all();
    $ask = fn (string $title, $rows, int $count, string $method, array $args = []): string => \Illuminate\Support\Js::from(['title' => $title, 'samples' => $samples($rows), 'more' => max(0, $count - count($samples($rows))), 'method' => $method, 'args' => $args])->toHtml();
@endphp
<div class="space-y-5 pb-24" data-repair-desk x-data="{ ask: null, go() { const a = this.ask; this.ask = null; this.$wire.call(a.method, ...a.args) } }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Onarım masası</h1>
            <p class="mt-1 text-sm text-gray-500">Sistemin hazırladığı düzeltmeler paket paket: aynı markanın aynı türdeki işleri tek kararda. Onayladığın an uygulanır, kayda geçer ve geri alınabilir. <span class="whitespace-nowrap font-medium text-gray-700 dark:text-gray-300">Onay bekleyen {{ $counts['total'] }}</span></p>
        </div>
        <a href="#yapilanlar" @click="$dispatch('show-done')" class="shrink-0 rounded-full bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30" data-done-badge>Son 7 gün: {{ number_format($doneCount, 0, ',', '.') }} iş yapıldı</a>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-gray-100 px-4 py-2 text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $message }}</p>
    @endif

    {{-- Tek anahtar (2026-10-11): üç şerit ve diğer işler; eski ?serit= / ?bolum= bağlantıları aynı yere düşer --}}
    <div class="space-y-1">
        <p class="text-xs text-gray-500">Hazır düzeltmeler · ya da diğer işler</p>
        <div class="inline-flex max-w-full flex-wrap gap-1 rounded-xl bg-gray-100 p-1 text-sm dark:bg-gray-800" role="tablist" aria-label="Bölümler" data-repair-lanes data-desk-sections>
            @foreach (RepairDesk::LANES as $key => $label)
                @php $active = $section === RepairDeskPage::SECTION_DESK && $lane === $key; @endphp
                <button type="button" role="tab" wire:click="showLane('{{ $key }}')" aria-selected="{{ $active ? 'true' : 'false' }}" class="{{ $segment }} {{ $active ? $segmentOn : $segmentOff }}">
                    <span class="h-2 w-2 rounded-full {{ $laneDot[$key] }}"></span>{{ $label }}
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

    <div class="grid items-start gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
        {{-- Solda markalar: kazandıracak işe göre sıralı --}}
        <aside class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-repair-health>
            <h2 class="px-2 text-sm font-semibold text-gray-800 dark:text-white/90">Marka sağlığı</h2>
            <p class="cursor-help px-2 text-xs text-gray-500" title="Kusursuz yüzdesi = son {{ RepairDesk::TRACK_DAYS }} günde biten işler ÷ (biten + kalan). Sıra: açık işlerin beklenen kazancı (kritik iş 3, önemli 2, diğerleri 1 puan), eşitse kritik iş sayısı." data-health-help>Kazandıracak işe göre sıralı ⓘ</p>
            <ul class="mt-2 max-h-[70vh] space-y-0.5 overflow-y-auto text-sm">
                <li>
                    <button type="button" wire:click="$set('brandId', null)" @class(['flex w-full items-baseline justify-between gap-2 rounded-lg px-2 py-1.5 text-left', 'bg-gray-100 font-semibold text-gray-900 dark:bg-gray-800 dark:text-white' => $brandId === null, 'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $brandId !== null])>
                        <span>Tümü</span><span class="text-xs text-gray-500">{{ number_format($counts['total'], 0, ',', '.') }}</span>
                    </button>
                </li>
                @if ($brandId !== null && ! $health->contains('brand_id', $brandId))
                    <li class="rounded-lg bg-gray-100 px-2 py-1.5 font-semibold text-gray-900 dark:bg-gray-800 dark:text-white">{{ $brands[$brandId] ?? 'Marka' }} <span class="block text-xs font-normal text-gray-500">Bekleyen iş yok</span></li>
                @endif
                @foreach ($health as $h)
                    @php $on = $brandId === $h['brand_id']; @endphp
                    <li wire:key="health-{{ $h['brand_id'] }}">
                        <button type="button" wire:click="$set('brandId', {{ $on ? 'null' : $h['brand_id'] }})" title="{{ $h['brand'] }}: son {{ RepairDesk::TRACK_DAYS }} günde {{ $h['done'] }} iş bitti, {{ $h['open'] }} iş kaldı{{ $h['critical'] > 0 ? ', '.$h['critical'].' kritik' : '' }}" @class(['block w-full rounded-lg px-2 py-1.5 text-left', 'bg-gray-100 dark:bg-gray-800' => $on, 'hover:bg-gray-50 dark:hover:bg-white/5' => ! $on]) data-health-brand="{{ $h['brand_id'] }}">
                            <span @class(['block break-words text-gray-800 dark:text-white/90', 'font-semibold' => $on, 'font-medium' => ! $on])>{{ $h['brand'] }}</span>
                            <span class="block text-xs"><span @class(['font-semibold', 'text-emerald-700 dark:text-emerald-400' => $h['percent'] >= 80, 'text-gray-700 dark:text-gray-300' => $h['percent'] < 80])>%{{ $h['percent'] }} kusursuz</span> <span class="text-gray-500">· {{ $h['open'] }} iş kaldı</span></span>
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($brandId !== null)
                <a href="{{ route('operator.brand', ['brand' => $brandId]) }}" wire:navigate class="mt-2 block px-2 text-xs font-semibold text-gray-700 hover:underline dark:text-gray-300" data-brand-link>Marka sayfasına git →</a>
            @endif
        </aside>

        <div class="min-w-0 space-y-4">
        @if ($section === RepairDeskPage::SECTION_OTHER)
            <livewire:operator.work.work-page :embedded="true" :brand-filter="$brandId" :key="'desk-other-'.($brandId ?? 'all')" />
        @else
            {{-- Onaydan sonra --}}
            <section class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4" data-repair-pipeline>
                @foreach ([['queued', 'Onaylandı, sırada', 'text-gray-800 dark:text-white/90'], ['written', 'Siteye yazıldı', 'text-gray-800 dark:text-white/90'], ['verified', 'Doğrulandı', 'text-emerald-600 dark:text-emerald-400'], ['returned', 'Hata, masaya döndü', 'text-amber-600 dark:text-amber-400']] as [$stage, $label, $tone])
                    <div class="rounded-xl bg-white px-4 py-2 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <p class="text-xs text-gray-500">{{ $label }}</p>
                        <p class="text-lg font-semibold {{ $tone }}">{{ $pipeline[$stage] }}</p>
                    </div>
                @endforeach
                <p class="col-span-full text-xs text-gray-500">Son {{ RepairDesk::TRACK_DAYS }} günde onayladıkların. Yazılamayan ya da geri alınan iş hatasıyla birlikte masaya kendiliğinden döner.</p>
            </section>

            {{-- Türler: paket listesinin içinde küçük ikincil süzgeç --}}
            <div class="flex flex-wrap items-center gap-1.5 text-xs" data-repair-kinds>
                @if (count(array_filter($counts['kinds'])) > 1 || $kind !== '')
                    <span class="text-gray-500">Tür:</span>
                    <button type="button" wire:click="$set('kind', '')" @class(['rounded-md px-2 py-0.5 ring-1 ring-inset', 'bg-gray-800 text-white ring-gray-800 dark:bg-white dark:text-gray-900' => $kind === '', 'text-gray-600 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $kind !== ''])>Hepsi</button>
                    @foreach (RepairDesk::KINDS as $key => $label)
                        @if (($counts['kinds'][$key] ?? 0) > 0)
                            <button type="button" wire:click="$set('kind', '{{ $kind === $key ? '' : $key }}')" @class(['rounded-md px-2 py-0.5 ring-1 ring-inset', 'bg-gray-800 text-white ring-gray-800 dark:bg-white dark:text-gray-900' => $kind === $key, 'text-gray-600 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $kind !== $key])>{{ $label }} {{ $counts['kinds'][$key] }}</button>
                        @endif
                    @endforeach
                @endif
                @if ($isAdmin && $laneTotal > 0)
                    <span class="ml-auto flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="selectAllMatching" class="rounded-lg border border-gray-300 px-2.5 py-1 text-gray-700 dark:border-gray-700 dark:text-gray-300">Bu şeritteki tümünü seç ({{ $laneTotal }})</button>
                        @if ($lane === RepairDesk::LANE_READY && $lowTotal > 0)
                            @if ($lowTotal > $bulkLimit)
                                <button type="button" @click="ask = {{ $ask('Görünen bütün düşük riskli işler uygulansın mı?', $lowSample, $lowTotal, 'approveAllLow') }}" class="rounded-lg bg-brand-500 px-3 py-1 font-semibold text-white" data-approve-low>Düşük risklilerin hepsini onayla ({{ $lowTotal }})</button>
                            @else
                                <button type="button" wire:click="approveAllLow" wire:confirm="Görünen bütün düşük riskli işler uygulansın mı?" class="rounded-lg bg-brand-500 px-3 py-1 font-semibold text-white" data-approve-low>Düşük risklilerin hepsini onayla ({{ $lowTotal }})</button>
                            @endif
                        @endif
                    </span>
                @endif
            </div>

            {{-- Paketler --}}
            <div class="space-y-3" data-repair-packages>
                @forelse ($packages as $package)
                    @php
                        $isOpen = $open === $package['key'];
                        $approvable = $package['count'] - $package['high'];
                        $isManual = $package['lane'] === RepairDesk::LANE_MANUAL;
                    @endphp
                    <article class="{{ $card }}" wire:key="package-{{ $package['key'] }}" data-package="{{ $package['key'] }}">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('operator.brand', ['brand' => $package['brand_id']]) }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-800 hover:underline">{{ $package['brand'] }}</a>
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $package['count'] }} · {{ RepairDesk::KINDS[$package['kind']] }}</h3>
                                @if ($package['high'] > 0)<p class="text-xs text-amber-700 dark:text-amber-300">{{ $package['high'] }} iş yüksek riskli: paketi açıp tek tek onaylanır.</p>@endif
                            </div>
                            @if ($isAdmin)
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    @if ($approvable > 0)
                                        @if (! $isManual && $approvable > $bulkLimit)
                                            <button type="button" @click="ask = {{ $ask($package['brand'].': '.$approvable.' iş uygulansın mı?', $package['sample'], $approvable, 'approvePackage', [$package['key']]) }}" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white" data-approve-package>Hepsini onayla ({{ $approvable }})</button>
                                        @else
                                            <button type="button" wire:click="approvePackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $approvable }} iş {{ $isManual ? 'yapıldı olarak işaretlensin' : 'uygulansın' }} mı?" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white" data-approve-package>{{ $isManual ? 'Hepsini yaptım' : 'Hepsini onayla' }} ({{ $approvable }})</button>
                                        @endif
                                    @endif
                                    <button type="button" wire:click="togglePackage('{{ $package['key'] }}')" class="rounded-lg px-2 py-1.5 font-semibold text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">{{ $isOpen ? 'Kapat ▲' : 'İçini aç ▼' }}</button>
                                    <button type="button" wire:click="selectPackage('{{ $package['key'] }}')" class="px-1.5 py-1 text-gray-500 hover:text-gray-800 hover:underline">Seç</button>
                                    <button type="button" wire:click="rejectPackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $package['count'] }} iş reddedilsin mi? Kanıt değişmedikçe geri gelmezler." class="rounded-md border border-gray-200 px-2 py-0.5 text-[11px] text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400" data-reject-package>Hepsini reddet</button>
                                </div>
                            @endif
                        </div>

                        @unless ($isOpen)
                            <ul class="mt-3 space-y-1.5 text-xs text-gray-600 dark:text-gray-400">
                                @foreach ($package['sample'] as $row)
                                    <li class="break-words" title="{{ $rowLabel($row) }}{{ $row['after'] !== [] ? ' → '.$row['after'][0] : '' }}">
                                        <span class="text-gray-700 dark:text-gray-300">{{ $rowLabel($row) }}</span> @if ($row['after'] !== [])<span class="text-gray-400">→</span> <span class="font-medium text-gray-900 dark:text-white/90">{{ $row['after'][0] }}</span>@endif
                                        @if ($row['target'] !== '')<span class="block break-all text-[11px] text-gray-400">{{ $row['target'] }}</span>@endif
                                    </li>
                                @endforeach
                                @if ($package['count'] > 3)<li class="text-gray-400">ve {{ $package['count'] - 3 }} iş daha</li>@endif
                            </ul>
                        @else
                            <div class="mt-3 divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800"
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
                                <p class="py-2 text-xs text-gray-400">Klavye: J / K satır değiştir · X seç · Enter ayrıntı · A onayla · R reddet</p>
                                @foreach ($openRows as $row)
                                    <div class="py-2 data-[focused]:rounded-lg data-[focused]:bg-gray-50 data-[focused]:px-2 dark:data-[focused]:bg-white/5" wire:key="repair-{{ $row['id'] }}" data-row="{{ $row['id'] }}" x-data="{ more: false }">
                                        <div class="flex items-start gap-3 text-sm">
                                            @if ($isAdmin)
                                                <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" class="mt-1 rounded border-gray-300">
                                            @endif
                                            <button type="button" data-toggle @click="more = ! more" class="min-w-0 flex-1 text-left" title="{{ $row['title'] }}">
                                                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                    @if ($row['risk'] !== 'low')<span class="rounded px-1.5 py-0.5 text-xs {{ $riskTone[$row['risk']] }}">{{ RepairDesk::RISKS[$row['risk']] }}</span>@endif
                                                    <span class="break-words text-gray-700 dark:text-gray-300">{{ $rowLabel($row) }}</span>
                                                    @if ($row['after'] !== [])<span class="text-gray-400">→</span> <span class="break-words font-medium text-gray-900 dark:text-white/90">{{ $row['after'][0] }}</span>@endif
                                                </span>
                                                @if ($row['target'] !== '')<span class="block break-all text-xs text-gray-400" data-row-target>{{ $row['target'] }}</span>@endif
                                            </button>
                                            @if ($isAdmin)
                                                <button type="button" wire:click="approve({{ $row['id'] }})" @class(['shrink-0 rounded-lg px-3 py-1 text-xs font-semibold', 'border border-brand-500 text-brand-600' => $row['risk'] === 'manual', 'bg-brand-500 text-white' => $row['risk'] !== 'manual']) @if ($row['risk'] === 'manual') data-repair-done @endif>{{ $row['risk'] === 'manual' ? 'Yaptım' : 'Onayla' }}</button>
                                            @endif
                                        </div>
                                        <div x-show="more" x-cloak class="mt-2 space-y-2 pl-7 text-xs">
                                            @if ($rowLabel($row) !== $row['title'])<p class="text-gray-500">{{ $row['title'] }}</p>@endif
                                            @if ($row['reason'] !== '')<p class="text-gray-500">Neden: {{ $row['reason'] }}</p>@endif
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                <div class="text-gray-500">
                                                    <p class="font-semibold uppercase text-gray-400">Şimdi</p>
                                                    @forelse ($row['before'] as $n => $line)
                                                        @php $pair = $row['after'][$n] ?? null; $same = $pair !== null && \Illuminate\Support\Str::before($line, ':') === \Illuminate\Support\Str::before($pair, ':'); @endphp
                                                        <p class="break-words">{!! $same ? $words(RepairDesk::diff($line, $pair)['before'], 'bg-gray-200 text-gray-700 line-through dark:bg-gray-700 dark:text-gray-200') : e($line) !!}</p>
                                                    @empty<p>—</p>@endforelse
                                                </div>
                                                <div class="text-gray-800 dark:text-white/90">
                                                    <p class="font-semibold uppercase text-gray-400">{{ $row['risk'] === 'manual' ? 'Yapılacak' : 'Onaylanınca' }}</p>
                                                    @foreach ($row['after'] as $n => $line)
                                                        @php $pair = $row['before'][$n] ?? null; $same = $pair !== null && \Illuminate\Support\Str::before($line, ':') === \Illuminate\Support\Str::before($pair, ':'); @endphp
                                                        <p class="break-words">{!! $same ? $words(RepairDesk::diff($pair, $line)['after'], 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200') : e($line) !!}</p>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @if ($isAdmin)
                                                <div class="flex flex-wrap gap-3">
                                                    @foreach ($row['editable'] as $field)
                                                        <button type="button" wire:click="startEdit({{ $row['id'] }}, '{{ $field }}', @js((string) ($field === 'description' ? ($row['after'][0] ?? '') : \Illuminate\Support\Str::after(collect($row['after'])->first(fn ($l) => str_starts_with($l, $field === 'seo_title' ? 'Başlık: ' : 'Açıklama: ')) ?? '', ': '))))" class="font-semibold text-gray-700 hover:underline dark:text-gray-300">{{ $editLabel[$field] ?? 'Düzelt' }}</button>
                                                    @endforeach
                                                    <button type="button" wire:click="rejectOne({{ $row['id'] }})" wire:confirm="Bu öneri reddedilsin mi?" class="text-gray-500 hover:underline">Reddet</button>
                                                </div>
                                            @endif
                                        </div>
                                        @if ($editing === $row['id'])
                                            <form wire:submit="saveEdit" class="mt-2 flex flex-wrap gap-2 pl-7">
                                                <textarea wire:model="editValue" rows="2" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
                                                <button type="submit" class="rounded-lg bg-brand-500 px-3 py-1 text-xs text-white">Kaydet</button>
                                                <button type="button" wire:click="$set('editing', null)" class="text-xs text-gray-500">Vazgeç</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($openTotal > $openRows->count())
                                    <button type="button" wire:click="showMore" class="w-full py-2 text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $openTotal - $openRows->count() }} iş daha göster</button>
                                @endif
                            </div>
                        @endunless
                    </article>
                @empty
                    <p class="{{ $card }} text-sm text-gray-500">Onay bekleyen hazır düzeltme yok.</p>
                @endforelse
            </div>

            {{-- Seçim çubuğu --}}
            @if ($isAdmin && $selected !== [])
                @php $selectedCount = count($selected); @endphp
                <div class="sticky bottom-3 z-30 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-lg dark:bg-gray-800" data-repair-selection>
                    <span class="font-semibold">{{ $selectedCount }} iş seçili</span>
                    @if ($selectedCount > $bulkLimit)
                        <button type="button" @click="ask = {{ $ask($selectedCount.' seçili iş uygulansın mı?', $selectedSample, $selectedCount, 'approveSelected') }}" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold">Seçilenleri onayla ({{ $selectedCount }})</button>
                    @else
                        <button type="button" wire:click="approveSelected" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold">Seçilenleri onayla ({{ $selectedCount }})</button>
                    @endif
                    <input type="text" wire:model="rejectReason" placeholder="Red sebebi (isteğe bağlı)" class="w-56 rounded-lg border-0 bg-white/10 px-2 py-1 text-white placeholder:text-gray-400">
                    <button type="button" wire:click="rejectSelected" class="rounded-md border border-white/20 px-2 py-1 text-xs text-gray-300 hover:text-white">Seçilenleri reddet ({{ $selectedCount }})</button>
                    <button type="button" wire:click="clearSelection" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
                </div>
            @endif
        @endif
        </div>
    </div>

    {{-- Yapılanlar: üstteki "Son 7 gün" rozeti buraya getirir ve listeyi açar --}}
    <section id="yapilanlar" class="{{ $card }} scroll-mt-20 text-sm" data-repair-writes x-data="{ all: false }" x-on:show-done.window="all = true">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Yapılanlar <span class="text-sm font-normal text-gray-500">· son 7 gün</span></h2>
            @if ($done->isNotEmpty())
                <p class="text-xs text-gray-500">{{ $done->sum('ok') }} iş tam, {{ $done->sum('partial') }} kısmen, {{ $done->sum('failed') }} yazılamadı @if ($done->sum('pending') > 0)· {{ $done->sum('pending') }} sırada @endif</p>
            @endif
        </div>
        <ul class="mt-3 space-y-2">
            @forelse ($done as $n => $group)
                <li class="rounded-lg px-3 py-2 ring-1 ring-inset ring-gray-200 dark:ring-gray-800" wire:key="done-{{ $group['key'] }}" x-data="{ more: false }" @if ($n >= 6) x-show="all" x-cloak @endif>
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-gray-500">{{ $group['at'] }} · {{ $group['asset'] }} · {{ $group['label'] }}</p>
                            <p class="font-medium text-gray-800 dark:text-white/90">
                                @if ($group['failed'] > 0 && $group['ok'] + $group['partial'] === 0)<span class="text-amber-600">✕</span>@elseif ($group['partial'] > 0 || $group['failed'] > 0)<span class="text-amber-600">◐</span>@else<span class="text-emerald-600">✓</span>@endif
                                {{ $group['sentence'] }}.
                            </p>
                            @foreach ($group['problems'] as $problem)
                                <p class="text-xs text-amber-700 dark:text-amber-300">Yapılamayan: {{ $problem }}</p>
                            @endforeach
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            <button type="button" @click="more = ! more" class="font-semibold text-gray-700 hover:underline dark:text-gray-300" x-text="more ? 'Kapat' : 'Ayrıntı ({{ count($group['items']) }})'"></button>
                            @if ($isAdmin && collect($group['items'])->contains('undoable', true))
                                <button type="button" wire:click="undoGroup('{{ $group['key'] }}')" wire:confirm="{{ $group['asset'] }}: bu gruptaki işler geri alınsın mı?" class="text-gray-500 hover:underline">Hepsini geri al</button>
                            @endif
                        </div>
                    </div>
                    <ul x-show="more" x-cloak class="mt-2 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                        @foreach ($group['items'] as $item)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="write-{{ $item['id'] }}">
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</span> · <span class="text-gray-500">{{ $item['status'] }}</span>@if ($item['error'] !== '') <span class="text-amber-700 dark:text-amber-300">· {{ \Illuminate\Support\Str::limit($item['error'], 300) }}</span>@endif
                                    @if ($item['page'] !== '')<span class="block break-all text-gray-400">{{ $item['page'] }}</span>@endif
                                    @foreach ($item['lines'] as $line)<span @class(['block break-words', 'text-gray-700 dark:text-gray-300' => $line['ok'], 'text-amber-700 dark:text-amber-300' => ! $line['ok']])>{{ $line['ok'] ? '✓' : '✕' }} {{ $line['text'] }}</span>@endforeach
                                </span>
                                @if ($isAdmin && $item['undoable'])
                                    <button type="button" wire:click="undo({{ $item['id'] }})" wire:confirm="Bu değişiklik geri alınsın mı?" class="font-semibold text-gray-700 hover:underline dark:text-gray-300">Geri al</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </li>
            @empty
                <li class="text-gray-500">Henüz yok.</li>
            @endforelse
        </ul>
        @if ($done->count() > 6)
            <button type="button" @click="all = ! all" class="mt-2 text-xs font-semibold text-gray-700 dark:text-gray-300" x-text="all ? 'Daha az göster' : 'Tümünü göster ({{ $done->count() }})'"></button>
        @endif
    </section>

    {{-- Toplu onay penceresi: 10'dan fazla iş tek tıkla gitmez; 3 örnek ve "Geri alınabilir" notu --}}
    <div x-show="ask" x-cloak @keydown.escape.window="ask = null" class="fixed inset-0 z-[99999] flex items-center justify-center p-4" role="dialog" aria-modal="true" data-bulk-confirm>
        <div class="fixed inset-0 bg-gray-900/50" @click="ask = null"></div>
        <div class="relative w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white" x-text="ask?.title"></h3>
            <p class="mt-1 text-xs text-gray-500">Örnek olarak ilk üç iş:</p>
            <ul class="mt-2 space-y-1.5 text-sm">
                <template x-for="(line, n) in (ask?.samples ?? [])" :key="n">
                    <li class="break-words rounded-lg bg-gray-50 px-3 py-1.5 text-gray-800 dark:bg-white/5 dark:text-gray-200" x-text="line"></li>
                </template>
                <li class="text-xs text-gray-500" x-show="ask?.more > 0" x-text="'ve ' + ask?.more + ' iş daha'"></li>
            </ul>
            <p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Geri alınabilir: her uygulanan iş kayda geçer, "Yapılanlar"dan tek tek ya da topluca geri alınır.</p>
            <div class="mt-4 flex justify-end gap-2 text-sm">
                <button type="button" @click="ask = null" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-700 dark:text-gray-300">Vazgeç</button>
                <button type="button" @click="go()" class="rounded-lg bg-brand-500 px-4 py-1.5 font-semibold text-white">Evet, onayla</button>
            </div>
        </div>
    </div>
</div>
