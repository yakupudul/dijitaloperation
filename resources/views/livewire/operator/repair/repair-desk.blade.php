@php
    use App\Services\Repair\RepairDesk;
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $riskTone = ['low' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'medium' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'high' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300', 'manual' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'];
    $laneActive = [RepairDesk::LANE_READY => 'bg-emerald-50 ring-emerald-300 dark:bg-emerald-500/10 dark:ring-emerald-500/40', RepairDesk::LANE_REVIEW => 'bg-amber-50 ring-amber-300 dark:bg-amber-500/10 dark:ring-amber-500/40', RepairDesk::LANE_MANUAL => 'bg-sky-50 ring-sky-300 dark:bg-sky-500/10 dark:ring-sky-500/40'];
    $laneHint = [
        RepairDesk::LANE_READY => 'Düşük riskli, hazır işler. Paketin tamamını tek tıkla onaylayabilirsin.',
        RepairDesk::LANE_REVIEW => 'Birleştirme, sayfa metni, teknik düzeltme gibi işler. Eski ve yeni yan yana, değişen kelimeler renkli. Sayfa metni tek tek onaylanır.',
        RepairDesk::LANE_MANUAL => 'Sistemin henüz yapamadığı işler. "Yaptım" deyince bir hafta gizlenir; gece denetimi sorun kalmadıysa kapatır.',
    ];
    $editLabel = ['seo_title' => 'Başlığı düzelt', 'meta_description' => 'Açıklamayı düzelt', 'description' => 'Metni düzelt'];
    $words = function (array $parts, string $tone): string {
        return collect($parts)->map(fn (array $p): string => $p[1] ? '<mark class="rounded px-0.5 '.$tone.'">'.e($p[0]).'</mark>' : e($p[0]))->implode('');
    };
