@php
    $stepLabels = $steps;
@endphp

<div class="mx-auto max-w-4xl space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Portfolio Setup Wizard</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">
                @if ($entry === 'brand') Add Brand
                @elseif ($entry === 'asset') Add Digital Assets
                @else New Customer setup
                @endif
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Create a clean Customer → Brand → Digital Estate in minutes. Discover resources, confirm bindings — no manual provider IDs under normal circumstances.</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
    </div>

    <nav aria-label="Setup progress" class="overflow-x-auto">
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
                        @if ($step > $num)<span class="sr-only">Completed</span>@endif
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- STEP 1 CUSTOMER --}}
    @if ($step === 1)
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Customer</h2>
            <p class="mt-1 text-sm text-gray-500">Minimal agency relationship — not a CRM dump.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="cust-name" class="mb-1 block text-xs font-medium text-gray-500">Customer name</label>
                    <input id="cust-name" type="text" wire:model="customer_name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                    @error('customer_name') <p class="mt-1 text-xs text-error-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="contact-name" class="mb-1 block text-xs font-medium text-gray-500">Primary contact name</label>
                    <input id="contact-name" type="text" wire:model="contact_name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                </div>
                <div>
                    <label for="contact-email" class="mb-1 block text-xs font-medium text-gray-500">Primary contact email</label>
                    <input id="contact-email" type="email" wire:model="contact_email" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                </div>
                <div>
                    <label for="contact-phone" class="mb-1 block text-xs font-medium text-gray-500">Primary contact phone</label>
                    <input id="contact-phone" type="text" wire:model="contact_phone" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                </div>
                <div>
                    <label for="owner" class="mb-1 block text-xs font-medium text-gray-500">Account owner</label>
                    <select id="owner" wire:model="account_owner" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
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
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Brand</h2>
            <p class="mt-1 text-sm text-gray-500">Initial setup only — full Brand Intelligence Context comes later.</p>
            @if ($customer_name !== '')
                <p class="mt-2 text-xs text-gray-400">Customer · {{ $customer_name }}</p>
            @endif
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="brand-name" class="mb-1 block text-xs font-medium text-gray-500">Brand name</label>
                    <input id="brand-name" type="text" wire:model="brand_name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                    @error('brand_name') <p class="mt-1 text-xs text-error-600" role="alert">{{ $message }}</p> @enderror
                    @if ($duplicateBrandWarning !== '')
                        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200" role="status">{{ $duplicateBrandWarning }}</p>
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <label for="website-url" class="mb-1 block text-xs font-medium text-gray-500">Website URL</label>
                    <input id="website-url" type="url" wire:model.live="website_url" placeholder="https://atlasdental.example" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                    @error('website_url') <p class="mt-1 text-xs text-error-600" role="alert">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-400">Used later for matching GA4 / GSC / GBP — no live crawl on change.</p>
                </div>
                <div>
                    <label for="country" class="mb-1 block text-xs font-medium text-gray-500">Primary market / country</label>
                    <select id="country" wire:model="primary_country" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        @foreach ($countryOptions as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="language" class="mb-1 block text-xs font-medium text-gray-500">Primary language</label>
                    <select id="language" wire:model="primary_language" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        @foreach ($languageOptions as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-1 text-xs font-medium text-gray-500">Responsible user(s)</p>
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
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Digital Assets</h2>
                <p class="mt-1 text-sm text-gray-500">Which Digital Assets do you manage? Domain and Hosting are Website infrastructure — not selectable assets.</p>
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
                                    @if (! empty($option['future'])) Future capability
                                    @elseif (in_array($option['type'], $selected_assets, true)) ✓ Selected
                                    @else Tap to select
                                    @endif
                                </p>
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>
            <p class="text-xs text-gray-400">Missing Meta/GBP/etc is setup incomplete — not an error and not a Finding.</p>
        </section>
    @endif

    {{-- STEP 4 CONNECT --}}
    @if ($step === 4)
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Connect &amp; Match</h2>
                <p class="mt-1 text-sm text-gray-500">Discover → list → select. Confirm before binding. Skip any provider for later.</p>
            </div>

            @if (in_array('website', $selected_assets, true))
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark type="website" size="sm" />
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Website</h3>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $website_url !== '' ? $website_url : 'URL not provided — can be completed later' }}</p>
                    <p class="mt-1 text-xs text-gray-400">Infrastructure (Domain / DNS / Hosting / SSL) lives under the Website Digital Asset.</p>
                </div>
            @endif

            @foreach ($matchCards as $card)
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <x-demo.digital-asset-mark :type="$card['type'] === 'gbp' ? 'gbp' : $card['type']" size="sm" />
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $card['label'] }}</h3>
                                <p class="text-xs text-gray-500">{{ $card['integration'] }} Integration · {{ $card['integration_state'] }} · {{ count($card['resources']) }} resources found</p>
                            </div>
                        </div>
                        <button type="button" wire:click="skipProvider('{{ $card['type'] }}')" class="text-xs font-medium text-gray-500 hover:underline">Skip for now</button>
                    </div>
                    @if ($card['skipped'])
                        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">Skipped — setup remains incomplete for this connector (not unhealthy).</p>
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
                                                <span class="text-[10px] font-bold uppercase text-brand-700 dark:text-brand-300">Recommended</span>
                                            @elseif (! empty($resource['match_signal']))
                                                <span class="text-[10px] font-bold uppercase text-gray-400">Possible match</span>
                                            @endif
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</span>
                                            @if (($card['selected'] ?? null) === $resource['id'])
                                                <span class="text-xs font-medium text-brand-700 dark:text-brand-300">Selected</span>
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
                </div>
            @endforeach

            @if (in_array('instagram', $selected_assets, true))
                <div class="rounded-xl bg-white p-4 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    Instagram remains a future capability — not connected in this Demo.
                </div>
            @endif
        </section>
    @endif

    {{-- STEP 5 DISCOVER --}}
    @if ($step === 5)
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Discover &amp; Review</h2>
                <p class="mt-1 text-sm text-gray-500">Reuses Brand Public Discovery candidates. Observed ≠ canonical. Accept only with human approval.</p>
            </div>

            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Candidate Brand updates</h3>
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
                                {{ in_array($candidate['id'], $accepted_candidate_ids, true) ? 'Selected' : 'Add' }}
                            </button>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-gray-400">Batch “Accept selected” runs on Continue — conflicts require individual review below.</p>
            </div>

            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Conflicts</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($conflicts as $conflict)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $conflict['field'] }}</p>
                                <p class="text-xs text-gray-500">{{ $conflict['state'] }} · not batch-accepted</p>
                            </div>
                            <button type="button" wire:click="openConflict('{{ $conflict['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- STEP 6 SUMMARY --}}
    @if ($step === 6)
        <section class="rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $summary['brand'] }} is ready</h2>
            <p class="mt-1 text-sm text-gray-500">Setup incomplete ≠ Brand unhealthy.</p>
            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex justify-between gap-3 border-b border-gray-100 py-2 dark:border-gray-800"><dt class="text-gray-500">Customer</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $summary['customer'] }} ✓</dd></div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-2 dark:border-gray-800"><dt class="text-gray-500">Brand</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $summary['brand'] }} ✓</dd></div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-2 dark:border-gray-800"><dt class="text-gray-500">Digital Assets</dt><dd class="font-medium text-gray-900 dark:text-white">{{ count($summary['assets']) }} configured</dd></div>
                @foreach ($summary['assets'] as $type)
                    <div class="flex justify-between gap-3 border-b border-gray-50 py-1.5 dark:border-gray-800/60">
                        <dt class="text-gray-500">{{ $type }}</dt>
                        <dd class="text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ in_array($type, $summary['bound'], true) ? '✓ Bound' : '✓ Configured' }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">Brand Context</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $summary['accepted'] }} facts accepted</dd></div>
                @if ($summary['conflicts_open'] > 0)
                    <div class="flex justify-between gap-3 py-2"><dt class="text-gray-500">Review remaining</dt><dd class="font-medium text-amber-700 dark:text-amber-300">{{ $summary['conflicts_open'] }} public identity conflict(s)</dd></div>
                @endif
            </dl>
            <div class="mt-6 flex flex-wrap gap-2">
                <a href="{{ route('demo.brand', ['brand' => $summary['brand_id']]) }}" wire:navigate class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">Open Brand</a>
                <a href="{{ route('demo.dashboard') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Go to Dashboard</a>
                @if ($summary['conflicts_open'] > 0)
                    <a href="{{ route('demo.brand', ['brand' => $summary['brand_id'], 'tab' => 'discovery', 'discovery' => 'conflicts']) }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Review remaining setup</a>
                @endif
            </div>
        </section>
    @endif

    @if ($step < 6)
        <div class="sticky bottom-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white/95 p-3 shadow-lg ring-1 ring-inset ring-gray-200 backdrop-blur dark:bg-gray-900/95 dark:ring-gray-800">
            <button type="button" wire:click="back" @disabled($step <= ($entry === 'asset' ? 3 : ($entry === 'brand' ? 2 : 1))) class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 disabled:opacity-40 dark:text-gray-300">Back</button>
            <button type="button" wire:click="next" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">
                {{ $step === 5 ? 'Accept selected & finish' : 'Continue' }}
            </button>
        </div>
    @endif

    @if ($reviewConflict)
        <div class="fixed inset-0 z-50 flex justify-end bg-gray-900/40" role="dialog" aria-modal="true" wire:click="closeConflict">
            <div class="flex h-full w-full max-w-lg flex-col overflow-y-auto bg-white p-5 shadow-xl dark:bg-gray-900" wire:click.stop>
                <div class="flex items-start justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $reviewConflict['field'] }}</h2>
                    <button type="button" wire:click="closeConflict" aria-label="Close">✕</button>
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
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'keep_canonical')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Keep canonical</button>
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'ignore')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Ignore difference</button>
                </div>
            </div>
        </div>
    @endif
</div>
