<div class="space-y-10">
    <x-operator.asset-context :asset-id="$assetId" current="sources" />
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $asset->name }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" title="{{ __('operator_runtime.sources.description') }}">{{ collect([$customer?->name, $brand?->name, \App\Support\DigitalAssetTypes::options()[$asset->type] ?? $asset->type])->filter()->unique()->implode(', ') }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($canDiscover ? $providers : [] as $provider)
                <button type="button" wire:click="discover('{{ $provider }}')" wire:loading.attr="disabled"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 disabled:opacity-60 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    {{ __('operator_runtime.sources.discover', ['provider' => strtoupper($provider)]) }}
                </button>
            @endforeach
            <button type="button" wire:click="collectNow" @disabled(! $hasCollectableSource) wire:loading.attr="disabled"
                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('operator_runtime.sources.collect_now') }}
            </button>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-lg px-4 py-3 text-sm',
            'tone-critical' => $messageTone === 'error',
            'tone-ok' => $messageTone === 'success',
            'tone-info' => ! in_array($messageTone, ['error', 'success'], true),
        ])>{{ $message }}</div>
    @endif

    @if ($ownershipConflict)
        <x-operator.ownership-transfer-panel :conflict="$ownershipConflict" action="transferResource" :can-transfer="$canTransfer" />
    @endif

    @if ($isWebsite)
        <section>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="section-title">
                            {{ app()->getLocale() === 'tr' ? 'Web sitesinin kendi kaynakları' : 'Website’s own sources' }}
                        </h2>
                    </div>
                    <p class="sr-only">
                        {{ app()->getLocale() === 'tr'
                            ? 'Bu kaynaklar için Google/Meta hesabı gerekmez. Web sitesinin adresi tanımlıysa sayfa taraması, HTML analizi ve SSL kontrolü doğrudan çalışır.'
                            : 'These sources are independent from Google/Meta resource bindings. With a Website primary URL/domain, public crawl, HTML analysis and SSL/TLS can run directly.' }}
                    </p>
                </div>
                <a href="{{ route('operator.integrations.website') }}" wire:navigate class="link-neutral text-sm">
                    {{ app()->getLocale() === 'tr' ? 'Website Entegrasyon Merkezi' : 'Website Integration Center' }}
                </a>
            </div>

            <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($websiteSources as $source)
                    @php
                        $sourceReady = (bool) ($source['ready'] ?? false);
                        $sourceStatus = (string) ($source['status'] ?? '');
                        $sourceStatusLabel = match ($sourceStatus) {
                            'ready' => app()->getLocale() === 'tr' ? 'Hazır' : 'Ready',
                            'url_required' => app()->getLocale() === 'tr' ? 'URL gerekli' : 'URL required',
                            'domain_required' => app()->getLocale() === 'tr' ? 'Domain gerekli' : 'Domain required',
                            'connection_required' => app()->getLocale() === 'tr' ? 'Bağlantı gerekli' : 'Connection required',
                            'plugin_required' => app()->getLocale() === 'tr' ? 'WordPress · eklenti kurulmalı' : 'WordPress · plugin required',
                            'not_wordpress' => app()->getLocale() === 'tr' ? 'WordPress değil · yalnız elle / herkese açık tarama' : 'Not WordPress · manual public crawl only',
                            default => $sourceStatus,
                        };
                    @endphp
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['name'] }}</p>
                        <x-ta.pill :tone="$sourceReady ? 'ok' : (str_contains($sourceStatus, 'required') ? 'look' : 'info')">{{ $sourceStatusLabel }}</x-ta.pill>
                    </li>
                @endforeach
            </ul>

            <div class="mt-3 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
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
            </div>
        </section>
    @endif

    @if ($capabilities !== [])
        <div class="grid gap-10 xl:grid-cols-2">
            @foreach ($capabilities as $capability)
                @php
                    $binding = $bindings->get($capability);
                    $current = $binding?->externalResource;
                    $available = $resources[$capability] ?? collect();
                    $active = $binding && $binding->status === \App\Models\CoreAssetBinding::STATUS_ACTIVE;
                @endphp

                <section>
                    <div class="flex items-start justify-between gap-3 border-b border-gray-200 pb-2 dark:border-gray-800">
                        <h2 class="section-title" title="{{ __('operator_runtime.sources.capability_help') }}">{{ \App\Support\Integrations\ProviderRegistry::capabilityLabel($capability) }}</h2>
                        <x-ta.pill :tone="$active ? 'ok' : 'info'">{{ $active ? __('operator_runtime.sources.connected') : __('operator_runtime.sources.not_connected') }}</x-ta.pill>
                    </div>

                    <div class="space-y-4 pt-3">
                        @if ($active && $current)
                            <div>
                                <p class="text-xs text-gray-500">{{ __('operator_runtime.sources.current_resource') }}</p>
                                <p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $current->display_name ?: $current->external_id }}</p>
                                <p class="mt-1 break-all text-xs text-gray-500">{{ $current->external_id }}</p>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                    <span>{{ __('operator_runtime.sources.provider') }}: {{ strtoupper((string) $current->integration?->provider) }}</span>
                                    <span>{{ __('operator_runtime.sources.resource_status') }}: {{ $current->status }}</span>
                                </div>
                                @php
                                    $automation = $automations->get($current->id);
                                @endphp
                                @if ($automation)
                                    <p @class(['mt-3 text-xs', 'text-error-700 dark:text-error-300' => $automation->collection_status === 'attention', 'text-gray-500' => $automation->collection_status !== 'attention'])>
                                        Otomatik veri çekimi: {{ $automation->collection_enabled ? ($automation->collection_status === 'attention' ? 'durdu' : 'açık') : 'kapalı' }}
                                        <span class="ml-2">son başarılı:</span> {{ $automation->last_collection_success_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'henüz yok' }}
                                        @if ($automation->collection_status === 'attention' && $automation->collection_error). {{ __('resource-auto.'.$automation->collection_error) }}. <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="underline">Sistem Sağlığı</a>@endif
                                    </p>
                                @endif
                                @php $write = $lastWrites[(int) $current->id] ?? null; @endphp
                                @if ($write !== null)
                                    @php $n = fn (int $v): string => number_format($v, 0, ',', '.'); @endphp
                                    <p class="mt-1 text-xs text-gray-500" data-last-write>
                                        Son çekim {{ $write['at']?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}: {{ $n($write['inserted']) }} yeni satır · {{ $n($write['updated']) }} güncellendi · {{ $n($write['unchanged']) }} değişmedi.
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
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ app()->getLocale() === 'tr' ? 'Google’dan çekilecek veriler' : 'Data collected from Google' }} <span class="font-normal text-gray-500">{{ count($gbpDatasets) }}</span>
                                </p>
                                <p class="mt-1 text-xs text-gray-500">{{ implode(', ', $gbpDatasets) }}</p>
                            </div>
                        @endif

                        @if ($canDiscover)
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $active ? __('operator_runtime.sources.change_resource') : __('operator_runtime.sources.select_resource_label') }}</label>
                            <select wire:model="selectedResource.{{ $capability }}"
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white">
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
                                <p class="mt-1 text-xs text-error-600">{{ $message }}</p>
                            @enderror
                            @if ($available->isEmpty() && ($ownedElsewhere[$capability] ?? []) === [])
                                <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">{{ __('operator_runtime.sources.no_resources') }}</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="bind('{{ $capability }}')"
                                class="rounded-lg px-3 py-2 text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-600 dark:hover:bg-white/5">
                                {{ $active ? __('operator_runtime.sources.change') : __('operator_runtime.sources.connect') }}
                            </button>
                            @if ($active)
                                <button type="button" wire:click="disable('{{ $capability }}')"
                                    class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                    {{ __('operator_runtime.sources.disconnect') }}
                                </button>
                            @endif
                        </div>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    @if ($transfers->isNotEmpty())
        <section data-ownership-history>
            <h2 class="section-title">Devir geçmişi</h2>
            <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 text-sm text-gray-600 dark:divide-gray-800 dark:border-gray-800 dark:text-gray-300">
                @foreach ($transfers as $transfer)
                    <li class="py-2.5">
                        <span class="mr-2 text-xs text-gray-500">{{ $transfer->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>
                        {{ $transfer->summary() }}
                        @if ($transfer->transferredBy) <span class="text-xs text-gray-500">{{ $transfer->transferredBy->name }}</span>@endif
                        @if ($transfer->note) <span class="block text-xs text-gray-500">Not: {{ $transfer->note }}</span>@endif
                        @if ($transfer->mappingSummary()) <span class="block text-xs text-gray-500">{{ $transfer->mappingSummary() }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="text-xs text-gray-500 dark:text-gray-400">
        <strong class="font-medium text-gray-700 dark:text-gray-300">{{ __('operator_runtime.sources.rule_title') }}</strong>
        <span>{{ __('operator_runtime.sources.rule_body') }}</span>
    </section>
</div>
