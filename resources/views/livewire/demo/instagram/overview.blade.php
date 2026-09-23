@php
    $identity = $workspace['identity'];
    $profile = $workspace['profile'];
    $isTr = app()->getLocale() === 'tr';
    $observedAt = $profile['observed_at'] ?? null;
    $observedLabel = $observedAt
        ? ($isTr ? $observedAt->copy()->locale('tr')->translatedFormat('j M Y H:i') : $observedAt->format('M j, Y H:i'))
        : null;
    $profileFields = $profile === null ? [] : array_filter([
        'username' => filled($profile['username']) ? '@'.ltrim((string) $profile['username'], '@') : null,
        'name' => $profile['name'],
        'account_type' => filled($profile['account_type']) ? str_replace('_', ' ', ucfirst(strtolower((string) $profile['account_type']))) : null,
        'website' => $profile['website'],
        'biography' => $profile['biography'],
    ], static fn ($value): bool => filled($value));
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" />

    <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-4">
                <x-demo.digital-asset-mark type="instagram" size="lg" />
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Instagram</p>
                    <h1 class="mt-1 truncate text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                    @if (filled($identity['brand_id']))
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('operator_meta.instagram.brand') }}:
                            <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] ?? '—' }}</a>
                        </p>
                    @endif
                    <div class="mt-2">@include('livewire.demo.partials._asset-scope-chip', ['assetType' => 'instagram'])</div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <a href="{{ route('operator.activity', ['asset' => $identity['asset_id']]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator_meta.instagram.view_activity') }}</a>
                <a href="{{ route('operator.assets') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator_meta.instagram.all_assets') }}</a>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
        <h2 class="text-sm font-semibold">{{ __('operator_meta.instagram.not_connected_title') }}</h2>
        <p class="mt-1 text-sm leading-6 opacity-90">{{ __('operator_meta.instagram.not_connected_body') }}</p>
    </section>

    <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="text-base font-bold text-gray-900 dark:text-white">{{ __('operator_meta.instagram.profile_title') }}</h2>
            @if ($observedLabel)
                <span class="text-xs text-gray-400">{{ __('operator_meta.instagram.profile_observed', ['time' => $observedLabel]) }}</span>
            @endif
        </div>

        @if ($profileFields === [])
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('operator_meta.instagram.profile_missing') }}</p>
        @else
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ($profileFields as $key => $value)
                    <div @class(['sm:col-span-2' => $key === 'biography'])>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('operator_meta.instagram.'.$key) }}</dt>
                        <dd class="mt-1 whitespace-pre-line break-words text-sm text-gray-800 dark:text-gray-200">@if ($key === 'website' && preg_match('#^https?://#i', (string) $value) === 1)<a href="{{ $value }}" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline dark:text-brand-400">{{ $value }}</a>@else{{ $value }}@endif</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
</div>
