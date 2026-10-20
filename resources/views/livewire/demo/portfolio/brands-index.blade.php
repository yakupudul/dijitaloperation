@php
    $select = 'h-9 rounded-lg border border-gray-200 bg-white px-2.5 text-sm text-gray-700 shadow-card focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200';
    $attentionLabels = ['needs_attention' => 'Dikkat istiyor', 'clear' => 'Sorun yok'];
    $contextLabels = ['complete' => 'İş bağlamı yeterli', 'incomplete' => 'İş bağlamı eksik', 'not_started' => 'İş bağlamı boş'];
    $sortHead = fn (string $key, string $label) => '<button type="button" wire:click="sortBy(\''.$key.'\')" class="font-medium hover:text-gray-900 dark:hover:text-white">'.e($label).($sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '').'</button>';
@endphp
<div class="space-y-6" data-brands-index>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title" title="Tüm markalar ve müşterileri. Noktalar kanalların veri durumunu, açık iş markanın bekleyen önerilerini gösterir.">Markalar</h1>
            @if ($allCount > 0)<p class="page-sub" data-brands-summary>{{ $summaryLine }}</p>@endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- Müşteriler merged into Markalar (2026-10-19): the customer list and its actions stay one click away. --}}
            <a href="{{ route('operator.customers') }}" wire:navigate class="btn-quiet" data-customers-link>Müşteri listesi</a>
            <x-ta.button variant="outline" size="sm" :href="route('operator.customer.create')" wire:navigate data-add-customer>Müşteri ekle</x-ta.button>
            <x-ta.button size="sm" :href="route('operator.brand.create')" wire:navigate>Marka ekle</x-ta.button>
        </div>
    </div>

    @php
        $moreFilters = collect([$sector, $asset_type, $responsible, $primary_market, $context])->filter(fn ($v) => $v !== '')->count();
    @endphp
    <div class="flex flex-wrap items-center gap-2" x-data="{ more: false }" data-filter-bar>
        <label class="relative w-full min-w-0 sm:w-64">
            <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Marka, müşteri veya alan adı ara" aria-label="Ara"
                class="h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white pr-3 pl-9 text-sm shadow-card placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-white/[0.03] dark:text-white" />
        </label>
        <select wire:model.live="attention" class="{{ $select }} min-w-0 flex-1 sm:flex-none" aria-label="Dikkat">
            <option value="">Dikkat: hepsi</option>
            @foreach ($attentionLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="customer" class="{{ $select }} min-w-0 flex-1 sm:max-w-56 sm:flex-none" aria-label="Müşteri">
            <option value="">Müşteri: hepsi</option>
            @foreach ($customerOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <button type="button" @click="more = ! more" :aria-expanded="more.toString()" class="btn h-9! text-sm! font-normal! text-gray-700! dark:text-gray-200!" data-more-filters>
            Filtreler
            @if ($moreFilters > 0)<span class="text-xs font-medium text-gray-900 tabular-nums dark:text-white">{{ $moreFilters }}</span>@endif
            <svg class="h-3.5 w-3.5 transition" :class="more && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </button>
        <div x-show="more" x-cloak class="card grid w-full grid-cols-1 gap-2 p-3 sm:grid-cols-2 lg:grid-cols-3" data-more-filters-panel>
            <select wire:model.live="sector" class="{{ $select }} w-full min-w-0" aria-label="Sektör">
                <option value="">Sektör: hepsi</option>
                @foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="asset_type" class="{{ $select }} w-full min-w-0" aria-label="Varlık türü">
                <option value="">Varlık: hepsi</option>
                @foreach ($assetTypeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="responsible" class="{{ $select }} w-full min-w-0" aria-label="Sorumlu">
                <option value="">Sorumlu: herkes</option>
                @foreach ($teamOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="primary_market" class="{{ $select }} w-full min-w-0" aria-label="Ana pazar">
                <option value="">Pazar: hepsi</option>
                @foreach ($countryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="context" class="{{ $select }} w-full min-w-0" aria-label="İş bağlamı">
                <option value="">İş bağlamı: hepsi</option>
                @foreach ($contextLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <label class="inline-flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">
                <input type="checkbox" wire:model.live="showOptionalColumns" class="rounded border-gray-300" />
                Ek sütunlar
            </label>
        </div>
    </div>

    @if ($hasFilters)
        <div class="-mt-2 flex flex-wrap items-center gap-2 text-[13px]" data-active-filters>
            <span class="text-gray-500">Filtre:</span>
            @foreach (array_filter([
                $search !== '' ? 'Arama: '.$search : null,
                $attention !== '' ? ($attentionLabels[$attention] ?? $attention) : null,
                $customer !== '' ? ($customerOptions[$customer] ?? $customer) : null,
                $sector !== '' ? ($sectorOptions[$sector] ?? $sector) : null,
                $asset_type !== '' ? ($assetTypeOptions[$asset_type] ?? $asset_type) : null,
                $responsible !== '' ? ($teamOptions[$responsible] ?? $responsible) : null,
                $primary_market !== '' ? ($countryOptions[$primary_market] ?? $primary_market) : null,
                $context !== '' ? ($contextLabels[$context] ?? $context) : null,
            ]) as $label)
                <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-gray-700 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">{{ $label }}</span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="btn-quiet h-7! px-2!">Temizle</button>
        </div>
    @endif

    @if ($allCount === 0)
        <div class="card">
            <x-ta.empty-state title="Henüz marka yok" message="Bir müşterinin altında ilk markayı açın; web sitesini girerseniz hesaplar ve hizmetler önerilir.">
                <x-ta.button size="sm" :href="route('operator.brand.create')" wire:navigate>Marka ekle</x-ta.button>
            </x-ta.empty-state>
        </div>
    @elseif ($brands->total() === 0)
        <div class="card">
            <x-ta.empty-state size="sm" title="Filtreye uyan marka yok">
                <button type="button" wire:click="clearFilters" class="btn">Filtreleri temizle</button>
            </x-ta.empty-state>
        </div>
    @else
        <section class="card relative overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        @if ($isAdmin)<th class="w-8 pr-0!"><input type="checkbox" aria-label="Tümünü seç" wire:click="toggleAll({{ json_encode($visibleIds) }})" @checked(count($selected) > 0 && count(array_intersect($selected, $visibleIds)) === count($visibleIds)) class="rounded border-gray-300"></th>@endif
                        <th>{!! $sortHead('name', 'Marka') !!}</th>
                        <th class="hidden md:table-cell">{!! $sortHead('customer', 'Müşteri') !!}</th>
                        <th class="hidden sm:table-cell">Kanallar</th>
                        <th class="text-right!">{!! $sortHead('work', 'Açık iş') !!}</th>
                        <th class="hidden sm:table-cell">{!! $sortHead('attention', 'Dikkat') !!}</th>
                        @if ($showOptionalColumns)
                            <th class="hidden lg:table-cell">{!! $sortHead('sector', 'Sektör') !!}</th>
                            <th class="hidden lg:table-cell">Ana pazar</th>
                            <th class="hidden text-right! lg:table-cell">{!! $sortHead('assets', 'Varlık') !!}</th>
                            <th class="hidden text-right! lg:table-cell">İş bağlamı</th>
                        @endif
                        <th class="hidden xl:table-cell">Ekip</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($brands as $brand)
                        @php
                            $href = route('operator.brand', ['brand' => $brand['id']]);
                            $customerHref = route('operator.customer', ['customerId' => $brand['customer_id']]);
                            $sameName = mb_strtolower(trim((string) $brand['customer_name'])) === mb_strtolower(trim((string) $brand['name']));
                            // Only real exceptions get a badge: an account to reconnect, a critical suggestion or a source that
                            // stopped. A late source alone is a quiet line (it was on every row before 2026-10-19).
                            $hasBadSource = collect($brand['channels'] ?? [])->contains(fn (array $c): bool => $c['tone'] === 'bad');
                            $isException = $brand['needs_attention'] && ($brand['reconnect'] > 0 || $brand['critical'] > 0 || $hasBadSource);
                            $byChannel = collect($brand['open_by_channel'])->map(fn ($n, $label) => $label.' '.$n);
                            $attentionVars = ['needsAttention' => $brand['needs_attention'], 'isException' => $isException, 'critical' => $brand['reconnect'] > 0, 'reason' => $brand['attention_reason']];
                        @endphp
                        <tr wire:key="brand-{{ $brand['id'] }}" class="max-sm:[&>td]:align-top" data-brand-row="{{ $brand['id'] }}">
                            @if ($isAdmin)<td class="pr-0!"><input type="checkbox" aria-label="Seç" value="{{ (int) $brand['id'] }}" wire:model.live="selected" class="rounded border-gray-300"></td>@endif
                            <td>
                                <div class="flex min-w-0 items-start gap-2.5 sm:items-center">
                                    <x-ta.brand-avatar :name="$brand['name']" :logo="$brand['logo_url'] ?? null" />
                                    <div class="min-w-0">
                                        <a href="{{ $href }}" wire:navigate class="break-words font-medium text-gray-900 hover:underline dark:text-white">{{ $brand['name'] }}</a>
                                        <p class="break-all text-[13px] text-gray-500">{{ $brand['website'] ?? 'Web sitesi yok' }}</p>
                                        <p class="text-[13px] text-gray-500 md:hidden">
                                            <a href="{{ $customerHref }}" wire:navigate class="link-neutral font-normal">{{ $sameName ? 'Müşteri kartı' : $brand['customer_name'] }}</a>
                                            @if (! $brand['customer_active'])<x-ta.pill tone="info" class="ml-1">Pasif</x-ta.pill>@endif
                                        </p>
                                        <div class="mt-1 sm:hidden">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</div>
                                        <div class="mt-1 sm:hidden" data-attention-mobile>@include('livewire.demo.portfolio.partials.attention', $attentionVars)</div>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden md:table-cell">
                                @if ($sameName)
                                    <a href="{{ $customerHref }}" wire:navigate class="link-neutral text-[13px] font-normal" title="{{ $brand['customer_name'] }}">Müşteri kartı</a>
                                @else
                                    <a href="{{ $customerHref }}" wire:navigate class="break-words text-gray-700 hover:underline dark:text-gray-300">{{ $brand['customer_name'] }}</a>
                                @endif
                                @if (! $brand['customer_active'])<x-ta.pill tone="info" class="ml-1">Pasif</x-ta.pill>@endif
                                @if ($brand['sector_label'] !== '—')<p class="text-[13px] text-gray-500">{{ $brand['sector_label'] }}</p>@endif
                            </td>
                            <td class="hidden sm:table-cell">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</td>
                            <td class="text-right tabular-nums" data-open-work @if ($byChannel->isNotEmpty()) title="{{ $byChannel->implode(', ') }}" @endif>
                                <span class="font-medium text-gray-900 dark:text-white">{{ $brand['open_work'] }}</span>
                                @if ($byChannel->isNotEmpty())
                                    <p class="mt-0.5 hidden flex-wrap justify-end gap-x-1.5 text-xs leading-4 text-gray-500 md:flex">
                                        @foreach ($byChannel as $part)<span class="whitespace-nowrap">{{ $part }}</span>@endforeach
                                    </p>
                                @endif
                            </td>
                            <td class="hidden sm:table-cell" data-attention>@include('livewire.demo.portfolio.partials.attention', $attentionVars)</td>
                            @if ($showOptionalColumns)
                                <td class="hidden text-gray-700 lg:table-cell dark:text-gray-300">{{ $brand['sector_label'] }}</td>
                                <td class="hidden text-gray-700 lg:table-cell dark:text-gray-300">
                                    {{ $brand['primary_market_label'] !== '' ? $brand['primary_market_label'] : '—' }}
                                    @if (($brand['extra_markets'] ?? 0) > 0)<span class="text-xs text-gray-500">+{{ $brand['extra_markets'] }}</span>@endif
                                </td>
                                <td class="hidden text-right tabular-nums lg:table-cell">{{ $brand['assets_count'] }}<p class="text-xs text-gray-500">{{ $brand['connected_assets'] }} bağlı</p></td>
                                <td class="hidden text-right tabular-nums lg:table-cell">{{ $brand['context_completed'] }}/{{ $brand['context_total'] }}</td>
                            @endif
                            <td class="hidden xl:table-cell">
                                <div class="flex -space-x-1.5" title="{{ collect($brand['responsible'] ?? [])->pluck('name')->implode(', ') }}">
                                    @foreach (collect($brand['responsible'] ?? [])->take(3) as $user)
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-gray-100 text-[11px] font-medium text-gray-700 dark:border-gray-900 dark:bg-gray-800 dark:text-gray-200">{{ $user['initials'] }}</span>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @if ($brands->hasPages())<div>{{ $brands->links() }}</div>@endif

        @if ($isAdmin && count($selected) > 0)
            <div class="sticky bottom-3 z-30 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-theme-lg ring-1 ring-white/10 dark:bg-gray-800" data-brands-selection>
                <span class="font-medium">{{ count($selected) }} marka seçildi</span>
                <button type="button" wire:click="deleteSelected" wire:confirm="Seçili {{ count($selected) }} marka, dijital varlıklarıyla birlikte listeden silinecek; müşteri kalır. Toplanan veriler silinmez; yalnızca veri çekimi durur. Hesap tekrar bir markaya bağlanırsa çekim kaldığı yerden devam eder. Devam edilsin mi?" class="inline-flex h-8 w-full items-center justify-center rounded-lg border border-white/20 px-3 text-[13px] font-medium text-error-300 hover:bg-white/10 sm:w-auto">Seçilenleri sil</button>
                <button type="button" wire:click="$set('selected', [])" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
            </div>
        @endif
    @endif
</div>
