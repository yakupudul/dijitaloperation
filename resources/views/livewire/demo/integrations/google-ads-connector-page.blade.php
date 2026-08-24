<div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="google_ads" size="lg" />
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Google Ads</h1>
                <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    @if ($integration)
                        <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Bağlı</span>
                        <span class="text-gray-300 dark:text-gray-700">·</span>
                        <span>{{ $stats['accounts'] }} hesap bulundu</span>
                        <span class="text-gray-300 dark:text-gray-700">·</span>
                        <span>{{ $stats['collectable'] }} müşteri hesabı veri çekimine uygun</span>
                    @else
                        <span class="font-medium text-amber-700 dark:text-amber-400">Google entegrasyonu bulunamadı</span>
                    @endif
                </div>
                <p class="mt-2 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Google Ads hesaplarını doğrudan merkezi veri havuzuna aktarın. MoxDOP ilk geçmiş aktarımı, güncelleme, eksik veri onarımı ve canlı ilerlemeyi hesap bazında yönetir.</p>
            </div>
        </div>
        <a href="{{ route('operator.integrations.google') }}" wire:navigate class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">Google hesabını yönet</a>
    </header>

    @if ($actionMessage)
        <div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">{{ $actionMessage }}</div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">Hesaplar</p><p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $stats['accounts'] }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">Veri çekilebilir</p><p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $stats['collectable'] }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">Veri mevcut</p><p class="mt-1 text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $stats['collected'] }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">Dikkat gerekli</p><p class="mt-1 text-2xl font-bold tabular-nums text-amber-700 dark:text-amber-400">{{ $stats['attention'] }}</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-400">Canlı aktarım</p><p class="mt-1 text-2xl font-bold tabular-nums text-blue-700 dark:text-blue-400">{{ $stats['collecting'] }}</p></div>
    </div>

    <nav class="flex flex-wrap gap-5 border-b border-gray-200 dark:border-gray-800" aria-label="Google Ads sections">
        @foreach (['accounts' => 'Hesaplar', 'data' => 'Veri', 'activity' => 'Canlı Akış'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                '-mb-px border-b-2 px-0.5 pb-3 pt-1 text-sm font-medium transition',
                'border-brand-500 text-brand-700 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800 dark:hover:border-gray-700 dark:hover:text-white/90' => $tab !== $key,
            ])>
                {{ $label }}
                @if ($key === 'activity' && $stats['collecting'] > 0)<span class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-50 px-1.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">{{ $stats['collecting'] }}</span>@endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'accounts')
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-4 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Google Ads hesapları</h2>
                    <p class="mt-0.5 max-w-3xl text-sm text-gray-500 dark:text-gray-400">İlk aktarım performans datasetlerinde son 180 günü alır. Sonraki güncellemelerde son 30 gün yeniden doğrulanır. MCC/yönetici hesapları erişim bağlamıdır ve performans toplama kökü olarak seçilemez.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($selectedCount > 0)
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $selectedCount }} hesap seçildi</span>
                        <button type="button" wire:click="clearSelection" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]">Seçimi temizle</button>
                    @else
                        <button type="button" wire:click="selectAllVisible" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">Görünenleri seç</button>
                    @endif
                    <button type="button" wire:click="collectSelected" wire:loading.attr="disabled" wire:target="collectSelected" @disabled($selectedCount === 0) class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400 dark:disabled:bg-gray-800 dark:disabled:text-gray-500">
                        <span wire:loading.remove wire:target="collectSelected">Seçilenlerin verilerini çek</span>
                        <span wire:loading wire:target="collectSelected">Başlatılıyor…</span>
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex w-full max-w-3xl gap-2">
                    <div class="relative flex-1">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                        <input type="search" wire:model.live.debounce.250ms="q" placeholder="Hesap adı veya Customer ID ara" class="w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                    </div>
                    <select wire:model.live="state" aria-label="Veri durumu" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                        <option value="all">Tüm veri durumları</option>
                        <option value="not_collected">Henüz veri yok</option>
                        <option value="collected">Veri mevcut</option>
                        <option value="collecting">Çekiliyor</option>
                        <option value="needs_repair">Eksik veri var</option>
                        <option value="resume">Durduruldu</option>
                    </select>
                </div>
                <p class="text-xs text-gray-400">{{ count($rows) }} hesap</p>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($rows as $row)
                    <article class="px-4 py-4" wire:key="google-ads-account-{{ $row['id'] }}">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                @if ($row['selectable_for_collection'])
                                    <input type="checkbox" wire:model.live="selectedResourceIds" value="{{ $row['id'] }}" class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-brand-500 focus:ring-brand-500" aria-label="{{ $row['name'] }} verisini seç" />
                                @else
                                    <span class="mt-1 h-4 w-4 shrink-0"></span>
                                @endif
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['name'] }}</h3>
                                        @if ($row['is_manager'])
                                            <span class="rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">MCC / Yönetici</span>
                                        @elseif (! $row['provider_selectable'])
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-400">Veri çekilemez</span>
                                        @endif
                                        @if (! empty($row['bound_assets']))
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Dijital varlığa bağlı</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Customer ID {{ $row['formatted_customer_id'] ?: '—' }} · {{ $row['currency'] }} · {{ $row['timezone'] }}</p>
                                    @if (! empty($row['bound_assets']))
                                        <p class="mt-1 text-xs text-gray-400">{{ collect($row['bound_assets'])->pluck('brand')->filter()->unique()->implode(', ') }} · {{ collect($row['bound_assets'])->pluck('name')->filter()->implode(', ') }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="grid min-w-0 gap-3 sm:grid-cols-3 xl:min-w-[680px] xl:grid-cols-[1.3fr_1.2fr_auto] xl:items-center">
                                <div>
                                    <p class="text-xs text-gray-400">Veri durumu</p>
                                    @switch($row['data_state'])
                                        @case('collecting')<p class="mt-1 text-sm font-semibold text-blue-700 dark:text-blue-300">Çekiliyor</p><p class="mt-0.5 text-xs text-gray-400">Run #{{ $row['active_run_id'] }}</p>@break
                                        @case('needs_repair')<p class="mt-1 text-sm font-semibold text-amber-700 dark:text-amber-300">Eksik veri var</p><p class="mt-0.5 text-xs text-gray-400">{{ $row['failed_datasets'] }} veri grubu tamamlanamadı</p>@break
                                        @case('resume')<p class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-300">Aktarım durduruldu</p><p class="mt-0.5 text-xs text-gray-400">Kaldığı yerden devam edebilir</p>@break
                                        @case('collected')<p class="mt-1 text-sm font-semibold text-emerald-700 dark:text-emerald-300">Veri mevcut</p><p class="mt-0.5 text-xs text-gray-400">Son başarı {{ $row['last_success'] ?? '—' }}</p>@break
                                        @default<p class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-300">Henüz veri yok</p><p class="mt-0.5 text-xs text-gray-400">İlk geçmiş aktarımı yapılmadı</p>
                                    @endswitch
                                </div>
                                <div>
                                    <p class="text-xs text-gray-400">Kapsam</p>
                                    @if ($row['coverage_start'] && $row['coverage_end'])
                                        <p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $row['coverage_start'] }} → {{ $row['coverage_end'] }}</p>
                                        <p class="mt-0.5 text-xs text-gray-400">Son işlem {{ $row['last_collection'] ?? '—' }}</p>
                                    @else
                                        <p class="mt-1 text-sm font-medium text-gray-500">—</p>
                                        <p class="mt-0.5 text-xs text-gray-400">Henüz tarih kapsamı oluşmadı</p>
                                    @endif
                                </div>
                                <div class="sm:text-right">
                                    @if ($row['provider_selectable'])
                                        @if ($row['data_state'] === 'collecting')
                                            <button type="button" wire:click="setTab('activity')" class="rounded-lg px-3 py-2 text-sm font-semibold text-blue-700 ring-1 ring-inset ring-blue-200 hover:bg-blue-50 dark:text-blue-300 dark:ring-blue-800 dark:hover:bg-blue-500/10">Canlı akışı aç</button>
                                        @else
                                            <button type="button" wire:click="collectOne({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="collectOne({{ $row['id'] }})" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">
                                                <span wire:loading.remove wire:target="collectOne({{ $row['id'] }})">{{ $row['action_label'] }}</span>
                                                <span wire:loading wire:target="collectOne({{ $row['id'] }})">Başlatılıyor…</span>
                                            </button>
                                        @endif
                                    @else
                                        <span class="text-xs text-gray-400">Yönetici hesaplarından veri çekilmez</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="px-5 py-10 text-center"><p class="text-sm font-medium text-gray-700 dark:text-gray-300">Bu filtrede Google Ads hesabı bulunamadı.</p><p class="mt-1 text-xs text-gray-500">Google Integration ekranından kaynakları yenileyebilirsiniz.</p></div>
                @endforelse
            </div>
        </section>
    @elseif ($tab === 'data')
        <div class="grid gap-5 xl:grid-cols-[1.25fr_1fr]">
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Toplanan veri setleri</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Google Ads provider verileri merkezi Data Pool’a typed dataset olarak yazılır. Oranlar ve yorumlar daha sonra MoxDOP tarafından bu temel gerçeklerden türetilir.</p></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($datasetCatalog as $dataset)
                        <div class="flex items-center justify-between gap-4 px-5 py-3">
                            <div class="min-w-0"><p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $dataset['label'] }}</p><p class="mt-0.5 truncate text-xs text-gray-400">{{ $dataset['dataset'] }}</p></div>
                            <div class="flex shrink-0 items-center gap-2"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-400">{{ $dataset['layer'] === 'professional' ? 'Professional' : 'Core' }}</span>@if ($dataset['dated'])<span class="text-[11px] text-gray-400">Tarihsel</span>@endif</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Data Pool kapsamı</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Başarılı materialization kayıtlarından görülen mevcut tarih kapsamı.</p></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($materializations as $item)
                        <div class="px-5 py-3"><div class="flex items-center justify-between gap-3"><p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">{{ $item['dataset'] }}</p><span class="text-xs text-gray-400">{{ $item['accounts'] }} hesap</span></div><p class="mt-1 text-xs text-gray-500">{{ $item['coverage_start'] ?? '—' }} → {{ $item['coverage_end'] ?? '—' }} · Son başarı {{ $item['last_success'] ?? '—' }}</p></div>
                    @empty
                        <div class="px-5 py-10 text-center text-sm text-gray-500">Henüz materialize edilmiş Google Ads verisi yok.</div>
                    @endforelse
                </div>
            </section>
        </div>
    @else
        <livewire:demo.integrations.google-ads-collection-monitor />
    @endif
</div>
