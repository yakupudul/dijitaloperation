@php
    $select = 'h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $chip = ['ok' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'warn' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'bad' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'];
    $attentionLabels = ['needs_attention' => 'Dikkat istiyor', 'clear' => 'Sorun yok'];
    $contextLabels = ['complete' => 'İş bağlamı yeterli', 'incomplete' => 'İş bağlamı eksik', 'not_started' => 'İş bağlamı boş'];
    $sortHead = fn (string $key, string $label) => '<button type="button" wire:click="sortBy(\''.$key.'\')" class="font-medium hover:text-gray-900 dark:hover:text-white">'.e($label).($sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '').'</button>';
@endphp
<div class="space-y-4" data-brands-index>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Markalar</h1>
            <p class="mt-1 text-sm text-gray-500">Tüm markalar. Noktalar kanalların veri durumunu, açık iş markanın bekleyen önerilerini gösterir.</p>
            @if ($allCount > 0)<p class="mt-1 text-xs text-gray-500" data-brands-summary>{{ $summaryLine }}</p>@endif
        </div>
        <a href="{{ route('operator.brand.create') }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Marka ekle</a>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Marka, müşteri veya alan adı ara" aria-label="Ara"
            class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
        <select wire:model.live="attention" class="{{ $select }}" aria-label="Dikkat">
            <option value="">Dikkat: hepsi</option>
            @foreach ($attentionLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="customer" class="{{ $select }}" aria-label="Müşteri">
            <option value="">Müşteri: hepsi</option>
            @foreach ($customerOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="sector" class="{{ $select }}" aria-label="Sektör">
            <option value="">Sektör: hepsi</option>
            @foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="asset_type" class="{{ $select }}" aria-label="Varlık türü">
            <option value="">Varlık: hepsi</option>
            @foreach ($assetTypeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="responsible" class="{{ $select }}" aria-label="Sorumlu">
            <option value="">Sorumlu: herkes</option>
            @foreach ($teamOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="primary_market" class="{{ $select }}" aria-label="Ana pazar">
            <option value="">Pazar: hepsi</option>
            @foreach ($countryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="context" class="{{ $select }}" aria-label="İş bağlamı">
            <option value="">İş bağlamı: hepsi</option>
            @foreach ($contextLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <label class="ml-auto inline-flex items-center gap-2 text-xs text-gray-500">
            <input type="checkbox" wire:model.live="showOptionalColumns" class="rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
            Ek sütunlar
        </label>
    </div>

    @if ($hasFilters)
        <div class="flex flex-wrap items-center gap-2 text-xs" data-active-filters>
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
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ $label }}</span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="font-medium text-brand-600 hover:underline">Temizle</button>
        </div>
    @endif

    @if ($allCount === 0)
        <section class="rounded-xl bg-white p-6 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Henüz marka yok</h2>
            <p class="mt-1 text-sm text-gray-500">Bir müşterinin altında ilk markayı açın; web sitesini girerseniz hesaplar ve hizmetler önerilir.</p>
            <a href="{{ route('operator.brand.create') }}" wire:navigate class="mt-3 inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Marka ekle</a>
        </section>
    @elseif ($brands->total() === 0)
        <section class="rounded-xl bg-white p-6 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Filtreye uyan marka yok</h2>
            <button type="button" wire:click="clearFilters" class="mt-2 text-sm font-medium text-brand-600 hover:underline">Filtreleri temizle</button>
        </section>
    @else
        @if ($isAdmin && count($selected) > 0)
            <div class="flex flex-wrap items-center gap-3 rounded-xl bg-rose-50 px-4 py-2.5 text-sm ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:ring-rose-500/20">
                <span class="font-medium text-rose-800 dark:text-rose-200">{{ count($selected) }} marka seçildi</span>
                <button type="button" wire:click="deleteSelected" wire:confirm="Seçili {{ count($selected) }} marka, dijital varlıklarıyla birlikte listeden silinecek; müşteri kalır. Toplanan veriler silinmez; yalnızca veri çekimi durur. Hesap tekrar bir markaya bağlanırsa çekim kaldığı yerden devam eder. Devam edilsin mi?" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Seçilenleri sil</button>
                <button type="button" wire:click="$set('selected', [])" class="text-xs text-rose-700 hover:underline dark:text-rose-300">Seçimi temizle</button>
            </div>
        @endif

        <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-100 text-xs text-gray-500 dark:border-gray-800">
                    <tr>
                        @if ($isAdmin)<th class="w-8 px-3 py-3"><input type="checkbox" aria-label="Tümünü seç" wire:click="toggleAll({{ json_encode($visibleIds) }})" @checked(count($selected) > 0 && count(array_intersect($selected, $visibleIds)) === count($visibleIds)) class="rounded border-gray-300"></th>@endif
                        <th class="px-4 py-3">{!! $sortHead('name', 'Marka') !!}</th>
                        <th class="hidden px-4 py-3 md:table-cell">{!! $sortHead('customer', 'Müşteri') !!}</th>
                        <th class="hidden px-4 py-3 sm:table-cell">Kanallar</th>
                        <th class="px-4 py-3 text-right">{!! $sortHead('work', 'Açık iş') !!}</th>
                        <th class="px-4 py-3">{!! $sortHead('attention', 'Dikkat') !!}</th>
                        @if ($showOptionalColumns)
                            <th class="hidden px-4 py-3 lg:table-cell">{!! $sortHead('sector', 'Sektör') !!}</th>
                            <th class="hidden px-4 py-3 lg:table-cell">Ana pazar</th>
                            <th class="hidden px-4 py-3 text-right lg:table-cell">{!! $sortHead('assets', 'Varlık') !!}</th>
                            <th class="hidden px-4 py-3 text-right lg:table-cell">İş bağlamı</th>
                        @endif
                        <th class="hidden px-4 py-3 xl:table-cell">Ekip</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($brands as $brand)
                        @php($href = route('operator.brand', ['brand' => $brand['id']]))
                        <tr wire:key="brand-{{ $brand['id'] }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-brand-row="{{ $brand['id'] }}">
                            @if ($isAdmin)<td class="px-3 py-3"><input type="checkbox" aria-label="Seç" value="{{ (int) $brand['id'] }}" wire:model.live="selected" class="rounded border-gray-300"></td>@endif
                            <td class="px-4 py-3">
                                <a href="{{ $href }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $brand['name'] }}</a>
                                @if (! $brand['customer_active'])<span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">Pasif</span>@endif
                                <p class="text-xs text-gray-500">
                                    {{ $brand['website'] ?? 'Web sitesi yok' }}
                                    <span class="md:hidden"> · {{ $brand['customer_name'] }}</span>
                                </p>
                                <div class="mt-1 sm:hidden">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</div>
                            </td>
                            <td class="hidden px-4 py-3 md:table-cell">
                                <a href="{{ route('operator.customer', ['customerId' => $brand['customer_id']]) }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-300">{{ $brand['customer_name'] }}</a>
                                @if ($brand['sector_label'] !== '—')<p class="text-xs text-gray-400">{{ $brand['sector_label'] }}</p>@endif
                            </td>
                            <td class="hidden px-4 py-3 sm:table-cell">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</td>
                            <td class="px-4 py-3 text-right tabular-nums" data-open-work>
                                {{ $brand['open_work'] }}
                                @if ($brand['open_by_channel'] !== [])
                                    <p class="text-xs text-gray-500">{{ collect($brand['open_by_channel'])->map(fn ($n, $label) => $label.' '.$n)->implode(' · ') }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3" data-attention>
                                @if ($brand['needs_attention'])
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $brand['reconnect'] > 0 ? $chip['bad'] : $chip['warn'] }}">Dikkat</span>
                                    <p class="mt-0.5 max-w-xs text-xs text-gray-500">{{ $brand['attention_reason'] }}</p>
                                @elseif ($brand['assets_count'] > 0)
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $chip['ok'] }}">Sorun yok</span>
                                @else
                                    <span class="text-xs text-gray-400">Varlık yok</span>
                                @endif
                            </td>
                            @if ($showOptionalColumns)
                                <td class="hidden px-4 py-3 text-gray-700 lg:table-cell dark:text-gray-300">{{ $brand['sector_label'] }}</td>
                                <td class="hidden px-4 py-3 text-gray-700 lg:table-cell dark:text-gray-300">
                                    {{ $brand['primary_market_label'] !== '' ? $brand['primary_market_label'] : '—' }}
                                    @if (($brand['extra_markets'] ?? 0) > 0)<span class="text-xs text-gray-400">+{{ $brand['extra_markets'] }}</span>@endif
                                </td>
                                <td class="hidden px-4 py-3 text-right tabular-nums lg:table-cell">{{ $brand['assets_count'] }}<p class="text-xs text-gray-500">{{ $brand['connected_assets'] }} bağlı</p></td>
                                <td class="hidden px-4 py-3 text-right tabular-nums lg:table-cell">{{ $brand['context_completed'] }}/{{ $brand['context_total'] }}</td>
                            @endif
                            <td class="hidden px-4 py-3 xl:table-cell">
                                <div class="flex -space-x-1.5" title="{{ collect($brand['responsible'] ?? [])->pluck('name')->implode(', ') }}">
                                    @forelse (collect($brand['responsible'] ?? [])->take(3) as $user)
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-gray-100 text-[10px] font-semibold text-gray-700 dark:border-gray-900 dark:bg-gray-800 dark:text-gray-200">{{ $user['initials'] }}</span>
                                    @empty
                                        <span class="text-xs text-gray-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @if ($brands->hasPages())<div>{{ $brands->links() }}</div>@endif
    @endif
</div>
