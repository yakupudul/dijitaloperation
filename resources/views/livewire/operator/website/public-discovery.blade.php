{{-- Site keşfi: which facts found on the public site should go into the brand file; coverage and runtime below. --}}
@php
    $field = 'mt-1 block h-9 w-full rounded-lg border border-gray-200 bg-white px-2.5 text-sm text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
@endphp
<div class="min-w-0 space-y-10" @if(in_array($runtime['run']?->status, ['queued', 'running'], true)) wire:poll.5s @endif>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('operator.public-discovery') }}" wire:navigate class="link-neutral text-[13px]">{{ __('operator_runtime.discovery.title') }}</a>
            <h1 class="page-title mt-1.5 [overflow-wrap:anywhere]" title="{{ __('public_discovery.intro') }} {{ __('public_discovery.how') }}">{{ $asset->name }}</h1>
            <p class="page-sub [overflow-wrap:anywhere]">@if ($brand){{ $brand->name }}, @endif{{ $asset->primary_url ?: $asset->domain }}</p>
            <p class="sr-only">{{ __('public_discovery.intro') }} {{ __('public_discovery.how') }}</p>
        </div>
        <button type="button" wire:click="runDiscovery" wire:loading.attr="disabled" @disabled(in_array($runtime['run']?->status, ['queued', 'running'], true))
            class="btn-primary w-full sm:w-auto">
            {{ __('operator_runtime.discovery.start') }}
        </button>
    </div>

    @if($statusMessage !== '')
        <p role="status" @class(['rounded-lg border bg-white px-4 py-2.5 text-sm shadow-card dark:bg-gray-900', 'border-error-200 text-error-700 dark:border-error-500/30 dark:text-error-400' => $statusTone === 'error', 'border-gray-200 text-gray-800 dark:border-gray-800 dark:text-gray-200' => $statusTone !== 'error'])>{{ $statusMessage }}</p>
    @endif
    @if($errors->any() && $selectedCandidate === null)
        <p role="alert" class="text-sm text-error-600">{{ $errors->first() }}</p>
    @endif
    @if($runtime['run'])
        <div class="-mt-6 flex flex-wrap justify-between gap-x-4 gap-y-1 text-[13px] text-gray-500 dark:text-gray-400">
            <p>{{ __('operator_runtime.discovery.latest_run') }} #{{ $runtime['run']->id }}, {{ $runtime['phase'] }}</p>
            @if($runtime['failure'])<p class="w-full text-error-600">{{ $runtime['failure'] }}</p>@endif
            @if(data_get($runtime['run']->metadata, 'source_collection_run_id'))
                <a href="{{ route('operator.integrations.website', ['assetId' => $asset->id]) }}" wire:navigate class="link-neutral">
                    {{ __('public_discovery.source_collection') }} #{{ data_get($runtime['run']->metadata, 'source_collection_run_id') }}
                    @if($collectionStatus = data_get($runtime['run']->metadata, 'source_collection_status')), {{ __('public_discovery.collection.'.$collectionStatus) }} @endif
                </a>
            @endif
        </div>
    @endif

    @if($selectedCandidate)
        <section x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))" aria-label="{{ __('public_discovery.review') }}" class="card space-y-4 p-5" wire:key="review-{{ $selectedCandidate->id }}">
            <div class="flex items-baseline justify-between gap-3"><h2 class="section-title">{{ __('public_discovery.review') }}</h2><button type="button" wire:click="closeReview" class="btn-quiet">{{ __('public_discovery.cancel') }}</button></div>
            <p class="text-sm">{{ __('public_discovery.destination') }}: <strong>{{ __('public_discovery.field.'.$selectedCandidate->target_field) }}</strong></p>
            <form wire:submit="applyReview" class="space-y-4">
                <label class="block text-[13px] text-gray-600 dark:text-gray-400">{{ __('public_discovery.edit') }}<textarea wire:model="editedValue" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-2.5 py-2 text-sm text-gray-800 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white"></textarea></label>
                @if($selectedCandidate->target_field === 'products_services')
                    <label class="block text-[13px] text-gray-600 dark:text-gray-400">{{ __('public_discovery.map_service') }}
                        <select wire:model="offeringId" class="{{ $field }}"><option value="">{{ __('public_discovery.new_service') }}</option>
                            @foreach($offerings as $offering)<option value="{{ $offering->id }}">{{ $offering->primaryName?->raw_label }} (#{{ $offering->id }})</option>@endforeach
                        </select>
                    </label>
                    <p class="text-xs text-gray-500">{{ __('public_discovery.service_help') }}</p>
                @endif
                @if(in_array($selectedCandidate->target_field, ['physical_addresses', 'service_areas', 'target_markets'], true))
                    <p class="text-[13px] text-gray-500">{{ __('public_discovery.area_help') }}</p>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" class="rounded border-gray-300 text-gray-900 focus:ring-gray-300 dark:border-gray-700" wire:model.live="confirmServiceArea" />{{ __('public_discovery.confirm_area') }}</label>
                    @if($confirmServiceArea)
                        <label class="block text-[13px] text-gray-600 dark:text-gray-400">{{ __('public_discovery.map_area') }}<select wire:model.live="serviceAreaId" class="{{ $field }}"><option value="">{{ __('public_discovery.new_area') }}</option>
                            @foreach($serviceAreas as $area)<option value="{{ $area->id }}">{{ $area->label() }}</option>@endforeach
                        </select></label>
                        @if(!$serviceAreaId)
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="text-[13px] text-gray-600 dark:text-gray-400">{{ __('public_discovery.country') }}<select wire:model.live="countryCode" class="{{ $field }}"><option value="">{{ __('public_discovery.choose') }}</option>@foreach(\App\Support\Options\CountryOptions::options() as $code => $country)<option value="{{ $code }}">{{ $country }}</option>@endforeach</select></label>
                                @if ($countryCode === 'TR')
                                    <label class="text-[13px] text-gray-600 dark:text-gray-400">Şehir<select wire:model.live="cityName" class="{{ $field }}"><option value="">Tüm Türkiye</option>@foreach(\App\Support\Options\LocationOptions::cities() as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select></label>
                                    <label class="text-[13px] text-gray-600 dark:text-gray-400">İlçe<select wire:model="districtName" @disabled($cityName === '') class="{{ $field }}"><option value="">Tüm şehir</option>@foreach(\App\Support\Options\LocationOptions::districts($cityName) as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select></label>
                                @else
                                <label class="text-[13px] text-gray-600 dark:text-gray-400">{{ __('public_discovery.city') }}<input wire:model="cityName" maxlength="120" class="{{ $field }}" /></label>
                                <label class="text-[13px] text-gray-600 dark:text-gray-400">{{ __('public_discovery.district') }}<input wire:model="districtName" maxlength="120" class="{{ $field }}" /></label>
                                @endif
                            </div>
                        @endif
                    @endif
                @endif
                @if($currentValue !== '')
                    <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-sm dark:bg-white/[0.03]"><p class="text-xs text-gray-500">{{ __('public_discovery.current') }}</p><p class="mt-0.5 break-words text-gray-800 dark:text-gray-200">{{ $currentValue }}</p></div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" class="rounded border-gray-300 text-gray-900 focus:ring-gray-300 dark:border-gray-700" wire:model="replaceExisting" />{{ __('public_discovery.replace') }}</label>
                    <p class="text-xs text-gray-500">{{ __('public_discovery.keep') }}</p>
                @endif
                @if($errors->any())<ul role="alert" class="text-sm text-error-600">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
                <button type="submit" wire:loading.attr="disabled" class="btn-primary w-full sm:w-auto">{{ __('public_discovery.save') }}</button>
            </form>
        </section>
    @endif

    <section class="min-w-0">
        <div class="section-head justify-between gap-y-2">
            <h2 class="section-title">{{ __('public_discovery.candidates') }}</h2>
            <div class="no-scrollbar inline-flex max-w-full overflow-x-auto rounded-lg border border-gray-200 bg-white p-0.5 shadow-card dark:border-gray-700 dark:bg-gray-900">
                @foreach(['pending', 'accepted', 'ignored', 'unapplied', 'all'] as $state)
                    <button type="button" wire:click="$set('filter', '{{ $state }}')" class="h-7 shrink-0 whitespace-nowrap rounded-md px-2.5 text-[13px] {{ $filter === $state ? 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}">
                        {{ __('public_discovery.'.$state) }}@if(isset($candidateCounts[$state])) <span class="tabular-nums text-gray-400">{{ $candidateCounts[$state] }}</span>@endif
                    </button>
                @endforeach
            </div>
        </div>
        <div class="card">
            @forelse($candidates as $candidate)
                @php($receipt = data_get($candidate->support_json, 'application'))
                <article class="space-y-1.5 border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="candidate-{{ $candidate->id }}">
                    <div class="flex flex-wrap justify-between gap-x-4 gap-y-2 sm:flex-nowrap">
                        <div class="min-w-0 flex-[1_1_16rem]">
                            <p class="text-xs text-gray-500">{{ __('public_discovery.field.'.$candidate->target_field) }}, {{ __('public_discovery.'.$candidate->status) }}</p>
                            <p class="mt-0.5 break-words text-sm font-medium text-gray-900 dark:text-white">{{ $candidate->accepted_value ?? $candidate->proposed_value }}</p>
                        </div>
                        @if(!$receipt || in_array($receipt['state'] ?? '', ['conflict', 'observation_only'], true))
                            <div class="flex w-full items-start gap-1 sm:w-auto">
                                <button type="button" wire:click="reviewCandidate({{ $candidate->id }})" class="btn h-7! flex-1 px-2.5! text-xs! sm:flex-none">{{ __('public_discovery.review') }}</button>
                                @if($candidate->status === 'pending')<button type="button" wire:click="ignoreCandidate({{ $candidate->id }})" wire:loading.attr="disabled" class="btn-quiet h-7! px-2! text-xs!">{{ __('operator_runtime.discovery.ignore') }}</button>@endif
                            </div>
                        @endif
                    </div>
                    @if($candidate->candidate_kind === 'inference')<p class="text-xs text-warning-700 dark:text-warning-300">{{ __('public_discovery.inference') }}</p>
                    @elseif(data_get($candidate->support_json, 'normalization_version') !== 'website-public-discovery-v3-stored')<p class="text-xs text-warning-700 dark:text-warning-300">{{ __('public_discovery.legacy') }}</p>
                    @else<p class="text-xs text-gray-500">{{ __('public_discovery.source_claim') }}</p>@endif
                    @if($receipt)
                        <div class="text-[13px] text-gray-700 dark:text-gray-300">
                            <p>{{ __('public_discovery.receipt.'.$receipt['state']) }}</p>
                            @if($receipt['record_id'] ?? null)<p class="mt-1 text-xs text-gray-500">#{{ $receipt['record_id'] }}, {{ $receipt['label'] ?? '' }}</p>@endif
                            @if($url = $candidate->applicationUrl())<a href="{{ $url }}" wire:navigate class="link-neutral mt-1 inline-block">{{ __('public_discovery.open_destination') }}</a>@endif
                        </div>
                    @elseif($candidate->status === 'accepted')<p class="text-[13px] text-warning-700 dark:text-warning-300">{{ __('public_discovery.legacy_accepted') }}</p>@endif
                    @php($sources = data_get($candidate->support_json, 'sources', [['url' => data_get($candidate->support_json, 'source_url'), 'observed_at' => data_get($candidate->support_json, 'retrieved_at')]]))
                    <details class="text-xs text-gray-500">
                        <summary class="cursor-pointer hover:text-gray-900 dark:hover:text-white">{{ __('public_discovery.sources') }} ({{ count($sources) }})</summary>
                        <ul class="mt-2 space-y-3">
                            @foreach($sources as $source)
                                <li>
                                    @if(is_string($source['url'] ?? null) && preg_match('~^https?://~i', $source['url']))<a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="link-neutral break-all">{{ $source['url'] }}</a>@endif
                                    <p>{{ __('public_discovery.observed') }}: {{ $source['observed_at'] ?? '—' }}</p>
                                    @if($source['excerpt'] ?? null)<p class="mt-1 break-words">{{ $source['excerpt'] }}</p>@endif
                                    @if($source['raw_ingestion_object_id'] ?? null)<a href="{{ route('operator.website.html.show', ['assetId' => $asset->id, 'rawObjectId' => $source['raw_ingestion_object_id']]) }}" target="_blank" rel="noopener" class="link-neutral">{{ __('public_discovery.html') }}</a>@endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </article>
            @empty
                <x-ta.empty-state size="sm" icon="none" :title="__('public_discovery.empty')" />
            @endforelse
        </div>
        @if ($candidates->hasPages())<div class="mt-3">{{ $candidates->links() }}</div>@endif
    </section>
    <section class="min-w-0">
        <div class="section-head"><h2 class="section-title">{{ __('public_discovery.coverage') }}</h2></div>
        @if($coverage = $discovery['coverage'])
            <div class="metric-strip grid-cols-2 lg:grid-cols-4">
                @foreach(['inventory_urls' => 'inventory', 'inspected_pages' => 'inspected', 'missing_html_urls' => 'missing', 'uninspected_pages' => 'uninspected'] as $key => $label)
                    <x-ta.metric-card :label="__('public_discovery.'.$label)" :value="(string) ($coverage[$key] ?? 0)" />
                @endforeach
            </div>
            <p class="mt-3 text-[13px] text-gray-600 dark:text-gray-300">{{ $discovery['status_label'] }}. {{ __('public_discovery.stale') }}: {{ $coverage['stale_urls'] ?? 0 }}, {{ __('public_discovery.unreadable') }}: {{ $coverage['unreadable_pages'] ?? 0 }}, {{ __('public_discovery.ineligible') }}: {{ $coverage['ineligible_pages'] ?? 0 }}</p>
            @if($coverage['oldest_observation'] ?? null)
                <p class="mt-1 text-xs text-gray-500">{{ __('public_discovery.observed') }}: {{ $coverage['oldest_observation'] }} — {{ $coverage['latest_observation'] }}</p>
            @endif
            <p class="mt-1 text-xs text-gray-500">{{ __('public_discovery.coverage_note') }}</p>
            @if($coverage['gap_samples'] ?? [])
                <details class="mt-2 text-xs text-gray-500"><summary class="cursor-pointer hover:text-gray-900 dark:hover:text-white">{{ __('public_discovery.gaps') }}</summary>
                    <ul class="mt-2 space-y-2">@foreach($coverage['gap_samples'] as $gap)<li><span class="break-all">{{ $gap['url'] }}</span> ({{ __('public_discovery.'.$gap['reason']) }})</li>@endforeach</ul>
                </details>
            @endif
        @elseif($discovery['last_run'])
            <p class="text-[13px] text-warning-700 dark:text-warning-300">{{ __('public_discovery.legacy') }}. {{ __('public_discovery.inspected') }}: {{ $discovery['pages_inspected'] }}</p>
        @else
            <div class="card"><x-ta.empty-state size="sm" icon="none" :title="__('public_discovery.no_data')" /></div>
        @endif
        <p class="mt-2 text-xs text-gray-500">{{ __('public_discovery.freshness') }}</p>
    </section>

    <details class="text-xs text-gray-500">
        <summary class="cursor-pointer hover:text-gray-900 dark:hover:text-white">{{ __('public_discovery.runtime') }}</summary>
        <p class="mt-3">{{ __('operator_runtime.discovery.health_status.'.$runtime['worker_status_key']) }}</p>
        <p>{{ __('operator_runtime.discovery.worker_health.'.$runtime['worker_health_key'], $runtime['worker_health_replace']) }}</p>
        <p>{{ __('operator_runtime.discovery.queue_health.'.$runtime['queue_health_key'], $runtime['queue_health_replace']) }}</p>
    </details>
</div>
