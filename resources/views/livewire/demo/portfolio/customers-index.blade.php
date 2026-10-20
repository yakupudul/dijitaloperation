@php
    $select = 'h-9 rounded-lg border border-gray-200 bg-white px-2.5 text-sm text-gray-700 shadow-card focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200';
    $chip = ['ok' => 'text-gray-900 dark:text-white', 'warn' => 'text-warning-800 dark:text-warning-400', 'bad' => 'text-error-700 dark:text-error-400'];
    $band = ['good' => 'ok', 'watch' => 'warn', 'risk' => 'bad'];
    $attentionLabels = ['needs_attention' => 'Dikkat istiyor', 'clear' => 'Sorun yok'];
    $sortHead = fn (string $key, string $label) => '<button type="button" wire:click="sortBy(\''.$key.'\')" class="font-medium hover:text-gray-900 dark:hover:text-white">'.e($label).($sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '').'</button>';
@endphp
<div class="space-y-7" data-customers-index>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Müşteriler</h1>
            @if ($allCount > 0)
                <p class="page-sub" data-customers-summary>{{ $allCount }} müşteri · {{ $attentionCount }} müşteri dikkat istiyor</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($isAdmin)
                <a href="{{ route('operator.integrations.discovered') }}" wire:navigate class="btn-quiet">Keşfedilen varlıklar</a>
            @endif
            <x-ta.button size="sm" :href="route('operator.customer.create')" wire:navigate>Müşteri ekle</x-ta.button>
        </div>
    </div>

    @php
        $moreFilters = collect([$industry, $responsible, $type, $hq_country, $service])->filter(fn ($v) => $v !== '')->count();
    @endphp
    @php
        $attentionSegs = ['' => ['Tümü', $allCount], 'needs_attention' => ['Dikkat istiyor', $attentionCount], 'clear' => ['Sorun yok', $allCount - $attentionCount]];
    @endphp
    <div class="flex flex-wrap items-center gap-2" x-data="{ more: false }" data-filter-bar>
        {{-- Canlı panel (2026-10-20): the attention filter as pill tabs; the select stays under Filtreler. --}}
        <nav class="no-scrollbar -mx-4 flex w-[calc(100%+2rem)] gap-1.5 overflow-x-auto px-4 lg:mx-0 lg:mr-2 lg:w-auto lg:px-0" aria-label="Dikkat" data-attention-segs>
            @foreach ($attentionSegs as $value => [$label, $count])
                <button type="button" wire:click="$set('attention', '{{ $value }}')" aria-pressed="{{ $attention === $value ? 'true' : 'false' }}" @class(['seg', 'seg-active' => $attention === $value])>
                    {{ $label }} <span @class(['tabular-nums text-xs', 'text-accent' => $attention === $value, 'text-gray-400 dark:text-gray-500' => $attention !== $value])>{{ $count }}</span>
                </button>
            @endforeach
        </nav>
        <label class="relative w-full min-w-0 sm:w-64">
            <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Müşteri adı, unvan veya e-posta ara" aria-label="Ara"
                class="h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white pr-3 pl-9 text-sm shadow-card placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-white/[0.03] dark:text-white" />
        </label>
        <select wire:model.live="status" class="{{ $select }} min-w-0 flex-1 sm:flex-none" aria-label="Durum">
            <option value="">Durum: hepsi</option>
            @foreach ($statusOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <button type="button" @click="more = ! more" :aria-expanded="more.toString()" class="btn h-9! text-sm! font-normal! text-gray-700! dark:text-gray-200!" data-more-filters>
            Filtreler
            @if ($moreFilters > 0)<span class="text-xs font-medium text-gray-900 tabular-nums dark:text-white">{{ $moreFilters }}</span>@endif
            <svg class="h-3.5 w-3.5 transition" :class="more && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </button>
        <div x-show="more" x-cloak class="card grid w-full grid-cols-1 gap-2 p-3 sm:grid-cols-2 lg:grid-cols-3" data-more-filters-panel>
            <select wire:model.live="attention" class="{{ $select }} w-full min-w-0" aria-label="Dikkat">
                <option value="">Dikkat: hepsi</option>
                @foreach ($attentionLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="industry" class="{{ $select }} w-full min-w-0" aria-label="Sektör">
                <option value="">Sektör: hepsi</option>
                @foreach ($industryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="responsible" class="{{ $select }} w-full min-w-0" aria-label="Sorumlu">
                <option value="">Sorumlu: herkes</option>
                @foreach ($teamOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="type" class="{{ $select }} w-full min-w-0" aria-label="Tür">
                <option value="">Tür: hepsi</option>
                @foreach ($typeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="hq_country" class="{{ $select }} w-full min-w-0" aria-label="Merkez ülke">
                <option value="">Ülke: hepsi</option>
                @foreach ($countryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="service" class="{{ $select }} w-full min-w-0" aria-label="Hizmet">
                <option value="">Hizmet: hepsi</option>
                @foreach ($serviceOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
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
                $status !== '' ? ($statusOptions[$status] ?? $status) : null,
                $industry !== '' ? ($industryOptions[$industry] ?? $industry) : null,
                $responsible !== '' ? ($teamOptions[$responsible] ?? $responsible) : null,
                $type !== '' ? ($typeOptions[$type] ?? $type) : null,
                $hq_country !== '' ? ($countryOptions[$hq_country] ?? $hq_country) : null,
                $service !== '' ? ($serviceOptions[$service] ?? $service) : null,
            ]) as $label)
                <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-gray-700 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">{{ $label }}</span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="btn-quiet h-7! px-2!">Temizle</button>
        </div>
    @endif

    @if ($allCount === 0)
        <div class="card">
            <x-ta.empty-state title="Henüz müşteri yok" message="İlk müşteriyi ekleyin; markaları ve hesapları müşterinin altında açılır.">
                <x-ta.button size="sm" :href="route('operator.customer.create')" wire:navigate>Müşteri ekle</x-ta.button>
            </x-ta.empty-state>
        </div>
    @elseif ($customers->total() === 0)
        <div class="card">
            <x-ta.empty-state size="sm" title="Filtreye uyan müşteri yok">
                <button type="button" wire:click="clearFilters" class="btn">Filtreleri temizle</button>
            </x-ta.empty-state>
        </div>
    @else
        <section class="card relative overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        @if ($isAdmin)<th class="w-8 pr-0!"><input type="checkbox" aria-label="Tümünü seç" wire:click="toggleAll({{ json_encode($visibleIds) }})" @checked(count($selected) > 0 && count(array_intersect($selected, $visibleIds)) === count($visibleIds)) class="rounded border-gray-300"></th>@endif
                        <th>{!! $sortHead('name', 'Müşteri') !!}</th>
                        <th class="hidden md:table-cell">{!! $sortHead('industry', 'Sektör') !!}</th>
                        <th class="hidden text-right! sm:table-cell">{!! $sortHead('brands', 'Marka') !!}</th>
                        <th class="text-right!">{!! $sortHead('work', 'Açık iş') !!}</th>
                        <th class="hidden text-right! sm:table-cell">Veri sorunu</th>
                        <th class="hidden sm:table-cell">{!! $sortHead('attention', 'Dikkat') !!}</th>
                        @if ($showOptionalColumns)
                            <th class="hidden lg:table-cell">Sorumlu</th>
                            <th class="hidden lg:table-cell">{!! $sortHead('service_started', 'Hizmet başlangıcı') !!}</th>
                            <th class="hidden text-right! lg:table-cell">Varlık</th>
                        @endif
                        <th>Durum</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        @php
                            $score = $health[$customer['id']] ?? null;
                            $canToggle = $isAdmin || in_array($actorId, $customer['responsible_user_ids'] ?? [], true);
                            $isActive = ($customer['status'] ?? '') === 'active';
                        @endphp
                        <tr wire:key="customer-{{ $customer['id'] }}" data-customer-row="{{ $customer['id'] }}">
                            @if ($isAdmin)<td class="pr-0!"><input type="checkbox" aria-label="Seç" value="{{ (int) $customer['id'] }}" wire:model.live="selected" class="rounded border-gray-300"></td>@endif
                            <td>
                                <a href="{{ route('operator.customer', ['customerId' => $customer['id']]) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $customer['name'] }}</a>
                                <p class="text-[13px] text-gray-500">{{ collect([$customer['legal_name'] ?? null, $customer['type_label'] ?? null])->filter()->implode(', ') }}</p>
                                @if ($customer['needs_attention'])
                                    <div class="mt-1 sm:hidden" data-attention-mobile>@include('livewire.demo.portfolio.partials.attention', ['needsAttention' => true, 'isException' => $customer['reconnect'] > 0 || $customer['critical'] > 0, 'critical' => $customer['reconnect'] > 0, 'reason' => $customer['attention_reason']])</div>
                                @endif
                                @if ($score !== null)
                                    <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-500" data-health-score>
                                        <span class="font-semibold tabular-nums {{ $chip[$band[$score['band']] ?? 'warn'] }}">{{ $score['score'] }}</span>
                                        <span>{{ $score['reasons'][0] ?? 'Puanı düşüren bir şey yok' }}</span>
                                    </p>
                                @endif
                            </td>
                            <td class="hidden text-gray-700 md:table-cell dark:text-gray-300">
                                {{ $customer['sector_label'] }}
                                @if ($customer['sector_from_brands'])<p class="text-xs text-gray-500">markalardan</p>@endif
                            </td>
                            <td class="hidden text-right tabular-nums sm:table-cell">{{ $customer['brands_count'] }}</td>
                            <td class="text-right tabular-nums" data-open-work>
                                {{ $customer['open_work'] }}
                                @if ($customer['critical'] > 0)<p class="whitespace-nowrap text-xs text-error-600">{{ $customer['critical'] }} kritik</p>@endif
                            </td>
                            <td class="hidden text-right tabular-nums sm:table-cell" data-data-issues>@if ($customer['data_issues'] > 0)<span class="text-warning-700 dark:text-warning-300">{{ $customer['data_issues'] }}</span>@else<span class="text-gray-500">0</span>@endif</td>
                            <td class="hidden sm:table-cell" data-attention>
                                @include('livewire.demo.portfolio.partials.attention', ['needsAttention' => $customer['needs_attention'], 'isException' => $customer['reconnect'] > 0 || $customer['critical'] > 0, 'critical' => $customer['reconnect'] > 0, 'reason' => $customer['attention_reason']])
                                @if (! $customer['needs_attention'] && $customer['brands_count'] === 0)<span class="text-xs text-gray-500">Marka yok</span>@endif
                            </td>
                            @if ($showOptionalColumns)
                                <td class="hidden text-gray-700 lg:table-cell dark:text-gray-300">{{ implode(', ', $customer['responsible_labels'] ?? []) ?: '—' }}</td>
                                <td class="hidden tabular-nums text-gray-500 lg:table-cell">{{ $customer['service_started_at'] ?? '—' }}</td>
                                <td class="hidden text-right tabular-nums lg:table-cell">{{ $customer['digital_assets_count'] ?? 0 }}</td>
                            @endif
                            <td>
                                {{-- Status (2026-10-19): only the exception (Pasif / Arşiv) shows; an active customer's switch sits in the row menu. --}}
                                @if (($customer['status'] ?? '') === 'archived')
                                    <x-ta.pill tone="info">{{ $customer['status_label'] }}</x-ta.pill>
                                @elseif ($canToggle && ! $isActive)
                                    <button type="button" role="switch" aria-checked="false"
                                        wire:click="toggleActive('{{ $customer['id'] }}')" wire:loading.attr="disabled"
                                        title="{{ __('customer_status.hint_passive') }}"
                                        class="inline-flex items-center gap-2 text-sm" data-toggle-active>
                                        <span class="relative inline-flex h-5 w-9 shrink-0 rounded-full bg-gray-300 transition dark:bg-gray-700"><span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition"></span></span>
                                        <span class="text-gray-500">Pasif</span>
                                    </button>
                                @elseif ($canToggle)
                                    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                                        <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-white/5 dark:hover:text-white" aria-label="Müşteri işlemleri" title="{{ __('customer_status.active') }}">
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 6a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak class="card absolute right-0 z-20 mt-1 w-56 p-2 shadow-theme-lg">
                                            <button type="button" role="switch" aria-checked="true"
                                                wire:click="toggleActive('{{ $customer['id'] }}')" wire:loading.attr="disabled"
                                                wire:confirm="{{ __('customer_status.confirm_pause', ['name' => $customer['name']]) }}"
                                                title="{{ __('customer_status.hint_active') }}"
                                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5" data-toggle-active>
                                                <span class="relative inline-flex h-5 w-9 shrink-0 rounded-full bg-gray-900 transition dark:bg-white"><span class="absolute left-[18px] top-0.5 h-4 w-4 rounded-full bg-white shadow transition"></span></span>
                                                <span>{{ __('customer_status.active') }}, pasife al</span>
                                            </button>
                                        </div>
                                    </div>
                                @elseif (! $isActive)
                                    <x-ta.pill tone="info" title="Yalnız Admin veya müşterinin sorumlusu değiştirebilir">Pasif</x-ta.pill>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @if ($customers->hasPages())<div>{{ $customers->links() }}</div>@endif

        @if ($isAdmin && count($selected) > 0)
            <div class="sticky bottom-3 z-30 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-theme-lg ring-1 ring-white/10 dark:bg-gray-800" data-customers-selection>
                <span class="font-medium">{{ count($selected) }} müşteri seçildi</span>
                <button type="button" wire:click="deleteSelected" wire:confirm="Seçili {{ count($selected) }} müşteri, markaları ve dijital varlıklarıyla birlikte listeden silinecek. Toplanan veriler silinmez; yalnızca veri çekimi durur. Hesap tekrar bir markaya bağlanırsa çekim kaldığı yerden devam eder. Devam edilsin mi?" class="inline-flex h-8 w-full items-center justify-center rounded-lg border border-white/20 px-3 text-[13px] font-medium text-error-300 hover:bg-white/10 sm:w-auto">Seçilenleri sil</button>
                <button type="button" wire:click="$set('selected', [])" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
            </div>
        @endif
    @endif
</div>
