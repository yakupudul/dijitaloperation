@php
    // İşletme profilleri › Gönderiler (panel concept 2026-10-20). The question: "Which posts wait for my approval, and is
    // every profile planned for the next 30 days?" The four work numbers filter the list; then one card with a row per
    // brand (its approve button on the right) that opens to a row per profile with its 30-day strip.
    $chip = fn (string $status): string => match ($status) {
        'draft', 'failed' => 'look',
        'published' => 'ok',
        default => 'info',
    };
    $square = fn (?string $status): string => match ($status) {
        'draft' => 'bg-warning-400',
        'approved' => 'bg-gray-500',
        'published' => 'bg-success-500',
        'failed' => 'bg-error-500',
        default => 'bg-gray-200 dark:bg-gray-700',
    };
    $statusText = ['draft' => 'onay bekliyor', 'approved' => 'onaylı', 'published' => 'yayında', 'failed' => 'yayınlanamadı'];
    $hints = ['draft' => 'onayını bekleyen gönderi', 'week' => 'onaylı, 7 gün içinde', 'failed' => 'Google’a gidemeyen gönderi', 'low' => $lowDays.' günden azı planlı'];
    $card = ['chip' => $chip, 'angles' => $angles, 'canWrite' => $canWrite, 'editing' => $editing];
