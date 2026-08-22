<div class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('operator.assets') }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('operator_runtime.sources.assets') }}</a>
                <span class="text-gray-300">/</span>
                <span class="text-gray-500">{{ __('operator_runtime.sources.title') }}</span>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $asset->name }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $customer?->name }} · {{ $brand?->name }} · {{ $asset->type }}</p>
            <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">{{ __('operator_runtime.sources.description') }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($providers as $provider)
                <button type="button" wire:click="discover('{{ $provider }}')" wire:loading.attr="disabled"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    {{ __('operator_runtime.sources.discover', ['provider' => strtoupper($provider)]) }}
                </button>
            @endforeach
            <button type="button" wire:click="collectNow" @disabled(! $hasActiveBinding) wire:loading.attr="disabled"
                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('operator_runtime.sources.collect_now') }}
            </button>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-xl border px-4 py-3 text-sm',
            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-300' => $messageTone === 'error',
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-300' => $messageTone === 'success',
            'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-300' => ! in_array($messageTone, ['error', 'success'], true),
        ])>{{ $message }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ($capabilities as $capability)
            @php
                $binding = $bindings->get($capability);
                $current = $binding?->externalResource;
                $available = $resources[$capability] ?? collect();
                $active = $binding && $binding->status === \App\Models\CoreAssetBinding::STATUS_ACTIVE;
            @endphp

            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <div>
                        <h2 class="font-semibold text-gray-900 dark:text-white">{{ \App\Support\Integrations\ProviderRegistry::capabilityLabel($capability) }}</h2>
                        <p class="mt-1 text-xs text-gray-500">{{ __('operator_runtime.sources.capability_help') }}</p>
                    </div>
                    <span @class([
                        'rounded-full px-2.5 py-1 text-xs font-semibold',
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' => $active,
                        'bg-gray-100 text-gray-500 dark:bg-white/[0.05] dark:text-gray-400' => ! $active,
                    ])>{{ $active ? __('operator_runtime.sources.connected') : __('operator_runtime.sources.not_connected') }}</span>
                </div>

                <div class="space-y-4 p-5">
                    @if ($active && $current)
                        <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator_runtime.sources.current_resource') }}</p>
                            <p class="mt-2 font-semibold text-gray-900 dark:text-white">{{ $current->display_name ?: $current->external_id }}</p>
                            <p class="mt-1 break-all text-xs text-gray-500">{{ $current->external_id }}</p>
                            <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-500">
                                <span>{{ __('operator_runtime.sources.provider') }}: {{ strtoupper((string) $current->integration?->provider) }}</span>
                                <span>·</span>
                                <span>{{ __('operator_runtime.sources.resource_status') }}: {{ $current->status }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($capability === 'google_business_profile')
                        @php
                            $gbpDatasets = app()->getLocale() === 'tr'
                                ? ['Profil & Google Güncellemeleri', 'Günlük Görünürlük & Aksiyonlar', 'Aylık Arama Kelimeleri', 'Yorumlar', 'Fotoğraf & Video', 'Google Gönderileri', 'Attributes', 'Hizmetler', 'Randevu / Sipariş Linkleri', 'Doğrulama & Profil Durumu']
                                : ['Profile & Google Updates', 'Daily Visibility & Actions', 'Monthly Search Keywords', 'Reviews', 'Photo & Video', 'Google Posts', 'Attributes', 'Services', 'Appointment / Order Links', 'Verification & Profile State'];
                        @endphp
                        <div class="rounded-lg border border-brand-100 bg-brand-50/50 p-4 dark:border-brand-900/40 dark:bg-brand-950/10">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ app()->getLocale() === 'tr' ? 'Google’dan çekilecek veriler' : 'Data collected from Google' }}
                                </p>
                                <span class="rounded-full bg-white px-2 py-1 text-[11px] font-semibold text-brand-600 ring-1 ring-brand-100 dark:bg-gray-900 dark:ring-brand-900/50">10 datasets</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($gbpDatasets as $datasetLabel)
                                    <span class="rounded-md bg-white px-2 py-1 text-xs text-gray-600 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $datasetLabel }}</span>
                                @endforeach
                            </div>
                            <p class="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                {{ app()->getLocale() === 'tr'
                                    ? 'Konumu bağladıktan sonra “Veriyi Topla / Collect Now” profil, performans, aramalar, yorumlar ve içerik verilerini Google Business Profile API’lerinden toplar.'
                                    : 'After binding the location, Collect Now pulls profile, performance, search, review and content data from Google Business Profile APIs.' }}
                            </p>
                        </div>
                    @endif

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $active ? __('operator_runtime.sources.change_resource') : __('operator_runtime.sources.select_resource_label') }}</label>
                        <select wire:model="selectedResource.{{ $capability }}"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                            <option value="">{{ __('operator_runtime.sources.choose') }}</option>
                            @foreach ($available as $resource)
                                <option value="{{ $resource->id }}">{{ $resource->display_name ?: $resource->external_id }} · {{ $resource->external_id }}</option>
                            @endforeach
                        </select>
                        @error('selectedResource.'.$capability)
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                        @if ($available->isEmpty())
                            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ __('operator_runtime.sources.no_resources') }}</p>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="bind('{{ $capability }}')"
                            class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">
                            {{ $active ? __('operator_runtime.sources.change') : __('operator_runtime.sources.connect') }}
                        </button>
                        @if ($active)
                            <button type="button" wire:click="disable('{{ $capability }}')"
                                class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                {{ __('operator_runtime.sources.disconnect') }}
                            </button>
                        @endif
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <section class="rounded-xl bg-gray-50 p-4 text-sm text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.02] dark:text-gray-300 dark:ring-gray-800">
        <strong>{{ __('operator_runtime.sources.rule_title') }}</strong>
        <span>{{ __('operator_runtime.sources.rule_body') }}</span>
    </section>
</div>
