@php
    $chip = fn (string $status): string => match ($status) {
        'draft' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'approved' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'published' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'failed' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    };
    $square = fn (?string $status): string => match ($status) {
        'draft' => 'bg-amber-400',
        'approved' => 'bg-sky-500',
        'published' => 'bg-emerald-500',
        'failed' => 'bg-rose-500',
        default => 'bg-gray-200 dark:bg-gray-700',
    };
    $statusText = ['draft' => 'onay bekliyor', 'approved' => 'onaylı', 'published' => 'yayında', 'failed' => 'yayınlanamadı'];
    $tone = ['draft' => 'text-amber-700 dark:text-amber-300', 'week' => 'text-sky-700 dark:text-sky-300', 'failed' => 'text-rose-700 dark:text-rose-300', 'low' => 'text-gray-700 dark:text-gray-200'];
    $card = ['chip' => $chip, 'angles' => $angles, 'canWrite' => $canWrite, 'editing' => $editing];
@endphp
<div class="space-y-5 dark:text-gray-200" data-gbp-post-plan>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme gönderileri</h1>
            <p class="mt-1 text-xs text-gray-500">Her İşletme Profili için önümüzdeki {{ $horizon }} gün, günde bir gönderi; markanın sitesindeki sayfalardan yazılır. Onaylananlar günü gelince saat 10:00 civarında kendiliğinden yayınlanır.</p>
        </div>
        @if ($canWrite)
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="fillNow" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700">Boş günleri şimdi doldur</button>
                <button type="button" @disabled($totals['draft'] === 0) x-on:click="if (confirm('Önümüzdeki {{ $horizon }} günün onay bekleyen {{ $totals['draft'] }} gönderisi onaylansın mı? Her biri kendi gününde yayınlanır.')) $wire.approveAll()"
                    class="rounded-lg bg-success-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-success-600 disabled:opacity-50">Tümünü onayla ({{ $totals['draft'] }})</button>
            </div>
        @endif
    </header>

    @if ($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif
    @unless ($canWrite)<p class="text-xs text-gray-500">Onay, düzenleme ve atlama yalnız Admin’dedir.</p>@endunless

    @if ($readBrand !== null)
        {{-- Okuma modu: one brand's drafts one under the other, approved together at the end. --}}
        <section class="space-y-3" data-reading>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="stopReading" class="text-sm text-brand-600 hover:underline">← Listeye dön</button>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $readBrand->name }} · onay bekleyen {{ $readTotal }} gönderi</h2>
            </div>
            @forelse ($reading as $post)
                @include('livewire.operator.gbp.partials.post-card', $card + ['post' => $post, 'showLocation' => true])
            @empty
                <p class="text-sm text-gray-500">Bu markada onay bekleyen gönderi yok.</p>
            @endforelse
            <div class="flex flex-wrap items-center gap-3">
                @if ($readTotal > $reading->count())<button type="button" wire:click="readMore" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Daha fazla göster ({{ $readTotal - $reading->count() }})</button>@endif
                @if ($canWrite && $readTotal > 0)
                    <button type="button" x-on:click="if (confirm('{{ $readBrand->name }} için onay bekleyen {{ $readTotal }} gönderi onaylansın mı?')) $wire.approveAll(null, {{ $readBrand->id }})" class="rounded-lg bg-success-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-success-600">Bunları onayla ({{ $readTotal }})</button>
                @endif
            </div>
        </section>
    @else
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($filters as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" @class(['rounded-xl bg-white p-3 text-left ring-1 ring-inset dark:bg-gray-800', 'ring-brand-500 ring-2' => $filter === $key, 'ring-gray-200 dark:ring-gray-700' => $filter !== $key])>
                    <span class="block text-2xl font-semibold {{ $tone[$key] }}">{{ $totals[$key] }}</span>
                    <span class="block text-xs text-gray-500">{{ $label }}{{ $key === 'low' ? ' işletme' : '' }}</span>
                </button>
            @endforeach
        </section>
        @if ($filter !== '')<p class="text-xs text-gray-500">Süzülen: {{ $filters[$filter] }} · <button type="button" wire:click="setFilter('{{ $filter }}')" class="text-brand-600 hover:underline">Süzmeyi kaldır</button></p>@endif
        <p class="flex flex-wrap items-center gap-3 text-[11px] text-gray-500">
            @foreach (['draft' => 'Onay bekliyor', 'approved' => 'Onaylı', 'published' => 'Yayında', 'failed' => 'Yayınlanamadı', null => 'Boş'] as $key => $label)
                <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm {{ $square($key === '' ? null : $key) }}"></span>{{ $label }}</span>
            @endforeach
        </p>

        <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
            @forelse ($brands as $group)
                @php
                    $brandModel = $group['brand'];
                    $ids = $group['locations']->pluck('id');
                    $sum = fn (string $key): int => (int) $ids->sum(fn ($id): int => $info[$id][$key]);
                    $reasons = $ids->map(fn ($id) => $info[$id]['reason'])->filter()->unique();
                    $isOpen = $this->brand === (int) $brandModel?->id || $brands->count() === 1 || $filter !== '';
                @endphp
                <div wire:key="brand-{{ $brandModel?->id }}">
                    <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                        <button type="button" wire:click="openBrand({{ (int) $brandModel?->id }})" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <span class="text-gray-400">{{ $isOpen ? '▾' : '▸' }}</span>
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-gray-900 dark:text-white">{{ $brandModel?->name }}</span>
                                <span class="block text-xs text-gray-500">{{ $ids->count() }} işletme · {{ $sum('planned') }}/{{ $ids->count() * $horizon }} gün planlı @if ($reasons->count() === 1) · {{ $reasons->first() }} @endif</span>
                            </span>
                        </button>
                        @if ($sum('drafts') > 0)<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chip('draft') }}">{{ $sum('drafts') }} onay bekliyor</span>@endif
                        @if ($sum('failed') > 0)<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chip('failed') }}">{{ $sum('failed') }} yayınlanamadı</span>@endif
                        @if ($sum('drafts') > 0)
                            <button type="button" wire:click="startReading({{ (int) $brandModel?->id }})" class="text-xs font-medium text-brand-600 hover:underline">Oku</button>
                            @if ($canWrite)<button type="button" x-on:click="if (confirm('{{ $brandModel?->name }} için {{ $sum('drafts') }} gönderi onaylansın mı?')) $wire.approveAll(null, {{ (int) $brandModel?->id }})" class="text-xs font-semibold text-success-600 hover:underline">Markayı onayla ({{ $sum('drafts') }})</button>@endif
                        @endif
                    </div>

                    @if ($isOpen)
                        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                            @foreach ($group['locations'] as $location)
                                @php
                                    $i = $info[$location->id];
                                    $state = $i['state'];
                                @endphp
                                <div wire:key="loc-{{ $location->id }}" class="bg-gray-50/40 dark:bg-white/[0.01]">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5 sm:pl-9">
                                        <button type="button" wire:click="open({{ $location->id }})" class="min-w-0 flex-1 text-left" title="{{ $location->name }}">
                                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ \App\Livewire\Operator\Gbp\PostPlanPage::shortName((string) $location->name) }}</span>
                                            <span class="block text-xs text-gray-500">
                                                {{ $i['planned'] }}/{{ $horizon }} gün
                                                @if ($i['drafts'] > 0) · {{ $i['drafts'] }} onay bekliyor @endif
                                                @if ($i['reason']) · <span class="text-rose-600 dark:text-rose-400">{{ $i['reason'] }}</span> @endif
                                            </span>
                                            @if ($state && ($state['status'] ?? '') !== 'ready')<span @class(['block text-xs', 'text-rose-600' => ($state['status'] ?? '') === 'failed', 'text-gray-500' => ($state['status'] ?? '') !== 'failed']) @if (($state['status'] ?? '') === 'running') wire:poll.10s @endif>{{ $state['message'] ?? '' }}</span>@endif
                                        </button>
                                        <div class="flex gap-0.5" aria-label="30 günlük plan">
                                            @foreach ($days as $index => $d)
                                                @php $s = $i['strip'][$d] ?? null; @endphp
                                                <button type="button" wire:click="openDay({{ $location->id }}, '{{ $d }}')" title="{{ \Illuminate\Support\Carbon::parse($d)->locale('tr')->translatedFormat('d M D') }}: {{ $statusText[$s] ?? 'boş' }}"
                                                    @class(['h-4 w-2 rounded-sm sm:w-2.5', $square($s), 'ring-1 ring-gray-900 dark:ring-white' => $index === 0, 'outline outline-2 outline-brand-500' => $this->location === (int) $location->id && $this->day === $d])></button>
                                            @endforeach
                                        </div>
                                    </div>

                                    @if ($this->location === (int) $location->id)
                                        <div class="space-y-2 px-4 pb-3 sm:pl-9">
                                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                                <a href="{{ route('operator.gbp', ['assetId' => $location->id, 'tab' => 'posts']) }}" wire:navigate class="text-brand-600 hover:underline">Profil ekranı</a>
                                                @if ($this->day !== null)<button type="button" wire:click="open({{ $location->id }})" class="text-brand-600 hover:underline">Tüm günler</button>@endif
                                                @if ($canWrite && $i['drafts'] > 0)
                                                    <button type="button" wire:click="approveAll({{ $location->id }})" class="font-semibold text-success-600 hover:underline">Bu işletmenin taslaklarını onayla ({{ $i['drafts'] }})</button>
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
                <p class="px-4 py-5 text-sm text-gray-500">{{ $filter !== '' ? 'Bu süzmeye uyan işletme yok.' : 'Operasyonel markaya bağlı İşletme Profili yok.' }}</p>
            @endforelse
        </section>
    @endif
</div>
