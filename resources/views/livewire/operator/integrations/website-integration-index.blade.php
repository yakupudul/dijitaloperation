@php
    // Panel concept 2026-10-20. List: "Hangi sitenin verisi eksik ya da çekim bekliyor?" Detail: "Bu sitenin verisi tam
    // mı, çekim sürüyor mu?" The running console and the WordPress refresh state come before the tabs.
    $tr = app()->getLocale() === 'tr';
    $messageClass = fn (string $tone): string => match ($tone) {
        'success' => 'text-success-700 dark:text-success-400',
        'warning' => 'text-warning-800 dark:text-warning-400',
        'error' => 'text-error-700 dark:text-error-400',
        default => 'text-gray-700 dark:text-gray-300',
    };
    $pillTone = fn (string $tone): string => match ($tone) {
        'success' => 'ok',
        'warning' => 'look',
        'error' => 'critical',
        default => 'info',
    };
    $stateTone = fn (string $state): string => match ($state) {
        'completed' => 'success',
        'running', 'queued', 'retrying', 'cancellation_requested' => 'info',
        'failed', 'cancelled', 'attention' => 'error',
        'partial', 'connection_required', 'needs_setup' => 'warning',
        default => 'neutral',
    };
    $tabs = [
        'overview' => $tr ? 'Genel Bakış' : 'Overview',
        'sources' => $tr ? 'Veri Kaynakları' : 'Data Sources',
        'runs' => $tr ? 'Çekimler' : 'Runs',
        'activity' => $tr ? 'İşlem geçmişi' : 'Activity',
        'data' => $tr ? 'Toplanan Veriler' : 'Collected Data',
        'settings' => $tr ? 'Ayarlar' : 'Settings',
    ];
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $small = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
@endphp