@endphp
<div class="dark:text-gray-200" data-gbp-post-plan>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-posts', 'brandFilter' => $brand])

    <div class="space-y-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="page-title" title="Her İşletme Profili için önümüzdeki {{ $horizon }} gün, günde bir gönderi; markanın sitesindeki sayfalardan yazılır. Onaylananlar günü gelince saat 10:00 civarında kendiliğinden yayınlanır.">İşletme profilleri</h1>
                <p class="page-sub">Önümüzdeki {{ $horizon }} gün, günde bir gönderi{{ $totals['draft'] > 0 ? '; '.$totals['draft'].' gönderi onayını bekliyor.' : '.' }}</p>
            </div>
            @if ($canWrite)
                <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                    <button type="button" wire:click="fillNow" wire:loading.attr="disabled" class="btn">Boş günleri şimdi doldur</button>
                    <button type="button" @disabled($totals['draft'] === 0) x-on:click="if (confirm('Önümüzdeki {{ $horizon }} günün onay bekleyen {{ $totals['draft'] }} gönderisi onaylansın mı? Her biri kendi gününde yayınlanır.')) $wire.approveAll()"
                        class="btn-primary">Tümünü onayla ({{ $totals['draft'] }})</button>
                </div>
            @endif
        </header>

        @if ($message !== '' || ! $canWrite)
            <div class="space-y-2">
                @if ($message !== '')<p role="status" class="tone-info rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm dark:border-gray-800">{{ $message }}</p>@endif
                @unless ($canWrite)<p class="text-[13px] text-gray-500">Onay, düzenleme ve atlama yalnız Admin’dedir.</p>@endunless
            </div>
        @endif

        @if ($readBrand !== null)
            {{-- Okuma modu: one brand's drafts one under the other, approved together at the end. --}}
            <section class="space-y-3" data-reading>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <button type="button" wire:click="stopReading" class="btn-quiet -ml-2.5"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15 7 10l5-5"/></svg>Listeye dön</button>
                    <h2 class="section-title min-w-0 break-words">{{ $readBrand->name }} · onay bekleyen {{ $readTotal }} gönderi</h2>
                </div>
                <div class="card">
                    @forelse ($reading as $post)
                        @include('livewire.operator.gbp.partials.post-card', $card + ['post' => $post, 'showLocation' => true])
                    @empty
                        <x-ta.empty-state size="sm" title="Bu markada onay bekleyen gönderi yok." />
                    @endforelse
                </div>
                <div class="grid gap-2 sm:flex sm:flex-wrap">
                    @if ($readTotal > $reading->count())<button type="button" wire:click="readMore" class="btn">Daha fazla göster ({{ $readTotal - $reading->count() }})</button>@endif
                    @if ($canWrite && $readTotal > 0)
                        <button type="button" x-on:click="if (confirm('{{ $readBrand->name }} için onay bekleyen {{ $readTotal }} gönderi onaylansın mı?')) $wire.approveAll(null, {{ $readBrand->id }})" class="btn-primary">Bunları onayla ({{ $readTotal }})</button>
                    @endif
                </div>
            </section>
        @else
            {{-- 1. The work, as four numbers you can click to filter. --}}
            <section class="space-y-2.5">
                <div class="metric-strip grid-cols-2 lg:grid-cols-4" aria-label="Süzgeç">
                    @foreach ($filters as $key => $label)
                        <button type="button" wire:click="setFilter('{{ $key }}')" @class(['flex min-w-0 flex-col bg-white px-[18px] py-3.5 text-left transition dark:bg-gray-900', 'shadow-[inset_0_-2px_0_var(--color-gray-900)] dark:shadow-[inset_0_-2px_0_var(--color-white)]' => $filter === $key, 'hover:bg-gray-50 dark:hover:bg-white/[0.03]' => $filter !== $key]) @if ($filter === $key) aria-pressed="true" @endif>
                            <span @class(['text-[13px]', 'font-medium text-gray-900 dark:text-white' => $filter === $key, 'text-gray-500' => $filter !== $key])>{{ $label }}{{ $key === 'low' ? ' işletme' : '' }}</span>
                            <span @class(['mt-1 text-[22px] font-semibold leading-7 tabular-nums tracking-[-0.02em]', 'text-warning-700 dark:text-warning-400' => in_array($key, ['draft', 'failed'], true) && $totals[$key] > 0, 'text-gray-900 dark:text-white' => ! (in_array($key, ['draft', 'failed'], true) && $totals[$key] > 0)])>{{ $totals[$key] }}</span>
                            <span class="mt-0.5 text-xs text-gray-500">{{ $hints[$key] }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                    @foreach (['draft' => 'Onay bekliyor', 'approved' => 'Onaylı', 'published' => 'Yayında', 'failed' => 'Yayınlanamadı', null => 'Boş'] as $key => $label)
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm {{ $square($key === '' ? null : $key) }}"></span>{{ $label }}</span>
                    @endforeach
                    @if ($filter !== '')<span class="ml-auto text-[13px]">Süzülen: {{ $filters[$filter] }} · <button type="button" wire:click="setFilter('{{ $filter }}')" class="link-neutral">Süzmeyi kaldır</button></span>@endif
                </div>
            </section>

            {{-- 2. Brands, each opening to its profiles. --}}
            <section class="card">
                @forelse ($brands as $group)
                    @php
                        $brandModel = $group['brand'];
                        $ids = $group['locations']->pluck('id');
                        $sum = fn (string $key): int => (int) $ids->sum(fn ($id): int => $info[$id][$key]);
                        $reasons = $ids->map(fn ($id) => $info[$id]['reason'])->filter()->unique();
                        $isOpen = $this->brand === (int) $brandModel?->id || $brands->count() === 1 || $filter !== '';
                    @endphp
                    <div wire:key="brand-{{ $brandModel?->id }}" class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                        <div @class(['flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3', 'bg-gray-50/70 dark:bg-white/[0.02]' => $isOpen])>
                            <button type="button" wire:click="openBrand({{ (int) $brandModel?->id }})" class="flex min-w-0 basis-full items-center gap-3 text-left sm:flex-1 sm:basis-0" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                                <svg @class(['h-4 w-4 shrink-0 text-gray-400 transition', 'rotate-90' => $isOpen]) viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 5 5 5-5 5"/></svg>
                                <x-ta.brand-avatar :name="(string) $brandModel?->name" :logo="$brandModel?->logo_url" size="sm" />
                                <span class="min-w-0">
                                    <span class="block break-words text-sm font-medium text-gray-900 dark:text-white">{{ $brandModel?->name }}</span>
                                    <span class="block text-[13px] text-gray-500">{{ $ids->count().' işletme, '.$sum('planned').'/'.($ids->count() * $horizon).' gün planlı'.($reasons->count() === 1 ? ', '.$reasons->first() : '') }}</span>
                                </span>
                            </button>
                            @if ($sum('drafts') > 0 || $sum('failed') > 0)
                                <span class="flex flex-wrap items-center gap-x-3 gap-y-1 max-sm:pl-7">
                                    @if ($sum('drafts') > 0)<x-ta.pill tone="look">{{ $sum('drafts') }} onay bekliyor</x-ta.pill>@endif
                                    @if ($sum('failed') > 0)<x-ta.pill tone="look">{{ $sum('failed') }} yayınlanamadı</x-ta.pill>@endif
                                </span>
                            @endif
                            @if ($sum('drafts') > 0)
                                <span class="flex gap-1.5 max-sm:w-full max-sm:pl-7">
                                    <button type="button" wire:click="startReading({{ (int) $brandModel?->id }})" class="btn-quiet">Oku</button>
                                    @if ($canWrite)<button type="button" x-on:click="if (confirm('{{ $brandModel?->name }} için {{ $sum('drafts') }} gönderi onaylansın mı?')) $wire.approveAll(null, {{ (int) $brandModel?->id }})" class="btn max-sm:flex-1">Markayı onayla ({{ $sum('drafts') }})</button>@endif
                                </span>
                            @endif
                        </div>

                        @if ($isOpen)
                            @foreach ($group['locations'] as $location)
                                @php
                                    $i = $info[$location->id];
                                    $state = $i['state'];
                                    $isLocation = $this->location === (int) $location->id;
                                @endphp
                                <div wire:key="loc-{{ $location->id }}" class="border-t border-gray-200 dark:border-gray-800">
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:pl-11">
                                        <button type="button" wire:click="open({{ $location->id }})" class="min-w-0 basis-full text-left sm:flex-1 sm:basis-0" title="{{ $location->name }}">
                                            <span @class(['block truncate text-sm font-medium text-gray-900 dark:text-white', 'underline decoration-gray-300 underline-offset-4' => $isLocation])>{{ \App\Livewire\Operator\Gbp\PostPlanPage::shortName((string) $location->name) }}</span>
                                            <span class="block text-[13px] text-gray-500">{{ $i['planned'] }}/{{ $horizon }} gün{{ $i['drafts'] > 0 ? ', '.$i['drafts'].' onay bekliyor' : '' }}@if ($i['reason']), <span class="text-warning-700 dark:text-warning-400">{{ $i['reason'] }}</span>@endif</span>
                                            @if ($state && ($state['status'] ?? '') !== 'ready')<span @class(['block text-[13px]', 'text-error-600 dark:text-error-400' => ($state['status'] ?? '') === 'failed', 'text-gray-500' => ($state['status'] ?? '') !== 'failed']) @if (($state['status'] ?? '') === 'running') wire:poll.10s @endif>{{ $state['message'] ?? '' }}</span>@endif
                                        </button>
                                        <div class="flex max-w-full flex-wrap gap-0.5" aria-label="30 günlük plan">
                                            @foreach ($days as $index => $d)
                                                @php $s = $i['strip'][$d] ?? null; @endphp
                                                <button type="button" wire:click="openDay({{ $location->id }}, '{{ $d }}')" title="{{ \Illuminate\Support\Carbon::parse($d)->locale('tr')->translatedFormat('d M D') }}: {{ $statusText[$s] ?? 'boş' }}"
                                                    @class(['h-4 w-2 rounded-sm sm:w-2.5', $square($s), 'ring-1 ring-gray-900 dark:ring-white' => $index === 0, 'outline outline-2 outline-gray-900 dark:outline-white' => $isLocation && $this->day === $d])></button>
                                            @endforeach
                                        </div>
                                        @if ($canWrite)
                                            <span class="flex w-28 justify-end max-sm:w-auto">
                                                @if ($i['posted_today'])
                                                    <x-ta.pill tone="ok">Bugün paylaşıldı</x-ta.pill>
                                                @else
                                                    <button type="button" wire:click="startShare({{ $location->id }})" class="btn h-7! px-2.5!">Bugün paylaş</button>
                                                @endif
                                            </span>
                                        @endif
                                    </div>

                                    @if ($sharing === (int) $location->id)
                                        <div class="mx-4 mb-3 rounded-lg border border-gray-200 bg-gray-50/70 p-3.5 text-sm sm:ml-11 dark:border-gray-800 dark:bg-white/[0.02]" data-testid="gbp-share-today">
                                            @if ($shareCandidate === null)
                                                <p class="text-gray-600 dark:text-gray-300">Havuzda bu işletme için hazır gönderi yok. “Boş günleri şimdi doldur” ile hazırlatın ya da profil ekranından elle gönderi yazın.</p>
                                                <button type="button" wire:click="startShare({{ $location->id }})" class="btn-quiet mt-2 -ml-2.5">Kapat</button>
                                            @else
                                                @php $from = substr((string) $shareCandidate->publish_on, 0, 10); @endphp
                                                <p class="text-[13px] text-gray-500">Şimdi paylaşılacak: havuzdaki {{ $shareTotal }} gönderiden {{ ($shareSkip % max(1, $shareTotal)) + 1 }}.
                                                    {{ $from === $days[0] ? 'Bugünün gönderisi' : \Illuminate\Support\Carbon::parse($from)->locale('tr')->translatedFormat('d F').' gününden alınır; o gün yeniden planlanır' }},
                                                    {{ $shareCandidate->status === \App\Models\GbpQueuedPost::APPROVED ? 'onaylı' : 'taslak (bu tıklama onaydır)' }}</p>
                                                <p class="mt-1.5 whitespace-pre-line break-words leading-6 text-gray-800 dark:text-gray-200">{{ $shareCandidate->summary }}</p>
                                                @if ($shareCandidate->page)<p class="mt-1 text-[13px] text-gray-500">Bağlantı: <a href="{{ $shareCandidate->url ?? $shareCandidate->page->url }}" target="_blank" rel="noopener" class="link-neutral break-words">{{ $shareCandidate->page->title }}</a></p>@endif
                                                <div class="mt-2.5 grid gap-2 sm:flex sm:flex-wrap sm:items-center">
                                                    <button type="button" wire:click="confirmShare({{ $shareCandidate->id }})" wire:loading.attr="disabled" class="btn-primary">Şimdi paylaş</button>
                                                    @if ($shareTotal > 1)<button type="button" wire:click="nextShare" class="btn">Başka gönderi</button>@endif
                                                    <button type="button" wire:click="startShare({{ $location->id }})" class="btn-quiet">Vazgeç</button>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($isLocation)
                                        <div class="space-y-2.5 px-4 pb-4 sm:pl-11">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <a href="{{ route('operator.gbp', ['assetId' => $location->id, 'tab' => 'posts']) }}" wire:navigate class="btn-quiet -ml-2.5">Profil ekranı</a>
                                                @if ($this->day !== null)<button type="button" wire:click="open({{ $location->id }})" class="btn-quiet">Tüm günler</button>@endif
                                                @if ($canWrite && $i['drafts'] > 0)
                                                    <button type="button" wire:click="approveAll({{ $location->id }})" class="btn ml-auto max-sm:w-full">Bu işletmenin taslaklarını onayla ({{ $i['drafts'] }})</button>
                                                @endif
                                            </div>
                                            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.02]">
                                                @forelse ($rows as $post)
                                                    @include('livewire.operator.gbp.partials.post-card', $card + ['post' => $post])
                                                @empty
                                                    <p class="px-4 py-3.5 text-sm text-gray-500">{{ $this->day !== null ? 'Bu gün için gönderi yok.' : ($i['reason'] ?? 'Bu işletme için plan yok.') }} Boş günler her sabah kendiliğinden dolar.</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                @empty
                    <x-ta.empty-state size="sm" icon="none" :title="$filter !== '' ? 'Bu süzmeye uyan işletme yok.' : 'Operasyonel markaya bağlı İşletme Profili yok.'" />
                @endforelse
            </section>
        @endif
    </div>
</div>
