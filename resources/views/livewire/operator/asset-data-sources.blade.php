@php
    // One question: which account feeds this asset, and is its data flowing?
    $providerName = fn (?string $provider): string => ['google' => 'Google', 'meta' => 'Meta'][(string) $provider] ?? ucfirst((string) $provider);
    $resourceState = fn (?string $status): string => [\App\Models\CoreExternalResource::STATUS_AVAILABLE => 'Erişilebilir', \App\Models\CoreExternalResource::STATUS_UNAVAILABLE => 'Erişilemiyor'][(string) $status] ?? (string) $status;
    $at = fn ($time): string => $time?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—';

    $activeBindings = collect($capabilities)->filter(fn (string $capability): bool => $bindings->get($capability)?->status === \App\Models\CoreAssetBinding::STATUS_ACTIVE && $bindings->get($capability)?->externalResource);
    $boundResourceIds = $activeBindings->map(fn (string $capability): int => (int) $bindings->get($capability)->external_resource_id)->values();
    $boundAutomations = $boundResourceIds->map(fn (int $id) => $automations->get($id))->filter();
    // Same reading as the head's "Veri durumu" line, so the two never disagree.
    $sourceStates = collect(app(\App\Services\DataStatus\DataStatusReader::class)->forAsset($asset))
        ->reject(fn ($status): bool => $status->state === \App\Services\DataStatus\DataStatus::NOT_BOUND);
    $lastData = $sourceStates->pluck('lastDataDate')->filter()->sortDesc()->first();
    $lastSuccess = $sourceStates->pluck('lastSuccessAt')->filter()->sortDesc()->first();
    $autoStopped = $boundAutomations->contains(fn ($automation): bool => $automation->collection_status === 'attention');
    $autoOn = $boundAutomations->contains(fn ($automation): bool => (bool) $automation->collection_enabled);
    $readySources = collect($websiteSources)->where('ready', true)->count();
    // One accent button at a time: binding a missing account comes first; once everything is bound, pulling data is the main action.
    $bindingMissing = $canDiscover && $activeBindings->count() < count($capabilities);
@endphp