<div class="space-y-10" @if (($liveConsole['active'] ?? false) === true) wire:poll.5s @else wire:poll.60s @endif>
    @if ($selectedRow === null)
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="page-title">{{ $tr ? 'Website veri kaynakları' : 'Website data sources' }}</h1>
                <p class="page-sub" title="Siteyi markadan önce ekleyip WordPress'e bağlayabilirsiniz; marka eklerken listeden seçtiğinizde site, bağlantısı ve verisi markaya geçer.">{{ $tr ? 'Her sitenin kaynakları, son çekimi ve kapsamı.' : 'Each website’s sources, latest run and coverage.' }}</p>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress']) }}" wire:navigate class="btn">WordPress Connector</a>
                <form wire:submit="addWebsite" class="flex w-full items-center gap-2 sm:w-auto">
                    <input wire:model="newWebsite" type="text" aria-label="Web sitesi ekle" placeholder="ornek.com.tr" class="{{ $field }} min-w-0 flex-1 sm:w-44 sm:flex-none">
                    <button type="submit" class="btn-primary">Web sitesi ekle</button>
                </form>
            </div>
        </header>

        @if ($message !== '')
            <p class="card -mt-4 px-4 py-3 text-sm {{ $messageClass($messageTone) }}" role="status">{{ $message }}</p>
        @endif

        <section class="metric-strip grid-cols-2 lg:grid-cols-4" aria-label="{{ $tr ? 'Çekim özeti' : 'Collection summary' }}">
            <x-ta.metric-card :label="$tr ? 'Dikkat gereken' : 'Needs attention'" :value="number_format((int) $stats['attention'], 0, ',', '.')" :tone="$stats['attention'] > 0 ? 'look' : 'neutral'" />
            <x-ta.metric-card :label="$tr ? 'Devam eden' : 'In progress'" :value="number_format((int) $stats['running'], 0, ',', '.')" />
            <x-ta.metric-card :label="$tr ? 'Çekime hazır' : 'Ready to collect'" :value="number_format((int) $stats['collect_ready'], 0, ',', '.')" />
            <x-ta.metric-card :label="$tr ? 'Website' : 'Websites'" :value="number_format((int) $stats['total'], 0, ',', '.')" />
        </section>

        <section>
            <div class="section-head items-center">
                <h2 class="section-title">{{ $tr ? 'Siteler' : 'Websites' }} <span class="section-count">{{ count($rows) }}</span></h2>
                <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                    <input wire:model.live.debounce.300ms="search" type="search" aria-label="{{ $tr ? 'Ara' : 'Search' }}" class="{{ $field }} w-full min-w-0 sm:w-64" placeholder="{{ $tr ? 'Website, müşteri veya alan adı ara' : 'Search website, customer, or domain' }}">
                    <select wire:model.live="filter" aria-label="{{ $tr ? 'Filtre' : 'Filter' }}" class="{{ $field }} w-full sm:w-auto">
                        @foreach ($filters as $option)
                            <option value="{{ $option['key'] }}">{{ $option['label'] }} ({{ $option['count'] }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Website</th>
                            <th class="hidden lg:table-cell">{{ $tr ? 'Kaynaklar' : 'Sources' }}</th>
                            <th>{{ $tr ? 'Son çekim' : 'Latest run' }}</th>
                            <th class="hidden text-right! md:table-cell">{{ $tr ? 'Temel kapsam' : 'Core coverage' }}</th>
                            <th class="hidden text-right! md:table-cell">{{ $tr ? 'Sonuç' : 'Result' }}</th>
                            <th class="text-right!"><span class="sr-only">{{ $tr ? 'Aç' : 'Open' }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="max-w-0 w-[40%] align-top sm:w-[30%]">
                                    <a href="{{ route('operator.integrations.website', ['assetId' => $row['asset']->id]) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-white" title="{{ $row['asset']->name }}">{{ $row['asset']->name }}</a>
                                    <p class="mt-0.5 truncate text-xs text-gray-500">{{ $row['asset']->domain ?: $row['asset']->primary_url ?: '—' }}</p>
                                    @if ($row['asset']->brand_id === null)
                                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <x-ta.pill tone="look">Markaya bağlı değil</x-ta.pill>
                                            @if ($canAssign && $assigningSiteId !== $row['asset']->id)
                                                <button type="button" wire:click="startAssign({{ $row['asset']->id }})" class="text-xs link-neutral">Markaya ata</button>
                                            @endif
                                        </p>
                                        @if ($canAssign && $assigningSiteId === $row['asset']->id)
                                            <div class="mt-2 space-y-2" data-assign-website="{{ $row['asset']->id }}">
                                                <select wire:model.live="assignCustomerId" aria-label="Müşteri" class="{{ $small }} w-full">
                                                    <option value="">Müşteri seçin…</option>
                                                    @foreach ($assignCustomers as $customerId => $customerName)
                                                        <option value="{{ $customerId }}">{{ $customerName }}</option>
                                                    @endforeach
                                                </select>
                                                <select wire:model="assignBrandId" aria-label="Marka" @disabled($assignBrands === []) class="{{ $small }} w-full">
                                                    <option value="">Marka seçin…</option>
                                                    @foreach ($assignBrands as $brandId => $brandName)
                                                        <option value="{{ $brandId }}">{{ $brandName }}</option>
                                                    @endforeach
                                                </select>
                                                @error('assignBrandId')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                                                <div class="flex gap-1.5">
                                                    <button type="button" wire:click="assignWebsite" class="btn-primary h-7! px-2.5! text-xs!">Ata</button>
                                                    <button type="button" wire:click="cancelAssign" class="btn-quiet h-7! px-2.5! text-xs!">Vazgeç</button>
                                                </div>
                                            </div>
                                        @endif
                                    @elseif ($row['asset']->brand?->customer?->name)
                                        <p class="mt-0.5 truncate text-xs text-gray-400">{{ $row['asset']->brand->customer->name }}</p>
                                    @endif
                                </td>
                                <td class="hidden align-top text-[13px] text-gray-600 lg:table-cell dark:text-gray-300">{{ $row['source_summary'] }}</td>
                                <td class="align-top">
                                    <x-ta.pill :tone="$pillTone($stateTone($row['overall_state']))">{{ $row['run_status_label'] }}</x-ta.pill>
                                    <p class="mt-1 text-xs text-gray-400">{{ $row['last_run_at']?->diffForHumans() ?? '—' }}</p>
                                </td>
                                <td class="hidden text-right align-top tabular-nums md:table-cell">
                                    <p class="text-gray-900 dark:text-white">{{ $row['required_completed'] }}/{{ $row['required_total'] }}</p>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ $tr ? 'zorunlu kaynak' : 'required sources' }}</p>
                                </td>
                                <td class="hidden text-right align-top tabular-nums md:table-cell">
                                    <p class="text-gray-900 dark:text-white">{{ number_format((int) $row['latest_rows_written'], 0, ',', '.') }}</p>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ $tr ? 'yazılan kayıt' : 'rows written' }}</p>
                                </td>
                                <td class="whitespace-nowrap text-right align-top">
                                    <div class="flex flex-col items-end gap-1.5 sm:flex-row sm:justify-end">
                                        <a href="{{ route('operator.website', ['assetId' => $row['asset']->id]) }}" wire:navigate data-open-site-screen="{{ $row['asset']->id }}" class="btn-quiet">{{ $tr ? 'Site ekranına git' : 'Open site screen' }}</a>
                                        <a href="{{ route('operator.integrations.website', ['assetId' => $row['asset']->id]) }}" wire:navigate class="btn">{{ $tr ? 'Aç' : 'Open' }}</a>
                                    </div>
                                    @if ($canAssign)
                                        <div class="mt-1.5 flex justify-end gap-3 text-xs">
                                            <a href="{{ route('operator.asset.edit', ['assetId' => $row['asset']->id]) }}" wire:navigate data-edit-website="{{ $row['asset']->id }}" class="link-neutral">{{ $tr ? 'Düzenle' : 'Edit' }}</a>
                                            <button type="button" data-remove-website="{{ $row['asset']->id }}" wire:click="removeWebsite({{ $row['asset']->id }})" wire:confirm="{{ $row['asset']->name }} kaldırılsın mı? Site listelerden çıkar, WordPress bağlayıcısı kapanır; toplanan veriler silinmez." class="text-gray-500 hover:text-error-600 dark:hover:text-error-400">{{ $tr ? 'Kaldır' : 'Remove' }}</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-12 text-center text-gray-500">{{ $tr ? 'Eşleşen Website bulunamadı.' : 'No matching Website was found.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $selectedRow['wordpress_detected'] ? 'wordpress' : 'website', 'size' => 'lg'])
                <div class="min-w-0">
                    <h1 class="page-title flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="min-w-0 truncate">{{ $selectedRow['asset']->name }}</span>
                        <x-ta.pill :tone="$pillTone($stateTone($selectedRow['overall_state']))">{{ $selectedRow['run_status_label'] }}</x-ta.pill>
                    </h1>
                    <p class="page-sub truncate"><a href="{{ $selectedRow['asset']->primary_url ?: ('https://'.$selectedRow['asset']->domain) }}" target="_blank" rel="noreferrer" class="hover:text-gray-900 dark:hover:text-white">{{ $selectedRow['asset']->primary_url ?: $selectedRow['asset']->domain }}</a></p>
                </div>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <a href="{{ route('operator.integrations.website') }}" wire:navigate class="btn-quiet">{{ $tr ? 'Tüm web siteleri' : 'All websites' }}</a>
                <a href="{{ route('operator.website', ['assetId' => $selectedRow['asset']->id]) }}" wire:navigate data-open-site-screen="{{ $selectedRow['asset']->id }}" class="btn">{{ $tr ? 'Site ekranına git' : 'Open site screen' }}</a>
            </div>
        </header>

        <section class="-mt-4">
            <div class="card">
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 0-2.34 5.66M20 12V6m0 6h-6"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $tr ? 'Veri çekimi' : 'Collection' }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $tr ? 'Genel çekim: WordPress envanteri, değişen sayfaların HTML’i ve TLS.' : 'General collection: WordPress inventory, changed pages’ HTML and TLS.' }}</p>
                    </div>
                    <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:flex-nowrap">
                        <label for="website-collection-scope" class="sr-only">{{ $tr ? 'Çekim kapsamı' : 'Collection scope' }}</label>
                        <select id="website-collection-scope" wire:model="collectionScope" wire:loading.attr="disabled" title="{{ $tr ? 'Genel çekim: önce WordPress envanteri, ardından sayfaların HTML’i (yalnız değişenler) ve TLS. Tam yeniden okuma tüm sayfaları yeniden okur (nadiren gerekir; WordPress bağlı sitelerde ayda bir gece kendiliğinden yapılır). Hız ölçümü için PageSpeed’i seçin.' : 'General collection: HTML (changed pages only), TLS and connected WordPress. Full re-read reads every page (rarely needed; runs by itself once a month at night on WordPress-connected sites). Select PageSpeed for speed measurements.' }}" class="{{ $field }} w-full sm:w-auto">
                            <option value="full">{{ $tr ? 'Genel çekim' : 'General collection' }}</option>
                            <option value="full_reread">{{ $tr ? 'Tam yeniden okuma' : 'Full re-read' }}</option>
                            <option value="public">{{ $tr ? 'Sayfa HTML ve TLS' : 'Page HTML and TLS' }}</option>
                            <option value="wordpress" @disabled(! $selectedRow['wordpress_ready'])>{{ $tr ? 'WordPress tam envanter' : 'WordPress full inventory' }}</option>
                            <option value="pagespeed" @disabled(! $selectedRow['page_speed_ready'])>PageSpeed</option>
                        </select>
                        <button type="button" wire:click="collectNow({{ $selectedRow['asset']->id }})" wire:loading.attr="disabled" @disabled(! $selectedRow['collectable'] || (($liveConsole['active'] ?? false) === true)) class="btn-primary w-full disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto">
                            <span wire:loading.remove wire:target="collectNow">{{ ($liveConsole['active'] ?? false) ? ($tr ? 'Veri çekimi sürüyor' : 'Collection in progress') : ($tr ? 'Veri çekimini başlat' : 'Start collection') }}</span>
                            <span wire:loading wire:target="collectNow">{{ $tr ? 'Başlatılıyor…' : 'Starting…' }}</span>
                        </button>
                        @if (($liveConsole['active'] ?? false) === true)
                            <button type="button" data-collection-stop wire:click="stopCollection({{ $selectedRow['asset']->id }})" wire:confirm="{{ $tr ? 'Süren çekim durdurulsun mu?' : 'Stop the running collection?' }}" wire:loading.attr="disabled" class="btn w-full hover:text-error-600! disabled:opacity-50 sm:w-auto dark:hover:text-error-400!">
                                <span wire:loading.remove wire:target="stopCollection">{{ $tr ? 'Çekimi durdur' : 'Stop collection' }}</span>
                                <span wire:loading wire:target="stopCollection">{{ $tr ? 'Durduruluyor…' : 'Stopping…' }}</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        @if ($message !== '')
            <p class="card -mt-4 px-4 py-3 text-sm {{ $messageClass($messageTone) }}" role="status">{{ $message }}</p>
        @endif

        @if ($liveConsole)
            <section>
                <div class="section-head items-center">
                    @if ($liveConsole['active'])
                        <span class="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-gray-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-gray-500"></span></span>
                    @endif
                    <h2 class="section-title">{{ $tr ? 'Veri çekim konsolu' : 'Collection console' }}</h2>
                    <x-ta.pill :tone="$pillTone($stateTone($liveConsole['state']))">{{ $liveConsole['status_label'] }}</x-ta.pill>
                </div>
                <div class="card">
                    <div class="card-row flex-wrap items-start sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] text-gray-500 dark:text-gray-400">{{ $tr ? 'Çekim' : 'Run' }} #{{ $liveConsole['id'] }}, {{ $liveConsole['scope_label'] }}, {{ $liveConsole['duration_label'] ?: '—' }}. {{ $tr ? 'Son hareket' : 'Last activity' }} {{ $liveConsole['last_activity_at']?->diffForHumans() ?? '—' }}</p>
                            <div class="mt-2.5 h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full bg-gray-900 transition-all dark:bg-white" style="width: {{ $liveConsole['progress_percent'] }}%"></div></div>
                        </div>
                        <div class="shrink-0 sm:text-right">
                            <p class="text-[26px] leading-8 font-semibold tabular-nums text-gray-900 dark:text-white">%{{ $liveConsole['progress_percent'] }}</p>
                            @if ($liveConsole['active'] && ($liveConsole['pages']['planned'] ?? 0) > 0)
                                <p class="text-xs text-gray-500">{{ number_format($liveConsole['pages']['done'], 0, ',', '.') }} / {{ number_format($liveConsole['pages']['planned'], 0, ',', '.') }} {{ $tr ? 'sayfa' : 'pages' }}@if ($liveConsole['pages']['eta_label'] !== null), {{ $tr ? 'tahmini' : 'about' }} {{ $liveConsole['pages']['eta_label'] }}@endif</p>
                            @else
                                <p class="text-xs text-gray-500">{{ $liveConsole['datasets_completed'] }}/{{ $liveConsole['datasets_total'] }} {{ $tr ? 'adım tamamlandı' : 'steps done' }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="grid gap-px border-t border-gray-200 bg-gray-200 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 dark:bg-gray-800">
                        @foreach ($liveConsole['stages'] as $stage)
                            <div class="bg-white px-4 py-3 dark:bg-gray-900">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $stage['label'] }}@if ($stage['optional']) <span class="text-xs font-normal text-gray-500">{{ $tr ? 'İsteğe bağlı' : 'Optional' }}</span>@endif</p>
                                    <x-ta.pill :tone="$pillTone($stateTone($stage['state']))">{{ $stage['status_label'] }}</x-ta.pill>
                                </div>
                                @if ($stage['summary'])<p class="mt-1.5 text-xs text-gray-500">{{ $stage['summary'] }}</p>@endif
                                @if ($stage['error'])<p class="mt-1.5 line-clamp-2 text-xs text-error-600 dark:text-error-400">{{ $stage['error'] }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                    @if ($liveConsole['failure_summary'])
                        <p class="border-t border-gray-200 px-4 py-3 text-sm text-error-700 dark:border-gray-800 dark:text-error-400">{{ $liveConsole['failure_summary'] }}</p>
                    @endif
                </div>
            </section>
        @endif

        @if ($selectedRow['wordpress_detected'])
            <section>
                <div class="section-head items-center">
                    <h2 class="section-title" title="{{ $tr ? 'Değişiklikler küçük gruplar halinde alınır, etkilenen URL’ler kontrol edilir. Tam envanter aşağıdaki sıklıkta yenilenir. Otomatik genel tarama yapılmaz; PageSpeed elle başlatılır.' : 'Changes are collected in small batches and affected URLs are checked. Full inventory follows the selected cadence. General public crawl and PageSpeed are started separately.' }}">{{ $tr ? 'Otomatik WordPress yenilemesi' : 'Automatic WordPress refresh' }}</h2>
                    @if ($deliveryState['ready'] ?? false)
                        <div class="inline-flex rounded-lg border border-gray-200 p-0.5 sm:ml-auto dark:border-gray-700" aria-label="{{ $tr ? 'Otomatik çekim sıklığı' : 'Automatic collection cadence' }}">
                            @foreach (['daily' => [$tr ? 'Günlük' : 'Daily', $deliveryState['enabled'] && $deliveryState['interval'] === 1], 'three_days' => [$tr ? '3 günde bir' : 'Every 3 days', $deliveryState['enabled'] && $deliveryState['interval'] === 3], 'paused' => [$tr ? 'Duraklat' : 'Pause', ! $deliveryState['enabled']]] as $mode => [$label, $chosen])
                                <button type="button" wire:click="setAutomation({{ $selectedRow['asset']->id }}, '{{ $mode }}')" wire:loading.attr="disabled" aria-pressed="{{ $chosen ? 'true' : 'false' }}" @class(['h-7 rounded-md px-3 text-[13px] font-medium', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $chosen, 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white' => ! $chosen])>{{ $label }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="card">
                    @if ($deliveryState['ready'] ?? false)
                        @if (! $deliveryState['enabled'])
                            <p class="card-row text-[13px] text-warning-800 dark:text-warning-400">{{ $tr ? 'Otomatik çekim duraklatıldı. Site bildirimleri kaydedilir; aktif çekim tamamlanır. Manuel çekim başlatabilirsiniz.' : 'Automatic collection is paused. Events are recorded and active work finishes. Manual collection remains available.' }}</p>
                        @elseif ($deliveryState['stale'])
                            <p class="card-row text-[13px] text-warning-800 dark:text-warning-400">{{ $tr ? 'Son 24 saatte site bildirimi gelmedi. Connector bağlantısını ve WordPress zamanlanmış görevlerini kontrol edin.' : 'No site delivery in the last 24 hours. Check the Connector connection and WordPress scheduled tasks.' }}</p>
                        @endif
                        @if ($deliveryState['outdated'] ?? false)
                            <p class="card-row text-[13px] text-warning-800 dark:text-warning-400">{{ $tr ? 'Eklenti sürümü '.($deliveryState['version'] ?: 'bilinmiyor').'; güncel sürüm '.config('moxdop-wordpress.connector_version').'. Yeni sürümü indirip sitede güncelleyin.' : 'Plugin version '.($deliveryState['version'] ?: 'unknown').'; current is '.config('moxdop-wordpress.connector_version').'. Download and update it on the site.' }}</p>
                        @endif
                        @if ($deliveryState['error'])
                            <p class="card-row text-[13px] text-error-700 dark:text-error-400">{{ $deliveryState['error'] }} @if ($deliveryState['enabled'] && $deliveryState['next_retry']) {{ $tr ? 'Yeniden deneme en erken:' : 'Retry no earlier than:' }} {{ $deliveryState['next_retry'] }} @endif</p>
                        @endif
                        <dl class="grid grid-cols-2 border-t border-gray-200 first:border-t-0 xl:grid-cols-4 dark:border-gray-800">
                            @foreach ([
                                [$tr ? 'Son site bildirimi' : 'Last site delivery', $deliveryState['last_received'] ?: '—'],
                                [$tr ? 'Son otomatik yenileme' : 'Last automatic refresh', $deliveryState['last_reconciled'] ?: '—'],
                                [$tr ? 'Son tam WordPress envanteri' : 'Last full WordPress inventory', $deliveryState['last_inventory'] ?: '—'],
                                [$tr ? 'İşlenmeyi bekleyen değişiklik' : 'Pending changes', number_format($deliveryState['pending'])],
                            ] as [$label, $value])
                                <div class="px-4 py-3"><dt class="text-[13px] text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @else
                        <div class="card-row flex-wrap sm:flex-nowrap">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                            <p class="min-w-0 flex-1 text-[13px] text-gray-700 dark:text-gray-300">{{ $tr ? 'Eşleştirilmiş Connector için otomatik envanter sıradaki zamanlayıcı çalışmasında hazırlanır. Bağlantıyı test ederek de başlatabilirsiniz; ilk site bildirimi gerekmez.' : 'Automatic inventory for a paired Connector is prepared on the next scheduler tick. A connection test also initializes it; no first site delivery is required.' }}</p>
                            <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $selectedRow['asset']->id]) }}" wire:navigate class="btn w-full sm:w-auto">{{ $tr ? 'Connector bağlantısını aç' : 'Open Connector connection' }}</a>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        <nav class="mb-6! flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="{{ $tr ? 'Website bölümleri' : 'Website sections' }}">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class(['tab', 'tab-active' => $activeTab === $key])>{{ $label }}</button>
            @endforeach
        </nav>

        @if ($activeTab === 'activity')
            <livewire:operator.integrations.word-press-activity-panel :asset-id="$selectedRow['asset']->id" :key="'wp-activity-'.$selectedRow['asset']->id" />
        @endif

        @if ($activeTab === 'overview')
            <section aria-label="{{ $tr ? 'Özet' : 'Summary' }}">
                <dl class="card">
                    @foreach ($overviewLines as $line)
                        <div class="card-row flex-wrap items-baseline">
                            <dt class="w-32 shrink-0 text-[13px] text-gray-500 dark:text-gray-400">{{ $line['label'] }}</dt>
                            <dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $line['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section>
                <div class="section-head items-center">
                    <h2 class="section-title" title="{{ $tr ? 'Her kaynağın son çekimi. Ayrıntılar Çekimler ve Toplanan Veriler sekmelerinde.' : 'Each source’s latest collection. Details are in the Runs and Collected Data tabs.' }}">{{ $tr ? 'Kaynak durumu' : 'Source status' }} <span class="section-count">{{ count($selectedRow['data_sources']) }}</span></h2>
                    <button type="button" wire:click="setTab('sources')" class="ml-auto text-[13px] link-neutral">{{ $tr ? 'Tümü' : 'All' }}</button>
                </div>
                <ul class="card">
                    @foreach ($selectedRow['data_sources'] as $source)
                        <li class="card-row flex-wrap sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['label'] }}@if ($source['optional']) <span class="text-xs font-normal text-gray-500">{{ $tr ? 'İsteğe bağlı' : 'Optional' }}</span>@endif</p>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ $source['connection_label'] }}, {{ $tr ? 'son veri:' : 'last data:' }} {{ $source['last_collected_at']?->diffForHumans() ?? '—' }}</p>
                            </div>
                            <x-ta.pill :tone="$pillTone($stateTone($source['state']))">{{ $source['status_label'] }}</x-ta.pill>
                        </li>
                    @endforeach
                </ul>
            </section>
        @elseif ($activeTab === 'sources')
            <div class="space-y-10">
                @foreach ($selectedRow['data_sources'] as $source)
                    <section>
                        <div class="section-head items-center">
                            <h2 class="section-title" title="{{ $source['description'] }}">{{ $source['label'] }}@if ($source['optional']) <span class="section-count">{{ $tr ? 'İsteğe bağlı' : 'Optional' }}</span>@endif</h2>
                            <span class="flex items-center gap-3 sm:ml-auto">
                                <span class="text-[13px] text-gray-500">{{ $source['connection_label'] }}</span>
                                <x-ta.pill :tone="$pillTone($stateTone($source['state']))">{{ $source['status_label'] }}</x-ta.pill>
                            </span>
                        </div>

                        <ul class="card">
                            @if ($source['key'] === 'site_connector' && ! $selectedRow['wordpress_ready'])
                                <li class="card-row flex-wrap sm:flex-nowrap">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                                    <p class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $tr ? 'WordPress eklentisi bu siteyle eşleşmemiş.' : 'The WordPress plugin is not paired with this site.' }}</p>
                                    <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $selectedRow['asset']->id]) }}" wire:navigate class="btn-primary w-full sm:w-auto">{{ $tr ? 'WordPress paketini indir ve siteyi eşleştir' : 'Download the WordPress package and pair the site' }}</a>
                                </li>
                            @endif
                            @foreach ($source['datasets'] as $dataset)
                                <li class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800">
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 sm:flex-nowrap">
                                        <div class="min-w-0 flex-1">
                                            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5"><span class="text-sm font-medium text-gray-900 dark:text-white">{{ $dataset['label'] }}</span><x-ta.pill :tone="$pillTone($stateTone($dataset['state']))">{{ $dataset['status_label'] }}</x-ta.pill></p>
                                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $dataset['description'] }}</p>
                                        </div>
                                        <div class="text-right"><p class="text-sm tabular-nums text-gray-900 dark:text-white">{{ number_format((int) $dataset['current_rows'], 0, ',', '.') }}</p><p class="text-xs text-gray-400">{{ $dataset['row_label'] }}</p></div>
                                        <button type="button" wire:click="selectDataset('{{ $dataset['id'] }}')" class="btn h-8! px-3! text-[13px]!">{{ $tr ? 'Veriyi aç' : 'Open data' }}</button>
                                    </div>
                                    <details class="mt-2 text-xs text-gray-500">
                                        <summary class="cursor-pointer text-gray-600 dark:text-gray-300">{{ $tr ? 'Çekim ve teknik ayrıntılar' : 'Collection and technical details' }}</summary>
                                        <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                            <div><span class="block text-gray-400">{{ $tr ? 'Son çekimde işlenen' : 'Processed in latest run' }}</span><span class="text-gray-900 dark:text-white">{{ number_format((int) $dataset['processed_rows'], 0, ',', '.') }}</span></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Son çekimde yeni / güncel' : 'Inserted / updated in latest run' }}</span><span class="text-gray-900 dark:text-white">{{ $dataset['inserted_rows'] }} / {{ $dataset['updated_rows'] }}</span></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Başarılı paket' : 'Committed batches' }}</span><span class="text-gray-900 dark:text-white">{{ $dataset['successful_batches'] }}</span></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Son veri' : 'Last data' }}</span><span class="text-gray-900 dark:text-white">{{ $dataset['last_collected_at']?->diffForHumans() ?? '—' }}</span></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Veri kümesi kimliği' : 'Dataset ID' }}</span><span class="break-all font-mono text-gray-900 dark:text-white">{{ $dataset['id'] }}</span></div>
                                        </div>
                                    </details>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @elseif ($activeTab === 'runs')
            <section>
                <div class="section-head">
                    <h2 class="section-title" title="{{ $tr ? 'Son 10 Website çekimi; tetikleyici, süre, çıktı ve hata özetiyle birlikte.' : 'Latest 10 Website runs with trigger, duration, output, and failure summary.' }}">{{ $tr ? 'Veri çekim geçmişi' : 'Collection history' }} <span class="section-count">{{ count($history) }}</span></h2>
                </div>
                <div class="card overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>{{ $tr ? 'Çekim' : 'Run' }}</th><th>{{ $tr ? 'Durum' : 'State' }}</th><th class="hidden md:table-cell">{{ $tr ? 'Tetikleyici' : 'Trigger' }}</th><th class="text-right!">{{ $tr ? 'Veri grubu' : 'Datasets' }}</th><th class="hidden text-right! sm:table-cell">{{ $tr ? 'Yazılan' : 'Written' }}</th><th class="hidden text-right! sm:table-cell">{{ $tr ? 'Süre' : 'Duration' }}</th><th>{{ $tr ? 'Başlangıç' : 'Started' }}</th></tr></thead>
                        <tbody>
                            @forelse ($history as $run)
                                <tr>
                                    <td class="font-mono text-xs text-gray-500">#{{ $run['id'] }}</td>
                                    <td><x-ta.pill :tone="$pillTone($stateTone((string) $run['status']))">{{ $run['status_label'] }}</x-ta.pill>@if ($run['failure_summary'])<p class="mt-1.5 max-w-72 text-xs text-error-600 dark:text-error-400">{{ $run['failure_summary'] }}</p>@endif</td>
                                    <td class="hidden md:table-cell"><p class="text-[13px]">{{ $run['trigger_label'] }}</p><p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $run['scope_label'] }}, {{ $run['requested_by'] }}</p></td>
                                    <td class="text-right tabular-nums">{{ $run['datasets_completed'] }}/{{ $run['datasets_total'] }}@if ($run['datasets_failed'] > 0)<p class="mt-0.5 text-xs text-error-600 dark:text-error-400">{{ $run['datasets_failed'] }} {{ $tr ? 'başarısız' : 'failed' }}</p>@endif</td>
                                    <td class="hidden text-right tabular-nums sm:table-cell">{{ number_format((int) $run['rows_written'], 0, ',', '.') }}</td>
                                    <td class="hidden text-right tabular-nums sm:table-cell">{{ $run['duration_label'] ?: '—' }}</td>
                                    <td class="whitespace-nowrap"><p class="text-[13px]">{{ $run['started_at']?->format('d.m.Y H:i') ?? '—' }}</p><p class="mt-0.5 text-xs text-gray-400">{{ $run['started_at']?->diffForHumans() ?? '—' }}</p></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-12 text-center text-gray-500">{{ $tr ? 'Henüz veri çekim geçmişi yok.' : 'No collection history yet.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @elseif ($activeTab === 'data')
            <div class="grid gap-10 xl:grid-cols-[280px_minmax(0,1fr)]">
                <aside class="min-w-0 self-start xl:sticky xl:top-4">
                    <div class="section-head"><h2 class="section-title">{{ $tr ? 'Veri kümeleri' : 'Datasets' }} <span class="section-count">{{ $availableDatasets->count() }}</span></h2></div>
                    <ul class="card max-h-[640px] overflow-y-auto">
                        @foreach ($availableDatasets as $dataset)
                            <li class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                                <button type="button" wire:click="selectDataset('{{ $dataset['id'] }}')" @class([
                                    'w-full px-4 py-2.5 text-left transition',
                                    'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)] dark:bg-white/[0.05] dark:shadow-[inset_2px_0_0_white]' => ($selectedDataset['id'] ?? null) === $dataset['id'],
                                    'hover:bg-gray-50 dark:hover:bg-white/[0.03]' => ($selectedDataset['id'] ?? null) !== $dataset['id'],
                                ])><span class="block text-sm text-gray-900 dark:text-white">{{ $dataset['label'] }}</span><span class="mt-0.5 block text-xs text-gray-500">{{ number_format((int) $dataset['current_rows'], 0, ',', '.') }} {{ $dataset['row_label'] }}</span></button>
                            </li>
                        @endforeach
                    </ul>
                </aside>

                <section class="min-w-0">
                    @if ($selectedDataset)
                        <div class="section-head items-center">
                            <h2 class="section-title" title="{{ $selectedDataset['description'] }}">{{ $selectedDataset['label'] }}</h2>
                            <x-ta.pill :tone="$pillTone($stateTone($selectedDataset['state']))">{{ $selectedDataset['status_label'] }}</x-ta.pill>
                            <span class="ml-auto text-[13px] tabular-nums text-gray-500">{{ number_format((int) ($dataExplorer['total'] ?? 0), 0, ',', '.') }} {{ $tr ? 'eşleşen ' : 'matching ' }}{{ $selectedDataset['row_label'] }}</span>
                        </div>
                        <input wire:model.live.debounce.400ms="dataSearch" type="search" aria-label="{{ $tr ? 'Bu dataset içinde ara' : 'Search within this dataset' }}" class="{{ $field }} mb-3 w-full" placeholder="{{ $tr ? 'Bu dataset içinde ara' : 'Search within this dataset' }}">

                        <div class="card">
                            @if (($dataExplorer['state'] ?? 'unavailable') === 'available')
                                <div class="overflow-x-auto">
                                    <table class="data-table text-xs">
                                        <thead><tr>@foreach ($dataExplorer['columns'] as $column)<th class="whitespace-nowrap">{{ $column['label'] }}</th>@endforeach</tr></thead>
                                        <tbody>@foreach ($dataExplorer['rows'] as $record)<tr>@foreach ($dataExplorer['columns'] as $column)<td class="max-w-80 truncate text-gray-700 dark:text-gray-300" title="{{ $record[$column['name']] ?? '—' }}">{{ $record[$column['name']] ?? '—' }}</td>@endforeach</tr>@endforeach</tbody>
                                    </table>
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                                    <p class="text-xs tabular-nums text-gray-500">{{ $dataExplorer['from'] }}–{{ $dataExplorer['to'] }} / {{ number_format((int) $dataExplorer['total'], 0, ',', '.') }}</p>
                                    <div class="flex gap-1.5"><button type="button" wire:click="setDataPage({{ max(1, $dataExplorer['page'] - 1) }})" @disabled($dataExplorer['page'] <= 1) class="btn h-7! px-2.5! text-xs! disabled:opacity-40">{{ $tr ? 'Önceki' : 'Previous' }}</button><button type="button" wire:click="setDataPage({{ min($dataExplorer['last_page'], $dataExplorer['page'] + 1) }})" @disabled($dataExplorer['page'] >= $dataExplorer['last_page']) class="btn h-7! px-2.5! text-xs! disabled:opacity-40">{{ $tr ? 'Sonraki' : 'Next' }}</button></div>
                                </div>
                            @elseif (($dataExplorer['state'] ?? '') === 'empty')
                                <x-ta.empty-state icon="none" :title="$tr ? 'Eşleşen kayıt yok.' : 'No matching rows.'" :message="$tr ? 'Aramayı temizleyin veya yeni bir veri çekimi başlatın.' : 'Clear the search or start a new collection.'" />
                            @else
                                <x-ta.empty-state icon="none" :title="$tr ? 'Bu dataset için görüntülenebilir fiziksel tablo bulunamadı.' : 'No viewable physical table is available for this dataset.'" />
                            @endif

                            <details class="border-t border-gray-200 px-4 py-3 text-xs dark:border-gray-800">
                                <summary class="cursor-pointer text-gray-600 dark:text-gray-300">{{ $tr ? 'Alanlar ve teknik bilgiler' : 'Fields and technical details' }}</summary>
                                <div class="mt-3 flex flex-wrap gap-1.5">@foreach ($selectedDataset['fields'] as $datasetField)<span class="rounded-md border border-gray-200 px-2 py-0.5 text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ $datasetField['label'] }} <span class="text-gray-400">{{ $datasetField['type'] }}</span></span>@endforeach @if ($selectedDataset['system_field_count'] > 0)<span class="rounded-md border border-gray-200 px-2 py-0.5 text-gray-500 dark:border-gray-700">+{{ $selectedDataset['system_field_count'] }} {{ $tr ? 'sistem alanı' : 'system fields' }}</span>@endif</div>
                                <p class="mt-3 break-all font-mono text-gray-400">{{ $selectedDataset['id'] }}@if ($selectedDataset['table']), {{ $selectedDataset['table'] }}@endif</p>
                            </details>
                        </div>
                    @else
                        <div class="card"><x-ta.empty-state icon="none" :title="$tr ? 'Görüntülenecek dataset bulunamadı.' : 'No dataset is available to display.'" /></div>
                    @endif
                </section>
            </div>
        @elseif ($activeTab === 'settings')
            <div class="grid gap-10 xl:grid-cols-2">
                <section class="min-w-0">
                    <div class="section-head"><h2 class="section-title">{{ $tr ? 'Website kimliği' : 'Website identity' }}</h2></div>
                    <dl class="card">
                        @foreach ([
                            [$tr ? 'Ad' : 'Name', $selectedRow['asset']->name],
                            [$tr ? 'Alan adı' : 'Domain', $selectedRow['asset']->domain ?: '—'],
                            ['URL', $selectedRow['asset']->primary_url ?: '—'],
                            ['CMS', $selectedRow['asset']->cms ?: ($tr ? 'Belirlenmedi' : 'Not detected')],
                        ] as [$label, $value])
                            <div class="card-row flex-wrap"><dt class="w-28 shrink-0 text-[13px] text-gray-500">{{ $label }}</dt><dd class="min-w-0 flex-1 break-all text-sm text-gray-900 dark:text-white">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section class="min-w-0">
                    <div class="section-head"><h2 class="section-title">{{ $tr ? 'Kaynak bağlantıları' : 'Source connections' }}</h2></div>
                    <ul class="card">
                        @foreach ($selectedRow['data_sources'] as $source)
                            <li class="card-row"><div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['label'] }}</p><p class="mt-0.5 text-[13px] text-gray-500">{{ $source['connection_label'] }}</p></div><x-ta.pill :tone="$pillTone($stateTone($source['state']))">{{ $source['status_label'] }}</x-ta.pill></li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $selectedRow['asset']->id]) }}" wire:navigate class="btn">WordPress Connector</a>
                        <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">{{ $tr ? 'Tüm entegrasyonlar' : 'All integrations' }}</a>
                    </div>
                </section>
            </div>
        @endif
    @endif
</div>
