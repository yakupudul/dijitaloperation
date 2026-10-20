@php
    // Panel concept 2026-10-20: "Hangi Google Ads hesabının verisi eksik ya da hiç çekilmedi?" The numbers come first,
    // then the account list (Hesaplar), bulk collection, the data catalogue and the live feed.
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
@endphp
<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'google_ads', 'size' => 'lg'])
            <div class="min-w-0">
                <h1 class="page-title">Google Ads</h1>
                <p class="page-sub flex flex-wrap items-center gap-x-3 gap-y-1">
                    @if ($integration)
                        <x-ta.pill :tone="match ($authState['tone']) { 'success' => 'ok', 'warning' => 'look', 'error' => 'critical', default => 'info' }">{{ $authState['label'] }}</x-ta.pill>
                        @if ($authState['tone'] !== 'success')<a href="{{ route('operator.integrations.google') }}" wire:navigate class="link-primary">Google bağlantısı</a>@endif
                        <span>{{ $stats['accounts'] }} hesap bulundu, {{ $stats['collectable'] }} müşteri hesabı veri çekimine uygun</span>
                    @else
                        <span class="text-warning-700 dark:text-warning-400">Google entegrasyonu bulunamadı</span>
                    @endif
                </p>
            </div>
        </div>
        <a href="{{ route('operator.integrations.google') }}" wire:navigate class="btn w-full sm:w-auto">Google hesabını yönet</a>
    </header>

    @if ($actionMessage)
        <p class="card -mt-4 px-4 py-3 text-sm text-gray-700 dark:text-gray-300" role="status">{{ $actionMessage }}</p>
    @endif

    <div class="metric-strip grid-cols-2 lg:grid-cols-5">
        <x-ta.metric-card label="Dikkat gerekli" :value="$stats['attention']" :tone="$stats['attention'] > 0 ? 'look' : 'neutral'" />
        <x-ta.metric-card label="Veri mevcut" :value="$stats['collected']" />
        <x-ta.metric-card label="Veri çekilebilir" :value="$stats['collectable']" />
        <x-ta.metric-card label="Hesaplar" :value="$stats['accounts']" />
        <x-ta.metric-card label="Canlı aktarım" :value="$stats['collecting']" />
    </div>

    <nav class="mb-6! flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Google Ads bölümleri">
        @foreach (['accounts' => 'Hesaplar', 'manual' => 'Toplu işlemler', 'data' => 'Veri', 'activity' => 'Canlı Akış'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class(['tab', 'tab-active' => $tab === $key])>
                {{ $label }}
                @if ($key === 'activity' && $stats['collecting'] > 0)<span class="text-xs font-normal text-gray-500">{{ $stats['collecting'] }}</span>@endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'accounts')
        <livewire:operator.integrations.resource-automations provider="google" resource-type="google_ads" :expanded="true" />
    @endif
    @if ($tab === 'manual')
        <section>
            <div class="section-head items-center">
                <h2 class="section-title" title="İlk aktarım performans veri gruplarında son 180 günü alır. Sonraki güncellemelerde son 30 gün yeniden doğrulanır. Yönetici (MCC) hesapları erişim bağlamıdır ve performans toplama kökü olarak seçilemez.">Google Ads hesapları <span class="section-count">{{ count($rows) }}</span></h2>
                <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                    @if ($selectedCount > 0)
                        <span class="text-[13px] text-gray-600 dark:text-gray-300">{{ $selectedCount }} hesap seçildi</span>
                        <button type="button" wire:click="clearSelection" class="btn-quiet">Seçimi temizle</button>
                    @else
                        <button type="button" wire:click="selectAllVisible" class="btn">Görünenleri seç</button>
                    @endif
                    <button type="button" wire:click="collectSelected" wire:loading.attr="disabled" wire:target="collectSelected" @disabled($selectedCount === 0) class="btn-primary w-full disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto">
                        <span wire:loading.remove wire:target="collectSelected">Seçilenlerin verilerini çek</span>
                        <span wire:loading wire:target="collectSelected">Başlatılıyor…</span>
                    </button>
                </div>
            </div>

            <div class="mb-3 flex flex-wrap gap-2">
                <input type="search" wire:model.live.debounce.250ms="q" placeholder="Hesap adı veya müşteri kimliği ara" aria-label="Ara" class="{{ $field }} w-full min-w-0 flex-1 sm:max-w-md" />
                <select wire:model.live="state" aria-label="Veri durumu" class="{{ $field }} w-full sm:w-auto">
                    <option value="all">Tüm veri durumları</option>
                    <option value="not_collected">Henüz veri yok</option>
                    <option value="collected">Veri mevcut</option>
                    <option value="collecting">Çekiliyor</option><option value="queued">Sırada</option><option value="retrying">Yeniden deneme bekleniyor</option><option value="delayed">İlerleme gecikti</option>
                    <option value="needs_repair">Eksik veri var</option>
                    <option value="resume">Durduruldu</option>
                </select>
            </div>

            <ul class="card">
                @forelse ($rows as $row)
                    <li class="card-row flex-wrap items-start lg:flex-nowrap lg:items-center" wire:key="google-ads-account-{{ $row['id'] }}">
                        @if ($row['selectable_for_collection'])
                            <input type="checkbox" wire:model.live="selectedResourceIds" value="{{ $row['id'] }}" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-gray-900 focus:ring-gray-500 lg:mt-0" aria-label="{{ $row['name'] }} verisini seç" />
                        @else
                            <span class="h-4 w-4 shrink-0" aria-hidden="true"></span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                @if ($row['is_manager'])
                                    <x-ta.pill tone="info">Yönetici hesap</x-ta.pill>
                                @elseif (! $row['provider_selectable'])
                                    <x-ta.pill tone="info">Veri çekilemez</x-ta.pill>
                                @endif
                                @if (! empty($row['bound_assets']))
                                    <x-ta.pill tone="ok">Dijital varlığa bağlı</x-ta.pill>
                                @endif
                            </p>
                            <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400">Müşteri kimliği {{ $row['formatted_customer_id'] ?: '—' }}, {{ $row['currency'] }}, {{ $row['timezone'] }}@if (! empty($row['bound_assets'])). {{ collect($row['bound_assets'])->pluck('brand')->filter()->unique()->implode(', ') }}: {{ collect($row['bound_assets'])->pluck('name')->filter()->implode(', ') }}@endif</p>
                        </div>
                        <div class="w-1/2 pl-7 text-[13px] lg:w-44 lg:shrink-0 lg:pl-0">
                            @switch($row['data_state'])
                                @case('collecting')<p class="font-medium text-gray-700 dark:text-gray-300">Çekiliyor</p><p class="mt-0.5 text-xs text-gray-500">çekim #{{ $row['active_run_id'] }}</p>@break
                                @case('needs_repair')<p class="font-medium text-warning-700 dark:text-warning-400">Eksik veri var</p><p class="mt-0.5 text-xs text-gray-500">{{ $row['failed_datasets'] }} veri grubu tamamlanamadı</p>@break
                                @case('resume')<p class="font-medium text-gray-700 dark:text-gray-300">Aktarım durduruldu</p><p class="mt-0.5 text-xs text-gray-500">Kaldığı yerden devam edebilir</p>@break
                                @case('collected')<p class="font-medium text-gray-700 dark:text-gray-300">Veri mevcut</p><p class="mt-0.5 text-xs text-gray-500">Son başarı {{ $row['last_success'] ?? '—' }}</p>@break
                                @default<p class="font-medium text-gray-500">Henüz veri yok</p><p class="mt-0.5 text-xs text-gray-500">İlk geçmiş aktarımı yapılmadı</p>
                            @endswitch
                        </div>
                        <div class="min-w-0 flex-1 text-[13px] lg:w-48 lg:flex-none lg:shrink-0">
                            @if ($row['coverage_start'] && $row['coverage_end'])
                                <p class="text-gray-700 tabular-nums dark:text-gray-300">{{ $row['coverage_start'] }} – {{ $row['coverage_end'] }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">Son işlem {{ $row['last_collection'] ?? '—' }}</p>
                            @else
                                <p class="text-gray-500">—</p>
                                <p class="mt-0.5 text-xs text-gray-500">Henüz tarih kapsamı oluşmadı</p>
                            @endif
                        </div>
                        <div class="w-full pl-7 lg:w-auto lg:shrink-0 lg:pl-0">
                            @if ($row['provider_selectable'])
                                @if ($row['data_state'] === 'collecting')
                                    <button type="button" wire:click="setTab('activity')" class="btn-quiet w-full lg:w-auto">Canlı akışı aç</button>
                                @else
                                    <button type="button" wire:click="collectOne({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="collectOne({{ $row['id'] }})" class="btn w-full disabled:opacity-50 lg:w-auto">
                                        <span wire:loading.remove wire:target="collectOne({{ $row['id'] }})">{{ $row['action_label'] }}</span>
                                        <span wire:loading wire:target="collectOne({{ $row['id'] }})">Başlatılıyor…</span>
                                    </button>
                                @endif
                            @else
                                <span class="text-xs text-gray-500">Yönetici hesaplarından veri çekilmez</span>
                            @endif
                        </div>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" title="Bu filtrede Google Ads hesabı bulunamadı." message="Google entegrasyonu ekranından kaynakları yenileyebilirsiniz." /></li>
                @endforelse
            </ul>
        </section>
    @elseif ($tab === 'data')
        <div class="grid gap-10 xl:grid-cols-[1.25fr_1fr]">
            <section class="min-w-0">
                <div class="section-head"><h2 class="section-title">Toplanan veri grupları <span class="section-count">{{ count($datasetCatalog) }}</span></h2></div>
                <ul class="card">
                    @foreach ($datasetCatalog as $dataset)
                        <li class="card-row py-2.5">
                            <div class="min-w-0 flex-1"><p class="text-sm text-gray-900 dark:text-white">{{ $dataset['label'] }}</p><p class="mt-0.5 truncate font-mono text-xs text-gray-400">{{ $dataset['dataset'] }}</p></div>
                            <span class="shrink-0 text-xs text-gray-500">{{ $dataset['layer'] === 'professional' ? 'Profesyonel' : 'Temel' }}@if ($dataset['dated']), tarihsel @endif</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="min-w-0">
                <div class="section-head"><h2 class="section-title">Veri havuzu kapsamı <span class="section-count">{{ count($materializations) }}</span></h2></div>
                <ul class="card">
                    @forelse ($materializations as $item)
                        <li class="card-row py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-gray-900 dark:text-white">{{ $item['dataset'] }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $item['coverage_start'] ?? '—' }} – {{ $item['coverage_end'] ?? '—' }}, son başarı {{ $item['last_success'] ?? '—' }}</p>
                            </div>
                            <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ $item['accounts'] }} hesap</span>
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" title="Henüz işlenmiş Google Ads verisi yok." /></li>
                    @endforelse
                </ul>
            </section>
        </div>
    @elseif ($tab === 'activity')
        <livewire:demo.integrations.google-ads-collection-monitor />
    @endif
</div>
