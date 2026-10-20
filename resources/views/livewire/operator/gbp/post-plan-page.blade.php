@php
    $chip = fn (string $status): string => match ($status) {
        'draft' => 'look',
        'published' => 'ok',
        'failed' => 'critical',
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
    $tone = ['draft' => 'text-warning-700 dark:text-warning-300', 'week' => 'text-gray-900 dark:text-white', 'failed' => 'text-error-700 dark:text-error-300', 'low' => 'text-gray-900 dark:text-white'];
    $outline = 'rounded-lg px-2.5 py-1 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $card = ['chip' => $chip, 'angles' => $angles, 'canWrite' => $canWrite, 'editing' => $editing];
@endphp
<div class="space-y-8 dark:text-gray-200" data-gbp-post-plan>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-[28px] font-semibold leading-9 tracking-tight text-gray-900 dark:text-white" title="Her İşletme Profili için önümüzdeki {{ $horizon }} gün, günde bir gönderi; markanın sitesindeki sayfalardan yazılır. Onaylananlar günü gelince saat 10:00 civarında kendiliğinden yayınlanır.">İşletme profilleri</h1>
            <p class="mt-1 text-sm text-gray-500">Önümüzdeki {{ $horizon }} gün, günde bir gönderi</p>
        </div>
        @if ($canWrite)
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <button type="button" wire:click="fillNow" wire:loading.attr="disabled" class="flex-1 rounded-lg px-3 py-1.5 text-sm font-medium sm:flex-none text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700">Boş günleri şimdi doldur</button>
                <button type="button" @disabled($totals['draft'] === 0) x-on:click="if (confirm('Önümüzdeki {{ $horizon }} günün onay bekleyen {{ $totals['draft'] }} gönderisi onaylansın mı? Her biri kendi gününde yayınlanır.')) $wire.approveAll()"
                    class="flex-1 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50 sm:flex-none">Tümünü onayla ({{ $totals['draft'] }})</button>
            </div>
        @endif
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-posts', 'brandFilter' => $brand])

    @if ($message !== '')<p role="status" class="tone-info rounded-lg px-3 py-2 text-sm">{{ $message }}</p>@endif
    @unless ($canWrite)<p class="text-xs text-gray-500">Onay, düzenleme ve atlama yalnız Admin’dedir.</p>@endunless

    @if ($readBrand !== null)
        {{-- Okuma modu: one brand's drafts one under the other, approved together at the end. --}}
        <section class="space-y-3" data-reading>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="stopReading" class="link-neutral text-sm">Listeye dön</button>
                <h2 class="section-title break-words">{{ $readBrand->name }} · onay bekleyen {{ $readTotal }} gönderi</h2>
            </div>
            @forelse ($reading as $post)
                @include('livewire.operator.gbp.partials.post-card', $card + ['post' => $post, 'showLocation' => true])
            @empty
                <x-ta.empty-state size="sm" title="Bu markada onay bekleyen gönderi yok." />
            @endforelse
            <div class="flex flex-wrap items-center gap-3">
                @if ($readTotal > $reading->count())<button type="button" wire:click="readMore" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:ring-gray-700">Daha fazla göster ({{ $readTotal - $reading->count() }})</button>@endif
                @if ($canWrite && $readTotal > 0)
                    <button type="button" x-on:click="if (confirm('{{ $readBrand->name }} için onay bekleyen {{ $readTotal }} gönderi onaylansın mı?')) $wire.approveAll(null, {{ $readBrand->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-800 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700">Bunları onayla ({{ $readTotal }})</button>
                @endif
            </div>
        </section>
    @else
        <section class="grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-4">
            @foreach ($filters as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" @class(['-mx-2 min-w-0 rounded-lg px-2 py-1.5 text-left', 'bg-gray-100 dark:bg-white/[0.06]' => $filter === $key, 'hover:bg-gray-50 dark:hover:bg-white/[0.03]' => $filter !== $key]) @if ($filter === $key) aria-pressed="true" @endif>
                    <span class="block text-[28px] font-semibold leading-9 tabular-nums {{ $tone[$key] }}">{{ $totals[$key] }}</span>
                    <span class="block text-xs text-gray-500">{{ $label }}{{ $key === 'low' ? ' işletme' : '' }}</span>
                </button>
            @endforeach
        </section>
        @if ($filter !== '')<p class="text-xs text-gray-500">Süzülen: {{ $filters[$filter] }} · <button type="button" wire:click="setFilter('{{ $filter }}')" class="link-neutral">Süzmeyi kaldır</button></p>@endif
        <p class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
            @foreach (['draft' => 'Onay bekliyor', 'approved' => 'Onaylı', 'published' => 'Yayında', 'failed' => 'Yayınlanamadı', null => 'Boş'] as $key => $label)
                <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm {{ $square($key === '' ? null : $key) }}"></span>{{ $label }}</span>
            @endforeach
        </p>

        <section class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @forelse ($brands as $group)
                @php
                    $brandModel = $group['brand'];
                    $ids = $group['locations']->pluck('id');
                    $sum = fn (string $key): int => (int) $ids->sum(fn ($id): int => $info[$id][$key]);
                    $reasons = $ids->map(fn ($id) => $info[$id]['reason'])->filter()->unique();
                    $isOpen = $this->brand === (int) $brandModel?->id || $brands->count() === 1 || $filter !== '';
                @endphp
                <div wire:key="brand-{{ $brandModel?->id }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 py-3">
                        <button type="button" wire:click="openBrand({{ (int) $brandModel?->id }})" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <span class="text-gray-400">{{ $isOpen ? '▾' : '▸' }}</span>
                            <x-ta.brand-avatar :name="(string) $brandModel?->name" size="sm" />
                            <span class="min-w-0">
                                <span class="block break-words font-semibold text-gray-900 dark:text-white">{{ $brandModel?->name }}</span>
                                <span class="block text-xs text-gray-500">{{ $ids->count() }} işletme, {{ $sum('planned') }}/{{ $ids->count() * $horizon }} gün planlı @if ($reasons->count() === 1), {{ $reasons->first() }} @endif</span>
                            </span>
                        </button>
                        @if ($sum('drafts') > 0)<x-ta.pill tone="look">{{ $sum('drafts') }} onay bekliyor</x-ta.pill>@endif
                        @if ($sum('failed') > 0)<x-ta.pill tone="critical">{{ $sum('failed') }} yayınlanamadı</x-ta.pill>@endif
                        @if ($sum('drafts') > 0)
                            <button type="button" wire:click="startReading({{ (int) $brandModel?->id }})" class="link-neutral text-xs font-medium">Oku</button>
                            @if ($canWrite)<button type="button" x-on:click="if (confirm('{{ $brandModel?->name }} için {{ $sum('drafts') }} gönderi onaylansın mı?')) $wire.approveAll(null, {{ (int) $brandModel?->id }})" class="{{ $outline }}">Markayı onayla ({{ $sum('drafts') }})</button>@endif
                        @endif
                    </div>

                    @if ($isOpen)
                        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                            @foreach ($group['locations'] as $location)
                                @php
                                    $i = $info[$location->id];
                                    $state = $i['state'];
                                @endphp
                                <div wire:key="loc-{{ $location->id }}">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5 sm:pl-9">
                                        <button type="button" wire:click="open({{ $location->id }})" class="min-w-0 flex-1 text-left" title="{{ $location->name }}">
                                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ \App\Livewire\Operator\Gbp\PostPlanPage::shortName((string) $location->name) }}</span>
                                            <span class="block text-xs text-gray-500">
                                                {{ $i['planned'] }}/{{ $horizon }} gün
                                                @if ($i['drafts'] > 0), {{ $i['drafts'] }} onay bekliyor @endif
                                                @if ($i['reason']), <span class="text-warning-700 dark:text-warning-400">{{ $i['reason'] }}</span> @endif
                                            </span>
                                            @if ($state && ($state['status'] ?? '') !== 'ready')<span @class(['block text-xs', 'text-error-600' => ($state['status'] ?? '') === 'failed', 'text-gray-500' => ($state['status'] ?? '') !== 'failed']) @if (($state['status'] ?? '') === 'running') wire:poll.10s @endif>{{ $state['message'] ?? '' }}</span>@endif
                                        </button>
                                        @if ($canWrite)
                                            @if ($i['posted_today'])
                                                <x-ta.pill tone="ok">Bugün paylaşıldı</x-ta.pill>
                                            @else
                                                <button type="button" wire:click="startShare({{ $location->id }})" class="{{ $outline }}">Bugün paylaş</button>
                                            @endif
                                        @endif
                                        <div class="flex max-w-full flex-wrap gap-0.5" aria-label="30 günlük plan">
                                            @foreach ($days as $index => $d)
                                                @php $s = $i['strip'][$d] ?? null; @endphp
                                                <button type="button" wire:click="openDay({{ $location->id }}, '{{ $d }}')" title="{{ \Illuminate\Support\Carbon::parse($d)->locale('tr')->translatedFormat('d M D') }}: {{ $statusText[$s] ?? 'boş' }}"
                                                    @class(['h-4 w-2 rounded-sm sm:w-2.5', $square($s), 'ring-1 ring-gray-900 dark:ring-white' => $index === 0, 'outline outline-2 outline-gray-900 dark:outline-white' => $this->location === (int) $location->id && $this->day === $d])></button>
                                            @endforeach
                                        </div>
                                    </div>

                                    @if ($sharing === (int) $location->id)
                                        <div class="surface mb-3 p-3 text-sm sm:ml-9" data-testid="gbp-share-today">
                                            @if ($shareCandidate === null)
                                                <p class="text-gray-600 dark:text-gray-300">Havuzda bu işletme için hazır gönderi yok. “Boş günleri şimdi doldur” ile hazırlatın ya da profil ekranından elle gönderi yazın.</p>
                                                <button type="button" wire:click="startShare({{ $location->id }})" class="mt-2 text-xs text-gray-500 hover:underline">Kapat</button>
                                            @else
                                                @php $from = substr((string) $shareCandidate->publish_on, 0, 10); @endphp
                                                <p class="text-xs text-gray-500">Şimdi paylaşılacak: havuzdaki {{ $shareTotal }} gönderiden {{ ($shareSkip % max(1, $shareTotal)) + 1 }}.
                                                    {{ $from === $days[0] ? 'Bugünün gönderisi' : \Illuminate\Support\Carbon::parse($from)->locale('tr')->translatedFormat('d F').' gününden alınır; o gün yeniden planlanır' }},
                                                    {{ $shareCandidate->status === \App\Models\GbpQueuedPost::APPROVED ? 'onaylı' : 'taslak (bu tıklama onaydır)' }}</p>
                                                <p class="mt-1 whitespace-pre-line break-words text-gray-800 dark:text-gray-200">{{ $shareCandidate->summary }}</p>
                                                @if ($shareCandidate->page)<p class="mt-1 text-xs text-gray-500">Bağlantı: <a href="{{ $shareCandidate->url ?? $shareCandidate->page->url }}" target="_blank" rel="noopener" class="link-neutral break-words">{{ $shareCandidate->page->title }}</a></p>@endif
                                                <div class="mt-2 flex flex-wrap items-center gap-3">
                                                    <button type="button" wire:click="confirmShare({{ $shareCandidate->id }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:text-white dark:ring-gray-600">Şimdi paylaş</button>
                                                    @if ($shareTotal > 1)<button type="button" wire:click="nextShare" class="link-neutral text-sm">Başka gönderi</button>@endif
                                                    <button type="button" wire:click="startShare({{ $location->id }})" class="text-sm text-gray-500 hover:underline">Vazgeç</button>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($this->location === (int) $location->id)
                                        <div class="space-y-1 pb-3 sm:pl-9">
                                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                                <a href="{{ route('operator.gbp', ['assetId' => $location->id, 'tab' => 'posts']) }}" wire:navigate class="link-neutral">Profil ekranı</a>
                                                @if ($this->day !== null)<button type="button" wire:click="open({{ $location->id }})" class="link-neutral">Tüm günler</button>@endif
                                                @if ($canWrite && $i['drafts'] > 0)
                                                    <button type="button" wire:click="approveAll({{ $location->id }})" class="{{ $outline }}">Bu işletmenin taslaklarını onayla ({{ $i['drafts'] }})</button>
                                                @endif
                                            </div>
                                            @forelse ($rows as $post)
                                                @include('livewire.operator.gbp.partials.post-card', $card + ['post' => $post])
                                            @empty
                                                <p class="text-sm text-gray-500">{{ $this->day !== null ? 'Bu gün için gönderi yok.' : ($i['reason'] ?? 'Bu işletme için plan yok.') }} Boş günler her sabah kendiliğinden dolar.</p>
                                            @endforelse
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <x-ta.empty-state size="sm" :title="$filter !== '' ? 'Bu süzmeye uyan işletme yok.' : 'Operasyonel markaya bağlı İşletme Profili yok.'" class="m-4" />
            @endforelse
        </section>
    @endif
</div>