@endphp
<div class="space-y-5 pb-24" data-repair-desk>
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Onarım masası</h1>
        <p class="mt-1 text-sm text-gray-500">Sistemin hazırladığı düzeltmeler paket paket: aynı markanın aynı türdeki işleri tek kararda. Onayladığın an uygulanır, kayda geçer ve geri alınabilir. Değer hazır olmayan öneriler her gece kendiliğinden hazırlanıp buraya gelir.</p>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-brand-50 px-4 py-2 text-sm text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $message }}</p>
    @endif

    {{-- Marka sağlığı --}}
    <section class="{{ $card }}" data-repair-health>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Marka sağlığı <span class="text-sm font-normal text-gray-500">· kusursuza kalan iş</span></h2>
            <span class="rounded-lg bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-800 dark:bg-gray-800 dark:text-white/90">Onay bekleyen {{ $counts['total'] }}</span>
        </div>
        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @if ($brandId !== null)
                <button type="button" wire:click="$set('brandId', null)" class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-left text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">← Tüm markalar</button>
                <a href="{{ route('operator.brand', ['brand' => $brandId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-left text-sm font-semibold text-brand-600 hover:underline" data-brand-link>Marka sayfasına git →</a>
            @endif
            @foreach ($health->take(12) as $h)
                <button type="button" wire:key="health-{{ $h['brand_id'] }}" wire:click="$set('brandId', {{ $brandId === $h['brand_id'] ? 'null' : $h['brand_id'] }})" @class(['rounded-lg px-3 py-2 text-left text-sm ring-1 ring-inset', 'bg-brand-50 ring-brand-300 dark:bg-brand-500/10 dark:ring-brand-500/40' => $brandId === $h['brand_id'], 'ring-gray-200 hover:bg-gray-50 dark:ring-gray-800 dark:hover:bg-white/5' => $brandId !== $h['brand_id']])>
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="truncate font-medium text-gray-800 dark:text-white/90">{{ $h['brand'] }}</span>
                        <span class="shrink-0 text-xs text-gray-500">{{ $h['open'] }} iş</span>
                    </span>
                    <span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><span class="block h-full rounded-full bg-emerald-500" style="width: {{ $h['percent'] }}%"></span></span>
                    <span class="mt-1 block text-xs text-gray-500">{{ $h['done'] }} iş son {{ RepairDesk::TRACK_DAYS }} günde bitti</span>
                </button>
            @endforeach
        </div>
        @if ($health->count() > 12)
            <label class="mt-2 flex items-center gap-2 text-sm">
                <span class="text-xs text-gray-500">Diğer markalar</span>
                <select wire:model.live="brandId" class="rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Tümü</option>
                    @foreach ($brands as $id => $name)
                        @if (($counts['brands'][$id] ?? 0) > 0)<option value="{{ $id }}">{{ $name }} ({{ $counts['brands'][$id] }})</option>@endif
                    @endforeach
                </select>
            </label>
        @endif
    </section>

    {{-- Tek iş listesi (2026-10-10): hazır düzeltmeler ve diğer işler aynı masada --}}
    <nav class="flex gap-5 border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Bölümler" role="tablist" data-desk-sections>
        @foreach ([\App\Livewire\Operator\Repair\RepairDeskPage::SECTION_DESK => ['Hazır düzeltmeler', $counts['total']], \App\Livewire\Operator\Repair\RepairDeskPage::SECTION_OTHER => ['Diğer işler', $otherCount]] as $code => [$label, $n])
            <button type="button" role="tab" wire:click="$set('section', '{{ $code }}')" aria-selected="{{ $section === $code ? 'true' : 'false' }}" @class(['-mb-px h-11 shrink-0 border-b-2 whitespace-nowrap', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $section === $code, 'border-transparent text-gray-500 hover:text-gray-800' => $section !== $code])>
                {{ $label }} @if ($n > 0)<span class="ml-1 rounded-full bg-gray-100 px-1.5 text-xs text-gray-600 dark:bg-gray-800">{{ number_format($n, 0, ',', '.') }}</span>@endif
            </button>
        @endforeach
    </nav>

    @if ($section === \App\Livewire\Operator\Repair\RepairDeskPage::SECTION_OTHER)
        <livewire:operator.work.work-page :embedded="true" :brand-filter="$brandId" :key="'desk-other-'.($brandId ?? 'all')" />
    @else

    {{-- Onaydan sonra --}}
    <section class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4" data-repair-pipeline>
        @foreach ([['queued', 'Onaylandı, sırada', 'text-gray-800 dark:text-white/90'], ['written', 'Siteye yazıldı', 'text-brand-600 dark:text-brand-300'], ['verified', 'Doğrulandı', 'text-emerald-600 dark:text-emerald-400'], ['returned', 'Hata, masaya döndü', 'text-red-600 dark:text-red-400']] as [$stage, $label, $tone])
            <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="text-xl font-semibold {{ $tone }}">{{ $pipeline[$stage] }}</p>
            </div>
        @endforeach
        <p class="col-span-full text-xs text-gray-500">Son {{ RepairDesk::TRACK_DAYS }} günde onayladıkların. Yazılamayan ya da geri alınan iş hatasıyla birlikte masaya kendiliğinden döner.</p>
    </section>

    {{-- Şeritler --}}
    <div class="flex flex-wrap gap-2" data-repair-lanes>
        @foreach (RepairDesk::LANES as $key => $label)
            <button type="button" wire:click="$set('lane', '{{ $key }}')" @class(['rounded-xl px-4 py-2 text-left text-sm ring-1 ring-inset', $laneActive[$key].' font-semibold text-gray-900 dark:text-white' => $lane === $key, 'ring-gray-200 text-gray-600 hover:bg-gray-50 dark:ring-gray-800 dark:text-gray-300' => $lane !== $key])>
                {{ $label }} <span class="ml-1 rounded-full bg-white/70 px-2 text-xs dark:bg-gray-800">{{ $laneCounts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    <p class="-mt-3 text-xs text-gray-500">{{ $laneHint[$lane] ?? '' }}</p>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        @foreach (RepairDesk::KINDS as $key => $label)
            @if (($counts['kinds'][$key] ?? 0) > 0)
                <button type="button" wire:click="$set('kind', '{{ $kind === $key ? '' : $key }}')" @class(['rounded-lg px-3 py-1 text-xs', 'bg-brand-500 text-white' => $kind === $key, 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $kind !== $key])>{{ $label }} {{ $counts['kinds'][$key] }}</button>
            @endif
        @endforeach
        @if ($isAdmin && $laneTotal > 0)
            <span class="ml-auto flex flex-wrap items-center gap-2">
                <button type="button" wire:click="selectAllMatching" class="rounded-lg border border-gray-300 px-3 py-1 text-xs text-gray-700 dark:border-gray-700 dark:text-gray-300">Bu şeritteki tümünü seç ({{ $laneTotal }})</button>
                @if ($lane === RepairDesk::LANE_READY)
                    <button type="button" wire:click="approveAllLow" wire:confirm="Görünen bütün düşük riskli işler uygulansın mı?" class="rounded-lg bg-brand-500 px-3 py-1 text-xs font-semibold text-white" data-approve-low>Düşük risklilerin hepsini onayla</button>
                @endif
            </span>
        @endif
    </div>

    {{-- Paketler --}}
    <div class="space-y-3" data-repair-packages>
        @forelse ($packages as $package)
            @php $isOpen = $open === $package['key']; @endphp
            <article class="{{ $card }}" wire:key="package-{{ $package['key'] }}" data-package="{{ $package['key'] }}">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('operator.brand', ['brand' => $package['brand_id']]) }}" wire:navigate class="text-xs text-gray-500 hover:text-brand-600 hover:underline">{{ $package['brand'] }}</a>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $package['count'] }} · {{ RepairDesk::KINDS[$package['kind']] }}</h3>
                        @if ($package['high'] > 0)<p class="text-xs text-red-600 dark:text-red-400">{{ $package['high'] }} iş yüksek riskli: paketi açıp tek tek onaylanır.</p>@endif
                    </div>
                    @if ($isAdmin)
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            @if ($package['count'] > $package['high'])
                                <button type="button" wire:click="approvePackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $package['count'] - $package['high'] }} iş {{ $package['lane'] === RepairDesk::LANE_MANUAL ? 'yapıldı olarak işaretlensin' : 'uygulansın' }} mı?" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white">{{ $package['lane'] === RepairDesk::LANE_MANUAL ? 'Hepsini yaptım' : 'Hepsini onayla' }} ({{ $package['count'] - $package['high'] }})</button>
                            @endif
                            <button type="button" wire:click="selectPackage('{{ $package['key'] }}')" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-700 dark:text-gray-300">Seç</button>
                            <button type="button" wire:click="rejectPackage('{{ $package['key'] }}')" wire:confirm="{{ $package['brand'] }}: {{ $package['count'] }} iş reddedilsin mi? Kanıt değişmedikçe geri gelmezler." class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-700 dark:text-gray-300">Hepsini reddet</button>
                            <button type="button" wire:click="togglePackage('{{ $package['key'] }}')" class="px-2 py-1.5 font-semibold text-brand-600">{{ $isOpen ? 'Kapat ▲' : 'İçini aç ▼' }}</button>
                        </div>
                    @endif
                </div>

                @unless ($isOpen)
                    <ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-400">
                        @foreach ($package['sample'] as $row)
                            <li class="truncate">· {{ $row['title'] }} @if ($row['after'] !== [])<span class="text-gray-400">→</span> <span class="text-gray-800 dark:text-white/80">{{ $row['after'][0] }}</span>@endif</li>
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
                            <div class="py-2 data-[focused]:rounded-lg data-[focused]:bg-brand-50 data-[focused]:px-2 dark:data-[focused]:bg-brand-500/10" wire:key="repair-{{ $row['id'] }}" data-row="{{ $row['id'] }}" x-data="{ more: false }">
                                <div class="flex items-start gap-3 text-sm">
                                    @if ($isAdmin)
                                        <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" class="mt-1 rounded border-gray-300">
                                    @endif
                                    <button type="button" data-toggle @click="more = ! more" class="min-w-0 flex-1 text-left">
                                        <span class="flex flex-wrap items-center gap-2">
                                            @if ($row['risk'] !== 'low')<span class="rounded px-1.5 py-0.5 text-xs {{ $riskTone[$row['risk']] }}">{{ RepairDesk::RISKS[$row['risk']] }}</span>@endif
                                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</span>
                                        </span>
                                        <span class="block truncate text-xs text-gray-500">
                                            @if ($row['target'] !== ''){{ $row['target'] }} · @endif
                                            @if ($row['before'] !== [])<span class="line-through decoration-red-400/60">{{ $row['before'][0] }}</span> → @endif<span class="text-gray-800 dark:text-white/80">{{ $row['after'][0] ?? '' }}</span>
                                        </span>
                                    </button>
                                    @if ($isAdmin)
                                        <button type="button" wire:click="approve({{ $row['id'] }})" @class(['shrink-0 rounded-lg px-3 py-1 text-xs font-semibold', 'border border-brand-500 text-brand-600' => $row['risk'] === 'manual', 'bg-brand-500 text-white' => $row['risk'] !== 'manual']) @if ($row['risk'] === 'manual') data-repair-done @endif>{{ $row['risk'] === 'manual' ? 'Yaptım' : 'Onayla' }}</button>
                                    @endif
                                </div>
                                <div x-show="more" x-cloak class="mt-2 space-y-2 pl-7 text-xs">
                                    @if ($row['reason'] !== '')<p class="text-gray-500">Neden: {{ $row['reason'] }}</p>@endif
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <div class="text-gray-500">
                                            <p class="font-semibold uppercase text-gray-400">Şimdi</p>
                                            @forelse ($row['before'] as $n => $line)
                                                @php $pair = $row['after'][$n] ?? null; $same = $pair !== null && \Illuminate\Support\Str::before($line, ':') === \Illuminate\Support\Str::before($pair, ':'); @endphp
                                                <p class="break-words">{!! $same ? $words(RepairDesk::diff($line, $pair)['before'], 'bg-red-100 text-red-800 line-through dark:bg-red-500/20 dark:text-red-200') : e($line) !!}</p>
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
                                                <button type="button" wire:click="startEdit({{ $row['id'] }}, '{{ $field }}', @js((string) ($field === 'description' ? ($row['after'][0] ?? '') : \Illuminate\Support\Str::after(collect($row['after'])->first(fn ($l) => str_starts_with($l, $field === 'seo_title' ? 'Başlık: ' : 'Açıklama: ')) ?? '', ': '))))" class="text-brand-600 hover:underline">{{ $editLabel[$field] ?? 'Düzelt' }}</button>
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
                            <button type="button" wire:click="showMore" class="w-full py-2 text-xs font-semibold text-brand-600">{{ $openTotal - $openRows->count() }} iş daha göster</button>
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
        <div class="sticky bottom-3 z-30 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-lg dark:bg-gray-800" data-repair-selection>
            <span class="font-semibold">{{ count($selected) }} iş seçili</span>
            <button type="button" wire:click="approveSelected" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold">Seçilenleri onayla ({{ count($selected) }})</button>
            <input type="text" wire:model="rejectReason" placeholder="Red sebebi (isteğe bağlı)" class="w-56 rounded-lg border-0 bg-white/10 px-2 py-1 text-white placeholder:text-gray-400">
            <button type="button" wire:click="rejectSelected" class="rounded-lg border border-white/30 px-3 py-1.5">Seçilenleri reddet ({{ count($selected) }})</button>
            <button type="button" wire:click="clearSelection" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
        </div>
    @endif

    <section class="{{ $card }} text-sm" data-repair-writes x-data="{ all: false }">
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
                                @if ($group['failed'] > 0 && $group['ok'] + $group['partial'] === 0)<span class="text-red-600 dark:text-red-400">✕</span>@elseif ($group['partial'] > 0 || $group['failed'] > 0)<span class="text-amber-600">◐</span>@else<span class="text-emerald-600">✓</span>@endif
                                {{ $group['sentence'] }}.
                            </p>
                            @foreach ($group['problems'] as $problem)
                                <p class="text-xs text-amber-700 dark:text-amber-300">Yapılamayan: {{ $problem }}</p>
                            @endforeach
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            <button type="button" @click="more = ! more" class="text-brand-600 hover:underline" x-text="more ? 'Kapat' : 'Ayrıntı ({{ count($group['items']) }})'"></button>
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
                                    @if ($item['page'] !== '')<span class="block truncate text-gray-500">{{ $item['page'] }}</span>@endif
                                    @foreach ($item['lines'] as $line)<span @class(['block break-words', 'text-gray-700 dark:text-gray-300' => $line['ok'], 'text-amber-700 dark:text-amber-300' => ! $line['ok']])>{{ $line['ok'] ? '✓' : '✕' }} {{ $line['text'] }}</span>@endforeach
                                </span>
                                @if ($isAdmin && $item['undoable'])
                                    <button type="button" wire:click="undo({{ $item['id'] }})" wire:confirm="Bu değişiklik geri alınsın mı?" class="text-brand-600 hover:underline">Geri al</button>
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
            <button type="button" @click="all = ! all" class="mt-2 text-xs font-semibold text-brand-600" x-text="all ? 'Daha az göster' : 'Tümünü göster ({{ $done->count() }})'"></button>
        @endif
    </section>
    @endif
</div>
