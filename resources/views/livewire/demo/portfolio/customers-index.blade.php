@php
    $select = 'h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $chip = ['ok' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'warn' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'bad' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'];
    $band = ['good' => 'ok', 'watch' => 'warn', 'risk' => 'bad'];
    $attentionLabels = ['needs_attention' => 'Dikkat istiyor', 'clear' => 'Sorun yok'];
    $sortHead = fn (string $key, string $label) => '<button type="button" wire:click="sortBy(\''.$key.'\')" class="font-medium hover:text-gray-900 dark:hover:text-white">'.e($label).($sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '').'</button>';
@endphp
<div class="space-y-4" data-customers-index>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Müşteriler</h1>
            <p class="mt-1 text-sm text-gray-500">Ajansın müşterileri. Açık iş ve veri sorunları müşterinin markalarından toplanır.</p>
            @if ($allCount > 0)
                <p class="mt-1 text-xs text-gray-500" data-customers-summary>{{ $allCount }} müşteri · {{ $attentionCount }} müşteri dikkat istiyor</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($isAdmin)
                <a href="{{ route('operator.integrations.discovered') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">Keşfedilen varlıklar</a>
            @endif
            <a href="{{ route('operator.customer.create') }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Müşteri ekle</a>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Müşteri adı, unvan veya e-posta ara" aria-label="Ara"
            class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
        <select wire:model.live="attention" class="{{ $select }}" aria-label="Dikkat">
            <option value="">Dikkat: hepsi</option>
            @foreach ($attentionLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="{{ $select }}" aria-label="Durum">
            <option value="">Durum: hepsi</option>
            @foreach ($statusOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="industry" class="{{ $select }}" aria-label="Sektör">
            <option value="">Sektör: hepsi</option>
            @foreach ($industryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="responsible" class="{{ $select }}" aria-label="Sorumlu">
            <option value="">Sorumlu: herkes</option>
            @foreach ($teamOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="type" class="{{ $select }}" aria-label="Tür">
            <option value="">Tür: hepsi</option>
            @foreach ($typeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="hq_country" class="{{ $select }}" aria-label="Merkez ülke">
            <option value="">Ülke: hepsi</option>
            @foreach ($countryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="service" class="{{ $select }}" aria-label="Hizmet">
            <option value="">Hizmet: hepsi</option>
            @foreach ($serviceOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
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
                $status !== '' ? ($statusOptions[$status] ?? $status) : null,
                $industry !== '' ? ($industryOptions[$industry] ?? $industry) : null,
                $responsible !== '' ? ($teamOptions[$responsible] ?? $responsible) : null,
                $type !== '' ? ($typeOptions[$type] ?? $type) : null,
                $hq_country !== '' ? ($countryOptions[$hq_country] ?? $hq_country) : null,
                $service !== '' ? ($serviceOptions[$service] ?? $service) : null,
            ]) as $label)
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ $label }}</span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="font-medium text-brand-600 hover:underline">Temizle</button>
        </div>
    @endif

    @if ($allCount === 0)
        <section class="rounded-xl bg-white p-6 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Henüz müşteri yok</h2>
            <p class="mt-1 text-sm text-gray-500">İlk müşteriyi ekleyin; markaları ve hesapları müşterinin altında açılır.</p>
            <a href="{{ route('operator.customer.create') }}" wire:navigate class="mt-3 inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Müşteri ekle</a>
        </section>
    @elseif ($customers->total() === 0)
        <section class="rounded-xl bg-white p-6 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Filtreye uyan müşteri yok</h2>
            <button type="button" wire:click="clearFilters" class="mt-2 text-sm font-medium text-brand-600 hover:underline">Filtreleri temizle</button>
        </section>
    @else
        @if ($isAdmin && count($selected) > 0)
            <div class="flex flex-wrap items-center gap-3 rounded-xl bg-rose-50 px-4 py-2.5 text-sm ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:ring-rose-500/20">
                <span class="font-medium text-rose-800 dark:text-rose-200">{{ count($selected) }} müşteri seçildi</span>
                <button type="button" wire:click="deleteSelected" wire:confirm="Seçili {{ count($selected) }} müşteri, markaları ve dijital varlıklarıyla birlikte listeden silinecek. Toplanan veriler silinmez; yalnızca veri çekimi durur. Hesap tekrar bir markaya bağlanırsa çekim kaldığı yerden devam eder. Devam edilsin mi?" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Seçilenleri sil</button>
                <button type="button" wire:click="$set('selected', [])" class="text-xs text-rose-700 hover:underline dark:text-rose-300">Seçimi temizle</button>
            </div>
        @endif

        <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-100 text-xs text-gray-500 dark:border-gray-800">
                    <tr>
                        @if ($isAdmin)<th class="w-8 px-3 py-3"><input type="checkbox" aria-label="Tümünü seç" wire:click="toggleAll({{ json_encode($visibleIds) }})" @checked(count($selected) > 0 && count(array_intersect($selected, $visibleIds)) === count($visibleIds)) class="rounded border-gray-300"></th>@endif
                        <th class="px-4 py-3">{!! $sortHead('name', 'Müşteri') !!}</th>
                        <th class="hidden px-4 py-3 md:table-cell">{!! $sortHead('industry', 'Sektör') !!}</th>
                        <th class="hidden px-4 py-3 text-right sm:table-cell">{!! $sortHead('brands', 'Marka') !!}</th>
                        <th class="px-4 py-3 text-right">{!! $sortHead('work', 'Açık iş') !!}</th>
                        <th class="hidden px-4 py-3 text-right sm:table-cell">Veri sorunu</th>
                        <th class="px-4 py-3">{!! $sortHead('attention', 'Dikkat') !!}</th>
                        @if ($showOptionalColumns)
                            <th class="hidden px-4 py-3 lg:table-cell">Sorumlu</th>
                            <th class="hidden px-4 py-3 lg:table-cell">{!! $sortHead('service_started', 'Hizmet başlangıcı') !!}</th>
                            <th class="hidden px-4 py-3 text-right lg:table-cell">Varlık</th>
                        @endif
                        <th class="px-4 py-3">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($customers as $customer)
                        @php
                            $score = $health[$customer['id']] ?? null;
                            $canToggle = $isAdmin || in_array($actorId, $customer['responsible_user_ids'] ?? [], true);
                            $isActive = ($customer['status'] ?? '') === 'active';
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="customer-{{ $customer['id'] }}" data-customer-row="{{ $customer['id'] }}">
                            @if ($isAdmin)<td class="px-3 py-3"><input type="checkbox" aria-label="Seç" value="{{ (int) $customer['id'] }}" wire:model.live="selected" class="rounded border-gray-300"></td>@endif
                            <td class="px-4 py-3">
                                <a href="{{ route('operator.customer', ['customerId' => $customer['id']]) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $customer['name'] }}</a>
                                <p class="text-xs text-gray-500">{{ collect([$customer['legal_name'] ?? null, $customer['type_label'] ?? null])->filter()->implode(' · ') }}</p>
                                @if ($score !== null)
                                    <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-500" data-health-score>
                                        <span class="rounded-full px-1.5 py-0.5 font-semibold tabular-nums {{ $chip[$band[$score['band']] ?? 'warn'] }}">{{ $score['score'] }}</span>
                                        <span>{{ $score['reasons'][0] ?? 'Puanı düşüren bir şey yok' }}</span>
                                    </p>
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 text-gray-700 md:table-cell dark:text-gray-300">
                                {{ $customer['sector_label'] }}
                                @if ($customer['sector_from_brands'])<p class="text-xs text-gray-400">markalardan</p>@endif
                            </td>
                            <td class="hidden px-4 py-3 text-right tabular-nums sm:table-cell">{{ $customer['brands_count'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums" data-open-work>
                                {{ $customer['open_work'] }}
                                @if ($customer['critical'] > 0)<p class="text-xs text-rose-600">{{ $customer['critical'] }} kritik</p>@endif
                            </td>
                            <td class="hidden px-4 py-3 text-right tabular-nums sm:table-cell" data-data-issues>@if ($customer['data_issues'] > 0)<span class="text-amber-700 dark:text-amber-300">{{ $customer['data_issues'] }}</span>@else<span class="text-gray-400">0</span>@endif</td>
                            <td class="px-4 py-3" data-attention>
                                @if ($customer['needs_attention'])
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $customer['reconnect'] > 0 ? $chip['bad'] : $chip['warn'] }}">Dikkat</span>
                                    <p class="mt-0.5 max-w-xs text-xs text-gray-500">{{ $customer['attention_reason'] }}</p>
                                @elseif ($customer['brands_count'] > 0)
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $chip['ok'] }}">Sorun yok</span>
                                @else
                                    <span class="text-xs text-gray-400">Marka yok</span>
                                @endif
                            </td>
                            @if ($showOptionalColumns)
                                <td class="hidden px-4 py-3 text-gray-700 lg:table-cell dark:text-gray-300">{{ implode(', ', $customer['responsible_labels'] ?? []) ?: '—' }}</td>
                                <td class="hidden px-4 py-3 tabular-nums text-gray-500 lg:table-cell">{{ $customer['service_started_at'] ?? '—' }}</td>
                                <td class="hidden px-4 py-3 text-right tabular-nums lg:table-cell">{{ $customer['digital_assets_count'] ?? 0 }}</td>
                            @endif
                            <td class="px-4 py-3">
                                @if (($customer['status'] ?? '') === 'archived')
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $customer['status_label'] }}</span>
                                @elseif ($canToggle)
                                    <button type="button" role="switch" aria-checked="{{ $isActive ? 'true' : 'false' }}"
                                        wire:click="toggleActive('{{ $customer['id'] }}')" wire:loading.attr="disabled"
                                        @if ($isActive) wire:confirm="{{ __('customer_status.confirm_pause', ['name' => $customer['name']]) }}" @endif
                                        title="{{ $isActive ? __('customer_status.hint_active') : __('customer_status.hint_passive') }}"
                                        class="inline-flex items-center gap-2 text-sm" data-toggle-active>
                                        <span @class(['relative inline-flex h-5 w-9 shrink-0 rounded-full transition', 'bg-emerald-500' => $isActive, 'bg-gray-300 dark:bg-gray-700' => ! $isActive])>
                                            <span @class(['absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition', 'left-[18px]' => $isActive, 'left-0.5' => ! $isActive])></span>
                                        </span>
                                        <span @class(['text-emerald-700 dark:text-emerald-400' => $isActive, 'text-gray-500' => ! $isActive])>{{ $isActive ? 'Aktif' : 'Pasif' }}</span>
                                    </button>
                                @else
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $isActive ? $chip['ok'] : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' }}" title="Yalnız Admin veya müşterinin sorumlusu değiştirebilir">{{ $isActive ? 'Aktif' : 'Pasif' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @if ($customers->hasPages())<div>{{ $customers->links() }}</div>@endif
    @endif
</div>
