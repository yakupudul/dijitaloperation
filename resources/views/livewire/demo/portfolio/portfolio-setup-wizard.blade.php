@php
    $stepLabels = $steps;
@endphp

<div class="mx-auto max-w-4xl space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.setup.eyebrow') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">
                @if ($entry === 'brand') {{ __('operator.setup.title_brand') }}
                @elseif ($entry === 'asset') {{ __('operator.setup.title_asset') }}
                @else {{ __('operator.setup.title_customer') }}
                @endif
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.setup.subtitle') }}</p>
        </div>
    </div>

    <nav aria-label="{{ __('operator.setup.progress') }}" class="overflow-x-auto">
        <ol class="flex min-w-max gap-2">
            @foreach ($stepLabels as $num => $label)
                <li>
                    <button type="button" wire:click="goToStep({{ $num }})"
                        @class([
                            'rounded-lg px-3 py-2 text-left text-sm ring-1 ring-inset transition',
                            'bg-brand-500 text-white ring-brand-500' => $step === $num,
                            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' => $step > $num,
                            'bg-white text-gray-600 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800' => $step < $num,
                        ])
                        @disabled($committed || $num > $step)
                    >
                        <span class="block text-[10px] font-semibold uppercase opacity-70">{{ $num }}</span>
                        <span class="font-medium">{{ $label }}</span>
                        @if ($step > $num)<span class="sr-only">{{ __('operator.setup.completed') }}</span>@endif
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- STEP 1 CUSTOMER --}}
    @if ($step === 1)
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.setup.customer_heading') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.setup.customer_help') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="cust-name" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.forms.customer_name') }}</label>
                    <input id="cust-name" type="text" wire:model="customer_name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                    @error('customer_name') <p class="mt-1 text-xs text-error-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="contact-name" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.setup.contact_name') }}</label>
                    <input id="contact-name" type="text" wire:model="contact_name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                </div>
                <div>
                    <label for="contact-email" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.setup.contact_email') }}</label>
                    <input id="contact-email" type="email" wire:model="contact_email" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                </div>
                <div>
                    <label for="contact-phone" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.setup.contact_phone') }}</label>
                    <input id="contact-phone" type="text" wire:model="contact_phone" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                </div>
                <div>
                    <label for="owner" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.forms.account_owner') }}</label>
                    <select id="owner" wire:model="account_owner" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        <option value="">{{ __('operator.setup.unassigned') }}</option>
                        @foreach ($team as $member)
                            <option value="{{ $member['id'] }}">{{ $member['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>
    @endif

    {{-- STEP 2 BRAND --}}
    @if ($step === 2)
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.setup.brand_heading') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.setup.brand_help') }}</p>
            @if ($customer_name !== '')
                <p class="mt-2 text-xs text-gray-400">{{ __('operator.setup.customer_prefix') }} · {{ $customer_name }}</p>
            @endif
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="brand-name" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.forms.brand_name') }}</label>
                    <input id="brand-name" type="text" wire:model="brand_name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                    @error('brand_name') <p class="mt-1 text-xs text-error-600" role="alert">{{ $message }}</p> @enderror
                    @if ($duplicateBrandWarning !== '')
                        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200" role="status">{{ $duplicateBrandWarning }}</p>
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <label for="website-url" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.setup.website_url') }}</label>
                    <input id="website-url" type="url" wire:model.live="website_url" placeholder="https://www.example.com" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                    @error('website_url') <p class="mt-1 text-xs text-error-600" role="alert">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-400">{{ __('operator.setup.website_url_help') }}</p>
                </div>
                <div>
                    <label for="country" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.setup.primary_market') }}</label>
                    <select id="country" wire:model="primary_country" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        @foreach ($countryOptions as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="language" class="mb-1 block text-xs font-medium text-gray-500">{{ __('operator.setup.primary_language') }}</label>
                    <select id="language" wire:model="primary_language" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        @foreach ($languageOptions as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-1 text-xs font-medium text-gray-500">{{ __('operator.setup.responsible_users') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($team as $member)
                            <label class="inline-flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                                <input type="checkbox" wire:model="responsible_user_ids" value="{{ $member['id'] }}" class="rounded text-brand-500" />
                                {{ $member['name'] }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- STEP 3 ASSETS --}}
    @if ($step === 3)
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.setup.assets_heading') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('operator.setup.assets_help', ['brand' => $brand_name !== '' ? $brand_name : __('operator.setup.this_brand')]) }}</p>
            </div>
            @error('selected_assets') <p class="text-xs text-error-600" role="alert">{{ $message }}</p> @enderror
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($assetOptions as $option)
                    <button type="button" wire:click="toggleAsset('{{ $option['type'] }}')"
                        @class([
                            'rounded-xl p-4 text-left ring-1 ring-inset transition',
                            'bg-brand-50 ring-brand-300 dark:bg-brand-500/10 dark:ring-brand-500/40' => in_array($option['type'], $selected_assets, true),
                            'bg-white ring-gray-200 dark:bg-gray-900 dark:ring-gray-800' => ! in_array($option['type'], $selected_assets, true),
                        ])
                        @disabled(! empty($option['future']))
                    >
                        <div class="flex items-center gap-3">
                            <x-demo.digital-asset-mark :type="$option['logo']" size="md" />
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $option['label'] }}</p>
                                <p class="text-xs text-gray-500">
                                    @if (! empty($option['future'])) {{ __('operator.setup.future_capability') }}
                                    @elseif (in_array($option['type'], $selected_assets, true)) ✓ {{ __('operator.setup.selected') }}
                                    @else {{ __('operator.setup.tap_to_select') }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>
            <p class="text-xs text-gray-400">{{ __('operator.setup.missing_not_error') }}</p>
        </section>
    @endif

    {{-- STEP 4 CONNECT --}}
    @if ($step === 4)
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.setup.connect_heading') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('operator.setup.connect_help') }}</p>
            </div>

            @if (in_array('website', $selected_assets, true))
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark type="website" size="sm" />
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Website</h3>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $website_url !== '' ? $website_url : __('operator.setup.url_later') }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ __('operator.setup.website_infra') }}</p>
                </div>
            @endif

            @foreach ($matchCards as $card)
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <x-demo.digital-asset-mark :type="$card['type'] === 'gbp' ? 'gbp' : $card['type']" size="sm" />
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $card['label'] }}</h3>
                                <p class="text-xs text-gray-500">{{ $card['integration'] }} · {{ $card['integration_state'] }} · {{ __('operator.setup.resources_found', ['count' => count($card['resources'])]) }}</p>
                                @if (! empty($card['blocker']))
                                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ $card['blocker'] }}</p>
                                @endif
                            </div>
                        </div>
                        <button type="button" wire:click="skipProvider('{{ $card['type'] }}')" class="text-xs font-medium text-gray-500 hover:underline">{{ __('operator.setup.skip_for_now') }}</button>
                    </div>
                    @if ($card['skipped'])
                        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ __('operator.setup.skipped_incomplete') }}</p>
                    @else
                        @if (($card['resources'] ?? []) === [])
                            <p class="mt-3 text-sm text-gray-500">{{ __('operator.setup.no_resources') }}</p>
                        @else
                        <ul class="mt-3 space-y-2">
                            @foreach ($card['resources'] as $resource)
                                <li>
                                    <button type="button" wire:click="selectResource('{{ $card['type'] }}', '{{ $resource['id'] }}')"
                                        @class([
                                            'w-full rounded-lg px-3 py-2.5 text-left ring-1 ring-inset transition',
                                            'bg-brand-50 ring-brand-300 dark:bg-brand-500/10 dark:ring-brand-500/40' => ($card['selected'] ?? null) === $resource['id'],
                                            'bg-gray-50 ring-transparent dark:bg-white/[0.03]' => ($card['selected'] ?? null) !== $resource['id'],
                                        ])>
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if (! empty($resource['recommended']))
                                                <span class="text-[10px] font-bold uppercase text-brand-700 dark:text-brand-300">{{ __('operator.setup.recommended') }}</span>
                                            @elseif (! empty($resource['match_signal']))
                                                <span class="text-[10px] font-bold uppercase text-gray-400">{{ __('operator.setup.possible_match') }}</span>
                                            @endif
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</span>
                                            @if (($card['selected'] ?? null) === $resource['id'])
                                                <span class="text-xs font-medium text-brand-700 dark:text-brand-300">{{ __('operator.setup.selected') }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $resource['external_id'] ?? '' }}
                                            @if (! empty($resource['stream'])) · {{ $resource['stream'] }} @endif
                                            @if (! empty($resource['address'])) · {{ $resource['address'] }} @endif
                                        </p>
                                        @if (! empty($resource['match_signal']))
                                            <p class="mt-1 text-xs text-gray-400">{{ $resource['match_signal'] }}</p>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        @endif
                    @endif
                </div>
            @endforeach

            @if (in_array('instagram', $selected_assets, true))
                <div class="rounded-xl bg-white p-4 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    {{ __('operator.setup.instagram_future') }}
                </div>
            @endif
        </section>
    @endif

    {{-- STEP 5 DISCOVER --}}
    @if ($step === 5)
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.setup.review_heading') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('operator.setup.review_help') }}</p>
            </div>

            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.setup.candidate_updates') }}</h3>
                @if ($candidates === [])
                    <p class="mt-3 text-sm text-gray-500">{{ __('operator.setup.no_candidates') }}</p>
                @else
                <ul class="mt-3 space-y-2">
                    @foreach ($candidates as $candidate)
                        @if (($candidate['kind'] ?? '') === 'competitor')
                            @continue
                        @endif
                        <li class="flex flex-wrap items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $candidate['value'] }}</p>
                                <p class="text-xs text-gray-500">{{ $candidate['kind_label'] }} · {{ $candidate['source'] }} · {{ $candidate['provenance'] }}</p>
                            </div>
                            <button type="button" wire:click="toggleCandidate('{{ $candidate['id'] }}')"
                                @class([
                                    'rounded-lg px-3 py-1.5 text-xs font-medium',
                                    'bg-brand-500 text-white' => in_array($candidate['id'], $accepted_candidate_ids, true),
                                    'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => ! in_array($candidate['id'], $accepted_candidate_ids, true),
                                ])>
                                {{ in_array($candidate['id'], $accepted_candidate_ids, true) ? __('operator.setup.selected') : __('operator.setup.add') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
                @endif
                <p class="mt-3 text-xs text-gray-400">{{ __('operator.setup.discovery_empty_note') }}</p>
            </div>

            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.setup.conflicts') }}</h3>
                @if ($conflicts === [])
                    <p class="mt-3 text-sm text-gray-500">{{ __('operator.setup.no_conflicts') }}</p>
                @else
                <ul class="mt-3 space-y-2">
                    @foreach ($conflicts as $conflict)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $conflict['field'] }}</p>
                                <p class="text-xs text-gray-500">{{ $conflict['state'] }} · {{ __('operator.setup.not_batch') }}</p>
                            </div>
                            <button type="button" wire:click="openConflict('{{ $conflict['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review</button>
                        </li>
                    @endforeach
                </ul>
                @endif
            </div>
        </section>
    @endif

    {{-- STEP 6 SUMMARY --}}
    @if ($step === 6)
        <section class="rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ __('operator.setup.ready', ['brand' => $summary['brand']]) }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.setup.incomplete_not_unhealthy') }}</p>
            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex justify-between gap-3 border-b border-gray-100 py-2 dark:border-gray-800"><dt class="text-gray-500">{{ __('operator.setup.steps.customer') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $summary['customer'] }} ✓</dd></div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-2 dark:border-gray-800"><dt class="text-gray-500">{{ __('operator.setup.steps.brand') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $summary['brand'] }} ✓</dd></div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-2 dark:border-gray-800"><dt class="text-gray-500">{{ __('operator.setup.steps.assets') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ __('operator.setup.defined_count', ['count' => $summary['defined_count'] ?? count($summary['assets'])]) }}</dd></div>
                @foreach ($summary['assets'] as $type)
                    <div class="flex justify-between gap-3 border-b border-gray-50 py-1.5 dark:border-gray-800/60">
                        <dt class="text-gray-500">{{ $type }}</dt>
                        <dd class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('operator.states.defined') }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-3 border-b border-gray-100 py-2 dark:border-gray-800"><dt class="text-gray-500">Google</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $summary['google'] ?? __('operator.states.not_configured') }}</dd></div>
                <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">Meta</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $summary['meta'] ?? __('operator.states.not_configured') }}</dd></div>
                @if ($summary['conflicts_open'] > 0)
                    <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">{{ __('operator.setup.review_remaining') }}</dt><dd class="font-medium text-amber-700 dark:text-amber-300">{{ __('operator.setup.conflicts_open', ['count' => $summary['conflicts_open']]) }}</dd></div>
                @endif
            </dl>
            <div class="mt-6 flex flex-wrap gap-2">
                <a href="{{ route('operator.brand', ['brand' => $summary['brand_id']]) }}" wire:navigate class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">{{ __('operator.setup.open_brand') }}</a>
                <a href="{{ route('operator.dashboard') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.setup.go_dashboard') }}</a>
                @if ($summary['conflicts_open'] > 0)
                    <a href="{{ route('operator.brand', ['brand' => $summary['brand_id'], 'tab' => 'discovery', 'discovery' => 'conflicts']) }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('operator.setup.review_remaining_setup') }}</a>
                @endif
            </div>
        </section>
    @endif

    @if ($step < 6)
        <div class="sticky bottom-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white/95 p-3 shadow-lg ring-1 ring-inset ring-gray-200 backdrop-blur dark:bg-gray-900/95 dark:ring-gray-800">
            <button type="button" wire:click="back" @disabled($step <= ($entry === 'asset' ? 3 : ($entry === 'brand' ? 2 : 1))) class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 disabled:opacity-40 dark:text-gray-300">{{ __('operator.forms.back') }}</button>
            <button type="button" wire:click="next" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">
                {{ $step === 5 ? __('operator.setup.finish') : __('operator.setup.continue') }}
            </button>
        </div>
    @endif

    @if ($reviewConflict)
        <div class="fixed inset-0 z-50 flex justify-end bg-gray-900/40" role="dialog" aria-modal="true" wire:click="closeConflict">
            <div class="flex h-full w-full max-w-lg flex-col overflow-y-auto bg-white p-5 shadow-xl dark:bg-gray-900" wire:click.stop>
                <div class="flex items-start justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $reviewConflict['field'] }}</h2>
                    <button type="button" wire:click="closeConflict" aria-label="{{ __('operator.setup.close') }}">✕</button>
                </div>
                <div class="mt-4 space-y-2">
                    @foreach ($reviewConflict['values'] as $value)
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <p class="text-[11px] uppercase text-gray-400">{{ $value['source'] }}</p>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $value['value'] }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'keep_canonical')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">{{ __('operator.setup.keep_canonical') }}</button>
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'ignore')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.setup.ignore_difference') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