<div class="min-w-0 space-y-8">
    <x-operator.asset-context :asset-id="$assetId" current="sources" :title="$asset->name" :refresh="false">
        <x-slot:mark><x-demo.digital-asset-mark :type="(string) $asset->type" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>
            <span title="{{ collect([$customer?->name, $brand?->name])->filter()->unique()->implode(', ') }}. {{ __('operator_runtime.sources.description') }}">{{ \App\View\Components\Operator\AssetContext::typeLabel((string) $asset->type) }} veri kaynakları ve hesap bağlantısı</span>
        </x-slot:meta>
        <x-slot:actions>
            @foreach ($canDiscover ? $providers : [] as $provider)
                <button type="button" wire:click="discover('{{ $provider }}')" wire:loading.attr="disabled" class="btn">
                    {{ __('operator_runtime.sources.discover', ['provider' => $providerName($provider)]) }}
                </button>
            @endforeach
            <button type="button" wire:click="collectNow" @disabled(! $hasCollectableSource) wire:loading.attr="disabled" @class(['btn-primary' => ! $bindingMissing, 'btn' => $bindingMissing])>
                {{ __('operator_runtime.sources.collect_now') }}
            </button>
        </x-slot:actions>
    </x-operator.asset-context>

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm',
            'tone-critical' => $messageTone === 'error',
            'tone-ok' => $messageTone === 'success',
            'tone-info' => ! in_array($messageTone, ['error', 'success'], true),
        ]) role="status">{{ $message }}</div>
    @endif

    @if ($ownershipConflict)
        <x-operator.ownership-transfer-panel :conflict="$ownershipConflict" action="transferResource" :can-transfer="$canTransfer" />
    @endif

    {{-- 1. The answer in one strip: how many accounts feed it, when data last came, whether pulls run by themselves. --}}
    <section>
        <div @class(['metric-strip grid-cols-2', 'lg:grid-cols-4' => $isWebsite, 'lg:grid-cols-3' => ! $isWebsite && $capabilities !== []])>
            @if ($capabilities !== [])
                <x-ta.metric-card label="Bağlı hesap" :value="$activeBindings->count().' / '.count($capabilities)" :tone="$activeBindings->count() === count($capabilities) ? 'positive' : 'look'"
                    :hint="$activeBindings->isEmpty() ? 'Hiç hesap bağlı değil' : $activeBindings->map(fn (string $capability): string => \App\Support\Integrations\ProviderRegistry::capabilityLabel($capability))->implode(', ')" />
            @endif
            <x-ta.metric-card label="Son veri" :value="$lastData?->locale('tr')->translatedFormat('j M') ?? '—'" :muted="$lastData === null"
                :hint="$sourceStates->isEmpty() ? 'Henüz veri yok' : $sourceStates->map(fn ($status): string => $status->label())->unique()->implode(', ').($lastSuccess ? ', çekim '.$lastSuccess->locale('tr')->diffForHumans() : '')" />
            <x-ta.metric-card label="Otomatik çekim" :value="$boundAutomations->isEmpty() ? '—' : ($autoStopped ? 'Durdu' : ($autoOn ? 'Açık' : 'Kapalı'))" :muted="$boundAutomations->isEmpty()"
                :tone="$autoStopped ? 'negative' : 'neutral'" :hint="$autoStopped ? 'Bağlantıya bakılmalı' : ($boundAutomations->isNotEmpty() ? 'Hesap kendiliğinden güncellenir' : ($activeBindings->isEmpty() ? 'Bağlı hesap yok' : 'Plan kaydı yok'))"
                @class(['col-span-2 lg:col-span-1' => $capabilities !== [] && ! $isWebsite]) />
            @if ($isWebsite)
                <x-ta.metric-card label="Site kaynakları" :value="$readySources.' / '.count($websiteSources)" :tone="$readySources === count($websiteSources) ? 'positive' : 'look'" hint="Hesap gerekmeden çalışanlar" />
            @endif
        </div>
    </section>

    {{-- 2. One card per data type: the bound account, what it last wrote, and the control to bind or change it. --}}
    @if ($capabilities !== [])
        <section>
            <div class="section-head">
                <h2 class="section-title" title="{{ __('operator_runtime.sources.capability_help') }}">Hesap bağlantıları <span class="section-count">{{ count($capabilities) }}</span></h2>
            </div>
            <div @class(['grid items-start gap-6', 'xl:grid-cols-2' => count($capabilities) > 1])>
                @foreach ($capabilities as $capability)
                    @php
                        $binding = $bindings->get($capability);
                        $current = $binding?->externalResource;
                        $available = $resources[$capability] ?? collect();
                        $active = $binding && $binding->status === \App\Models\CoreAssetBinding::STATUS_ACTIVE;
                        $automation = $current ? $automations->get($current->id) : null;
                        $write = $current ? ($lastWrites[(int) $current->id] ?? null) : null;
                        $canBind = $available->isNotEmpty() || ($ownedElsewhere[$capability] ?? []) !== [];
                    @endphp

                    <section class="card" wire:key="source-{{ $capability }}">
                        <div class="card-row">
                            <x-demo.digital-asset-mark :type="$capability" size="md" class="shrink-0" />
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-medium text-gray-900 dark:text-white" title="{{ __('operator_runtime.sources.capability_help') }}">{{ \App\Support\Integrations\ProviderRegistry::capabilityLabel($capability) }}</h3>
                                <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400">{{ $active && $current ? ($current->display_name ?: $current->external_id) : 'Hesap seçilmedi' }}</p>
                            </div>
                            <x-ta.pill :tone="$active ? ($automation?->collection_status === 'attention' ? 'look' : 'ok') : 'info'">{{ $active ? __('operator_runtime.sources.connected') : __('operator_runtime.sources.not_connected') }}</x-ta.pill>
                        </div>

                        @if ($active && $current)
                            <div class="card-row block space-y-3">
                                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                                    <div class="col-span-2 sm:col-span-1">
                                        <dt class="text-xs text-gray-500 dark:text-gray-400">Hesap kimliği</dt>
                                        <dd class="mt-0.5 break-all text-sm tabular-nums text-gray-900 dark:text-white">{{ $current->external_id }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('operator_runtime.sources.provider') }}</dt>
                                        <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $providerName($current->integration?->provider) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('operator_runtime.sources.resource_status') }}</dt>
                                        <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $resourceState($current->status) }}</dd>
                                    </div>
                                </dl>

                                @if ($automation)
                                    <p @class(['flex flex-wrap items-center gap-x-1.5 text-[13px]', 'text-error-700 dark:text-error-400' => $automation->collection_status === 'attention', 'text-gray-500 dark:text-gray-400' => $automation->collection_status !== 'attention'])>
                                        <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-error-500' => $automation->collection_status === 'attention', 'bg-success-500' => $automation->collection_status !== 'attention' && $automation->collection_enabled, 'bg-gray-300' => ! $automation->collection_enabled]) aria-hidden="true"></span>
                                        <span>Otomatik veri çekimi {{ $automation->collection_enabled ? ($automation->collection_status === 'attention' ? 'durdu' : 'açık') : 'kapalı' }}, son başarılı {{ $automation->last_collection_success_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'henüz yok' }}{{ $automation->collection_status === 'attention' && $automation->collection_error ? '. '.__('resource-auto.'.$automation->collection_error).'.' : '' }}</span>
                                        @if ($automation->collection_status === 'attention' && $automation->collection_error)
                                            <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="link-primary">Sistem Sağlığı</a>
                                        @endif
                                    </p>
                                @endif

                                @if ($write !== null)
                                    @php $n = fn (int $v): string => number_format($v, 0, ',', '.'); @endphp
                                    <p class="text-[13px] text-gray-500 dark:text-gray-400" data-last-write>
                                        <span class="text-gray-900 dark:text-white">Son çekim {{ $at($write['at']) }}: {{ $n($write['inserted']) }} yeni satır, {{ $n($write['updated']) }} güncellendi, {{ $n($write['unchanged']) }} değişmedi.</span>
                                        {{ ['search_console' => 'Son 7 gün her çekimde yeniden doğrulanır (Google rakamları geç kesinleştirir).', 'google_ads' => 'Geç gelen dönüşümler için son günler yeniden, haftada bir son 30 gün doğrulanır.'][$capability] ?? '' }}
                                        Aynı gün ve boyut tek satırdır; tekrar çekim kopya oluşturmaz, değişen satırı günceller.
                                    </p>
                                @endif
                            </div>
                        @endif

                        @if ($capability === 'google_business_profile')
                            @php
                                $gbpDatasets = app()->getLocale() === 'tr'
                                    ? ['Profil & Google Güncellemeleri', 'Günlük Görünürlük & Aksiyonlar', 'Aylık Arama Kelimeleri', 'Yorumlar', 'Fotoğraf & Video', 'Google Gönderileri', 'Attributes', 'Hizmetler', 'Randevu / Sipariş Linkleri', 'Doğrulama & Profil Durumu']
                                    : ['Profile & Google Updates', 'Daily Visibility & Actions', 'Monthly Search Keywords', 'Reviews', 'Photo & Video', 'Google Posts', 'Attributes', 'Services', 'Appointment / Order Links', 'Verification & Profile State'];
                            @endphp
                            <div class="card-row block">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ app()->getLocale() === 'tr' ? 'Google’dan çekilecek veriler' : 'Data collected from Google' }} <span class="tabular-nums">{{ count($gbpDatasets) }}</span></p>
                                <p class="mt-1 text-[13px] text-gray-700 dark:text-gray-300">{{ implode(', ', $gbpDatasets) }}</p>
                            </div>
                        @endif

                        @if ($canDiscover)
                            <div class="card-row block space-y-3">
                                <div>
                                    <label for="source-select-{{ $capability }}" class="mb-1.5 block text-xs text-gray-500 dark:text-gray-400">{{ $active ? __('operator_runtime.sources.change_resource') : __('operator_runtime.sources.select_resource_label') }}</label>
                                    <select id="source-select-{{ $capability }}" wire:model="selectedResource.{{ $capability }}"
                                        class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm sm:max-w-md text-gray-900 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                        <option value="">{{ __('operator_runtime.sources.choose') }}</option>
                                        @foreach ($available as $resource)
                                            <option value="{{ $resource->id }}">{{ $resource->display_name ?: $resource->external_id }} · {{ $resource->external_id }}</option>
                                        @endforeach
                                        @if (($ownedElsewhere[$capability] ?? []) !== [])
                                            <optgroup label="Başka varlığa bağlı (seçerseniz yetki devri sorulur)">
                                                @foreach ($ownedElsewhere[$capability] as $foreign)
                                                    <option value="{{ $foreign['id'] }}">{{ $foreign['label'] }} — {{ $foreign['owner'] }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    </select>
                                    @error('selectedResource.'.$capability)
                                        <p class="mt-1 text-xs text-error-600 dark:text-error-400">{{ $message }}</p>
                                    @enderror
                                    @if ($available->isEmpty() && ($ownedElsewhere[$capability] ?? []) === [])
                                        <p class="mt-1.5 text-xs text-warning-700 dark:text-warning-400">{{ __('operator_runtime.sources.no_resources') }}</p>
                                    @endif
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="bind('{{ $capability }}')" @class(['btn-primary' => ! $active && $canBind, 'btn' => $active || ! $canBind])>
                                        {{ $active ? __('operator_runtime.sources.change') : __('operator_runtime.sources.connect') }}
                                    </button>
                                    @if ($active)
                                        <button type="button" wire:click="disable('{{ $capability }}')" class="btn-quiet">
                                            {{ __('operator_runtime.sources.disconnect') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 3. A website also feeds itself: public crawl, HTML, SSL, PageSpeed, the WordPress connector. --}}
    @if ($isWebsite)
        <section>
            <div class="section-head">
                <h2 class="section-title" title="{{ app()->getLocale() === 'tr' ? 'Bu kaynaklar için Google/Meta hesabı gerekmez. Web sitesinin adresi tanımlıysa sayfa taraması, HTML analizi ve SSL kontrolü doğrudan çalışır.' : 'These sources are independent from Google/Meta resource bindings. With a Website primary URL/domain, public crawl, HTML analysis and SSL/TLS can run directly.' }}">
                    {{ app()->getLocale() === 'tr' ? 'Web sitesinin kendi kaynakları' : 'Website’s own sources' }} <span class="section-count">{{ count($websiteSources) }}</span>
                </h2>
                <a href="{{ route('operator.integrations.website') }}" wire:navigate class="link-neutral text-[13px]">
                    {{ app()->getLocale() === 'tr' ? 'Website Entegrasyon Merkezi' : 'Website Integration Center' }}
                </a>
            </div>
            <p class="sr-only">
                {{ app()->getLocale() === 'tr'
                    ? 'Bu kaynaklar için Google/Meta hesabı gerekmez. Web sitesinin adresi tanımlıysa sayfa taraması, HTML analizi ve SSL kontrolü doğrudan çalışır.'
                    : 'These sources are independent from Google/Meta resource bindings. With a Website primary URL/domain, public crawl, HTML analysis and SSL/TLS can run directly.' }}
            </p>

            <ul class="card">
                @foreach ($websiteSources as $source)
                    @php
                        $sourceReady = (bool) ($source['ready'] ?? false);
                        $sourceStatus = (string) ($source['status'] ?? '');
                        $sourceStatusLabel = match ($sourceStatus) {
                            'ready' => app()->getLocale() === 'tr' ? 'Hazır' : 'Ready',
                            'url_required' => app()->getLocale() === 'tr' ? 'URL gerekli' : 'URL required',
                            'domain_required' => app()->getLocale() === 'tr' ? 'Domain gerekli' : 'Domain required',
                            'connection_required' => app()->getLocale() === 'tr' ? 'Bağlantı gerekli' : 'Connection required',
                            'plugin_required' => app()->getLocale() === 'tr' ? 'WordPress, eklenti kurulmalı' : 'WordPress · plugin required',
                            'not_wordpress' => app()->getLocale() === 'tr' ? 'WordPress değil, yalnız elle ya da açık tarama' : 'Not WordPress · manual public crawl only',
                            default => $sourceStatus,
                        };
                    @endphp
                    <li class="card-row py-3" wire:key="website-source-{{ $source['key'] ?? $loop->index }}">
                        <p class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $source['name'] }}</p>
                        <x-ta.pill :tone="$sourceReady ? 'ok' : (str_contains($sourceStatus, 'required') ? 'look' : 'info')">{{ $sourceStatusLabel }}</x-ta.pill>
                    </li>
                @endforeach
            </ul>

            <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                @if ($websiteCollection)
                    @php
                        $collectionStatus = (string) ($websiteCollection->status?->value ?? '');
                        $collectionStatusLabel = match ($collectionStatus) {
                            'completed', 'succeeded' => __('operator.data_sources.collection_status.completed'),
                            'partial' => __('operator.data_sources.collection_status.partial'),
                            'failed' => __('operator.data_sources.collection_status.failed'),
                            'queued', 'pending' => __('operator.data_sources.collection_status.queued'),
                            'running' => __('operator.data_sources.collection_status.running'),
                            default => $collectionStatus,
                        };
                    @endphp
                    <span>{{ __('operator.data_sources.last_collection') }}: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $collectionStatusLabel }}</span></span>
                    <span>{{ $websiteCollection->datasets_completed }}/{{ $websiteCollection->datasets_total }} {{ app()->getLocale() === 'tr' ? 'veri seti tamamlandı' : 'datasets completed' }}</span>
                    @if ((int) $websiteCollection->datasets_failed > 0)
                        <span class="text-error-700 dark:text-error-400">{{ $websiteCollection->datasets_failed }} {{ app()->getLocale() === 'tr' ? 'başarısız' : 'failed' }}</span>
                    @endif
                    <span>{{ $websiteCollection->updated_at?->diffForHumans() }}</span>
                @else
                    {{ app()->getLocale() === 'tr'
                        ? 'Bu web sitesi için henüz veri çekimi yapılmadı.'
                        : 'No production Website collection has been run for this Website yet.' }}
                @endif
            </p>
        </section>
    @endif

    {{-- 4. Who moved an account here or away, and when. --}}
    @if ($transfers->isNotEmpty())
        <section data-ownership-history>
            <div class="section-head">
                <h2 class="section-title">Devir geçmişi <span class="section-count">{{ $transfers->count() }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($transfers as $transfer)
                    <li class="card-row items-start">
                        <div class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">
                            <p>{{ $transfer->summary() }}</p>
                            @if ($transfer->note) <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Not: {{ $transfer->note }}</p>@endif
                            @if ($transfer->mappingSummary()) <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $transfer->mappingSummary() }}</p>@endif
                        </div>
                        <div class="shrink-0 text-right text-xs text-gray-500 dark:text-gray-400">
                            <p class="tabular-nums">{{ $transfer->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                            @if ($transfer->transferredBy) <p class="mt-0.5">{{ $transfer->transferredBy->name }}</p>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('operator_runtime.sources.rule_title') }}</span>{{ __('operator_runtime.sources.rule_body') }}
    </p>
</div>
