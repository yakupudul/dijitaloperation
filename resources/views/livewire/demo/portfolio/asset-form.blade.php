@php($f = 'operator.forms.asset_form.')
<div class="space-y-6">
    @if ($mode === 'edit')
        <x-operator.asset-context :asset-id="$assetId" current="edit" />
    @endif
    <div class="mx-auto max-w-4xl space-y-6">
    <header>
        <a href="{{ $backUrl }}" wire:navigate class="text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">{{ __($f.'back') }}</a>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="page-title">{{ $pageTitle }}</h1>
                <p class="page-sub">{{ $pageSubtitle }}</p>
            </div>
            @if ($sourcesUrl)
                <a href="{{ $sourcesUrl }}" wire:navigate class="btn">{{ __($f.'open_sources') }}</a>
            @endif
        </div>
    </header>

    <form wire:submit.prevent="save" class="space-y-5">
        <section class="card p-5">
                <h2 class="section-title">{{ __($f.'identity') }}</h2>
                <div class="mt-4 space-y-4">
            @if ($brandLocked && $brandName)
                <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                    {{ __($f.'brand_prefix') }} <span class="font-medium text-gray-900 dark:text-white">{{ $brandName }}</span>
                </div>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ta.form.field label="Müşteri" :error="$errors->first('customer_id')">
                        <x-ta.form.select wire:model.live="customer_id" :options="$customerOptions" :placeholder="__($f.'select')" />
                    </x-ta.form.field>
                    <x-ta.form.field :label="__($f.'brand')" :required="true" :error="$errors->first('brand_id')">
                        <x-ta.form.select wire:model.live="brand_id" :options="$brandOptions" :placeholder="__($f.'select')" :nullable="false" />
                    </x-ta.form.field>
                </div>
                @if ($mode === 'edit')
                    <p class="text-xs text-gray-500">Aynı müşterinin başka markasına taşımak onay gerektirmez. Başka müşterinin markasına taşımak yetki devridir; kaydederken onay istenir.</p>
                @endif
            @endif

            @if ($ownershipConflict)
                <x-operator.ownership-transfer-panel :conflict="$ownershipConflict" :action="$mode === 'edit' ? 'confirmAssetMove' : 'moveExistingSite'" :can-transfer="$canTransfer" />
            @endif

            @if (($existingSite ?? null) !== null && ! $ownershipConflict)
                <div class="tone-look rounded-lg p-4 text-[13px]" data-existing-site>
                    <p class="font-medium">Bu alan adı zaten kayıtlı</p>
                    <p class="mt-1">{{ $existingSite['message'] }}</p>
                    <p class="mt-1">İkinci bir web sitesi oluşturulmaz; mevcut siteyi (bağlantıları ve verisiyle) bu markaya taşıyabilirsiniz.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($existingSite['movable'])
                            <button type="button" wire:click="moveExistingSite" class="btn">Mevcut siteyi bu markaya taşı</button>
                        @endif
                        <button type="button" wire:click="dismissExistingSite" class="btn-quiet">Vazgeç</button>
                    </div>
                </div>
            @endif

            <x-ta.form.field :label="__($f.'name')" :required="true" :error="$errors->first('name')">
                <input wire:model="name" type="text" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
            </x-ta.form.field>

            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__($f.'type')" :required="true" :error="$errors->first('type')" :helper="$typeLocked ? __($f.'type_locked') : null">
                    @if ($typeLocked)
                        <div class="flex h-9 items-center rounded-lg bg-gray-50 px-3 text-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $typeOptions[$type] ?? $type }}</div>
                    @else
                        <x-ta.form.select wire:model.live="type" :options="$typeOptions" :placeholder="__($f.'select')" :nullable="false" />
                    @endif
                </x-ta.form.field>
                <x-ta.form.field :label="__($f.'status')" :required="true" :error="$errors->first('status')">
                    <x-ta.form.select wire:model="status" :options="$statusOptions" :searchable="false" :nullable="false" />
                </x-ta.form.field>
            </div>
            </div>
            </section>

        @if ($isWebsite)
            <section class="card p-5">
                <h2 class="section-title">{{ __($f.'website_details') }}</h2>
                <p class="mt-0.5 text-[13px] text-gray-500">{{ __($f.'website_details_hint') }}</p>
                <div class="mt-4 space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ta.form.field :label="__($f.'domain')" helper="example.com" :error="$errors->first('domain')">
                        <input wire:model="domain" type="text" placeholder="example.com" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                    </x-ta.form.field>
                    <x-ta.form.field :label="__($f.'primary_url')" :error="$errors->first('primary_url')">
                        <input wire:model="primary_url" type="url" placeholder="https://www.example.com" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                    </x-ta.form.field>
                    <x-ta.form.field :label="__($f.'cms')" :error="$errors->first('cms')">
                        <x-ta.form.select wire:model="cms" :options="$cmsOptions" :placeholder="__($f.'select')" />
                    </x-ta.form.field>
                    <x-ta.form.field :label="__($f.'site_type')" :error="$errors->first('site_type')">
                        <x-ta.form.select wire:model="site_type" :options="$websiteTypeOptions" :placeholder="__($f.'select')" />
                    </x-ta.form.field>
                </div>

                <x-ta.form.field :label="__($f.'languages')" :error="$errors->first('languages')">
                    <x-ta.form.multi-select wire:model="languages" :options="$languageOptions" :placeholder="__($f.'select')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__($f.'target_countries')" :error="$errors->first('target_countries')">
                    <x-ta.form.multi-select wire:model="target_countries" :options="$countryOptions" :placeholder="__($f.'select')" />
                </x-ta.form.field>

                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ __($f.'seo_market') }}</p>
                    @if ($marketCountryOptions === [])
                        <p class="rounded-lg bg-gray-50 px-3 py-2 text-[13px] text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">{{ __($f.'seo_market_unavailable') }}</p>
                    @else
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-ta.form.field :label="__($f.'seo_market_country')" :error="$errors->first('seo_market_country')">
                                <x-ta.form.select wire:model.live="seo_market_country" :options="$marketCountryOptions" :placeholder="__($f.'select')" />
                            </x-ta.form.field>
                            <x-ta.form.field :label="__($f.'seo_market_language')" :error="$errors->first('seo_market_language')">
                                <x-ta.form.select wire:model="seo_market_language" :options="$marketLanguageOptions" :placeholder="__($f.'select')" />
                            </x-ta.form.field>
                        </div>
                    @endif
                </div>

                <x-ta.form.field :label="__($f.'hosting_context')" :error="$errors->first('hosting_context')">
                    <textarea wire:model="hosting_context" rows="3" class="py-2 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white"></textarea>
                </x-ta.form.field>
                </div>
            </section>
        @else
            <section class="card p-5">
                <h2 class="section-title">{{ __($f.'connection') }}</h2>
                <div class="mt-4 space-y-4">
                <p class="text-[13px] text-gray-500 dark:text-gray-400">{{ __($f.'connection_hint') }}</p>
                </div>
            </section>
        @endif

        <div class="card sticky bottom-3 z-30 px-4 py-3 shadow-theme-lg">
            <div class="flex justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate class="btn">{{ __($f.'cancel') }}</a>
                <button type="submit" wire:loading.attr="disabled" class="btn-primary">
                    <span wire:loading.remove>{{ $mode === 'edit' ? __('operator.forms.save_changes') : __($f.'save') }}</span>
                    <span wire:loading>{{ __($f.'saving') }}</span>
                </button>
            </div>
        </div>
    </form>
    </div>
</div>
