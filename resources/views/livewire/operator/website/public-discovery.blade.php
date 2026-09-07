<div class="space-y-6" @if(in_array($runtime['run']?->status, ['queued', 'running'], true)) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 pb-5 dark:border-gray-800">
        <div>
            <a href="{{ route('operator.public-discovery') }}" wire:navigate class="text-sm text-brand-600">{{ __('operator_runtime.discovery.title') }}</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $brand?->name }} · {{ $asset->name }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $asset->primary_url ?: $asset->domain }}</p>
            <p class="mt-3 max-w-3xl text-sm text-gray-700 dark:text-gray-300">{{ __('public_discovery.intro') }}</p>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">{{ __('public_discovery.how') }}</p>
        </div>
        <button type="button" wire:click="runDiscovery" wire:loading.attr="disabled" @disabled(in_array($runtime['run']?->status, ['queued', 'running'], true))
            class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            {{ __('operator_runtime.discovery.start') }}
        </button>
    </div>

    @if($statusMessage !== '')
        <p role="status" class="rounded-xl border p-4 text-sm {{ $statusTone === 'error' ? 'border-rose-300 text-rose-700' : 'border-brand-200 text-brand-700 dark:text-brand-300' }}">{{ $statusMessage }}</p>
    @endif
    @if($errors->any() && $selectedCandidate === null)
        <p role="alert" class="text-sm text-rose-600">{{ $errors->first() }}</p>
    @endif
    @if($runtime['run'])
        <div class="flex flex-wrap justify-between gap-2 rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-700">
            <p>{{ __('operator_runtime.discovery.latest_run') }} #{{ $runtime['run']->id }} · {{ $runtime['phase'] }}</p>
            <a href="{{ route('operator.activity') }}" wire:navigate class="text-brand-600">{{ __('operator_runtime.discovery.open_activity') }} →</a>
            @if($runtime['failure'])<p class="w-full text-rose-600">{{ $runtime['failure'] }}</p>@endif
            @if(data_get($runtime['run']->metadata, 'source_collection_run_id'))
                <a href="{{ route('operator.integrations.website', ['assetId' => $asset->id]) }}" wire:navigate class="text-brand-600">
                    {{ __('public_discovery.source_collection') }} #{{ data_get($runtime['run']->metadata, 'source_collection_run_id') }}
                    @if($collectionStatus = data_get($runtime['run']->metadata, 'source_collection_status')) · {{ __('public_discovery.collection.'.$collectionStatus) }} @endif
                </a>
            @endif
        </div>
    @endif

    <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
        <h2 class="font-semibold">{{ __('public_discovery.coverage') }}</h2>
        @if($coverage = $discovery['coverage'])
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach(['inventory_urls' => 'inventory', 'inspected_pages' => 'inspected', 'missing_html_urls' => 'missing', 'uninspected_pages' => 'uninspected'] as $key => $label)
                    <div><p class="text-xs text-gray-500">{{ __('public_discovery.'.$label) }}</p><p class="mt-1 text-2xl font-semibold">{{ $coverage[$key] ?? 0 }}</p></div>
                @endforeach
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $discovery['status_label'] }} · {{ __('public_discovery.stale') }}: {{ $coverage['stale_urls'] ?? 0 }} · {{ __('public_discovery.unreadable') }}: {{ $coverage['unreadable_pages'] ?? 0 }} · {{ __('public_discovery.ineligible') }}: {{ $coverage['ineligible_pages'] ?? 0 }}</p>
            @if($coverage['oldest_observation'] ?? null)
                <p class="text-xs text-gray-500">{{ __('public_discovery.observed') }}: {{ $coverage['oldest_observation'] }} — {{ $coverage['latest_observation'] }}</p>
            @endif
            <p class="text-xs text-gray-500">{{ __('public_discovery.coverage_note') }}</p>
            @if($coverage['gap_samples'] ?? [])
                <details class="text-xs text-gray-500"><summary class="cursor-pointer">{{ __('public_discovery.gaps') }}</summary>
                    <ul class="mt-2 space-y-2">@foreach($coverage['gap_samples'] as $gap)<li><span class="break-all">{{ $gap['url'] }}</span> · {{ __('public_discovery.'.$gap['reason']) }}</li>@endforeach</ul>
                </details>
            @endif
        @elseif($discovery['last_run'])
            <p class="text-sm text-amber-700">{{ __('public_discovery.legacy') }} · {{ __('public_discovery.inspected') }}: {{ $discovery['pages_inspected'] }}</p>
        @else
            <p class="text-sm text-gray-500">{{ __('public_discovery.no_data') }}</p>
        @endif
        <p class="text-xs text-gray-500">{{ __('public_discovery.freshness') }}</p>
    </section>

    @if($selectedCandidate)
        <section x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))" aria-label="{{ __('public_discovery.review') }}" class="space-y-4 rounded-xl border-2 border-brand-400 bg-white p-5 dark:bg-gray-900" wire:key="review-{{ $selectedCandidate->id }}">
            <div class="flex justify-between gap-3"><h2 class="font-semibold">{{ __('public_discovery.review') }}</h2><button type="button" wire:click="closeReview" class="text-sm text-gray-500">{{ __('public_discovery.cancel') }}</button></div>
            <p class="text-sm">{{ __('public_discovery.destination') }}: <strong>{{ __('public_discovery.field.'.$selectedCandidate->target_field) }}</strong></p>
            <form wire:submit="applyReview" class="space-y-4">
                <label class="block text-sm">{{ __('public_discovery.edit') }}<textarea wire:model="editedValue" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border-gray-300 bg-transparent"></textarea></label>
                @if($selectedCandidate->target_field === 'products_services')
                    <label class="block text-sm">{{ __('public_discovery.map_service') }}
                        <select wire:model="offeringId" class="mt-1 block w-full rounded-lg border-gray-300 bg-transparent"><option value="">{{ __('public_discovery.new_service') }}</option>
                            @foreach($offerings as $offering)<option value="{{ $offering->id }}">{{ $offering->primaryName?->raw_label }} (#{{ $offering->id }})</option>@endforeach
                        </select>
                    </label>
                    <p class="text-xs text-gray-500">{{ __('public_discovery.service_help') }}</p>
                @endif
                @if(in_array($selectedCandidate->target_field, ['physical_addresses', 'service_areas', 'target_markets'], true))
                    <p class="text-sm text-gray-500">{{ __('public_discovery.area_help') }}</p>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="confirmServiceArea" />{{ __('public_discovery.confirm_area') }}</label>
                    @if($confirmServiceArea)
                        <label class="block text-sm">{{ __('public_discovery.map_area') }}<select wire:model.live="serviceAreaId" class="mt-1 block w-full rounded-lg border-gray-300 bg-transparent"><option value="">{{ __('public_discovery.new_area') }}</option>
                            @foreach($serviceAreas as $area)<option value="{{ $area->id }}">{{ $area->label() }}</option>@endforeach
                        </select></label>
                        @if(!$serviceAreaId)
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="text-sm">{{ __('public_discovery.country') }}<select wire:model="countryCode" class="mt-1 block w-full rounded-lg border-gray-300 bg-transparent"><option value="">{{ __('public_discovery.choose') }}</option>@foreach(\App\Support\Options\CountryOptions::options() as $code => $country)<option value="{{ $code }}">{{ $country }}</option>@endforeach</select></label>
                                <label class="text-sm">{{ __('public_discovery.city') }}<input wire:model="cityName" maxlength="120" class="mt-1 block w-full rounded-lg border-gray-300 bg-transparent" /></label>
                                <label class="text-sm">{{ __('public_discovery.district') }}<input wire:model="districtName" maxlength="120" class="mt-1 block w-full rounded-lg border-gray-300 bg-transparent" /></label>
                            </div>
                        @endif
                    @endif
                @endif
                @if($currentValue !== '')
                    <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800"><p class="font-semibold">{{ __('public_discovery.current') }}</p><p>{{ $currentValue }}</p></div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="replaceExisting" />{{ __('public_discovery.replace') }}</label>
                    <p class="text-xs text-gray-500">{{ __('public_discovery.keep') }}</p>
                @endif
                @if($errors->any())<ul role="alert" class="text-sm text-rose-600">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ __('public_discovery.save') }}</button>
            </form>
        </section>
    @endif

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <div class="space-y-3 border-b border-gray-200 p-5 dark:border-gray-700">
            <h2 class="font-semibold">{{ __('public_discovery.candidates') }}</h2>
            <div class="flex flex-wrap gap-2">
                @foreach(['pending', 'accepted', 'ignored', 'unapplied', 'all'] as $state)
                    <button type="button" wire:click="$set('filter', '{{ $state }}')" class="rounded-lg px-3 py-2 text-xs {{ $filter === $state ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' }}">
                        {{ __('public_discovery.'.$state) }} @if(isset($candidateCounts[$state])) ({{ $candidateCounts[$state] }}) @endif
                    </button>
                @endforeach
            </div>
        </div>
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            @forelse($candidates as $candidate)
                @php($receipt = data_get($candidate->support_json, 'application'))
                <article class="space-y-3 p-5" wire:key="candidate-{{ $candidate->id }}">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium text-gray-500">{{ __('public_discovery.field.'.$candidate->target_field) }} · {{ __('public_discovery.'.$candidate->status) }}</p>
                            <p class="mt-2 break-words font-medium">{{ $candidate->accepted_value ?? $candidate->proposed_value }}</p>
                        </div>
                        @if(!$receipt || in_array($receipt['state'] ?? '', ['conflict', 'observation_only'], true))
                            <div class="flex items-start gap-3">
                                <button type="button" wire:click="reviewCandidate({{ $candidate->id }})" class="rounded-lg border border-brand-300 px-3 py-2 text-xs font-semibold text-brand-600">{{ __('public_discovery.review') }}</button>
                                @if($candidate->status === 'pending')<button type="button" wire:click="ignoreCandidate({{ $candidate->id }})" wire:loading.attr="disabled" class="px-2 py-2 text-xs text-gray-500">{{ __('operator_runtime.discovery.ignore') }}</button>@endif
                            </div>
                        @endif
                    </div>
                    @if($candidate->candidate_kind === 'inference')<p class="text-xs text-amber-700">{{ __('public_discovery.inference') }}</p>
                    @elseif(data_get($candidate->support_json, 'normalization_version') !== 'website-public-discovery-v3-stored')<p class="text-xs text-amber-700">{{ __('public_discovery.legacy') }}</p>
                    @else<p class="text-xs text-gray-500">{{ __('public_discovery.source_claim') }}</p>@endif
                    @if($receipt)
                        <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800">
                            <p>{{ __('public_discovery.receipt.'.$receipt['state']) }}</p>
                            @if($receipt['record_id'] ?? null)<p class="mt-1 text-xs text-gray-500">#{{ $receipt['record_id'] }} · {{ $receipt['label'] ?? '' }}</p>@endif
                            @if($url = $candidate->applicationUrl())<a href="{{ $url }}" wire:navigate class="mt-1 inline-block text-brand-600">{{ __('public_discovery.open_destination') }} →</a>@endif
                        </div>
                    @elseif($candidate->status === 'accepted')<p class="text-sm text-amber-700">{{ __('public_discovery.legacy_accepted') }}</p>@endif
                    @php($sources = data_get($candidate->support_json, 'sources', [['url' => data_get($candidate->support_json, 'source_url'), 'observed_at' => data_get($candidate->support_json, 'retrieved_at')]]))
                    <details class="text-xs text-gray-500">
                        <summary class="cursor-pointer">{{ __('public_discovery.sources') }} ({{ count($sources) }})</summary>
                        <ul class="mt-2 space-y-3">
                            @foreach($sources as $source)
                                <li>
                                    @if(is_string($source['url'] ?? null) && preg_match('~^https?://~i', $source['url']))<a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-600">{{ $source['url'] }}</a>@endif
                                    <p>{{ __('public_discovery.observed') }}: {{ $source['observed_at'] ?? '—' }}</p>
                                    @if($source['excerpt'] ?? null)<p class="mt-1 break-words">{{ $source['excerpt'] }}</p>@endif
                                    @if($source['raw_ingestion_object_id'] ?? null)<a href="{{ route('operator.website.html.show', ['assetId' => $asset->id, 'rawObjectId' => $source['raw_ingestion_object_id']]) }}" target="_blank" rel="noopener" class="text-brand-600">{{ __('public_discovery.html') }}</a>@endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </article>
            @empty
                <p class="p-8 text-sm text-gray-500">{{ __('public_discovery.empty') }}</p>
            @endforelse
        </div>
        <div class="p-5">{{ $candidates->links() }}</div>
    </section>
    <details class="rounded-xl border border-gray-200 p-4 text-xs text-gray-500 dark:border-gray-700">
        <summary class="cursor-pointer">{{ __('public_discovery.runtime') }}</summary>
        <p class="mt-3">{{ __('operator_runtime.discovery.health_status.'.$runtime['worker_status_key']) }}</p>
        <p>{{ __('operator_runtime.discovery.worker_health.'.$runtime['worker_health_key'], $runtime['worker_health_replace']) }}</p>
        <p>{{ __('operator_runtime.discovery.queue_health.'.$runtime['queue_health_key'], $runtime['queue_health_replace']) }}</p>
    </details>
</div>
