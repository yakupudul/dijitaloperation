@php
    // Panel concept 2026-10-20: "Lokasyonların verisi otomatik geliyor mu?" The collection list answers it.
    $found = __('gbp-connector.found', ['count' => $count]);
@endphp
<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'gbp', 'size' => 'lg'])
            <div class="min-w-0">
                <h1 class="page-title">Google Business Profile</h1>
                <p class="page-sub" title="{{ \Illuminate\Support\Str::after($found, ' · ') }} {{ __('gbp-connector.description') }} {{ __('gbp-connector.scope') }}">{{ \Illuminate\Support\Str::before($found, ' · ') }}.</p>
            </div>
        </div>
        <a href="{{ route('operator.integrations.google') }}" wire:navigate class="btn w-full sm:w-auto">{{ __('gbp-connector.manage') }}</a>
    </header>
    @if($integration?->isActive())
        <livewire:operator.integrations.resource-automations provider="google" resource-type="google_business_profile" :expanded="true" />
    @else
        <div class="card">
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <p class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ __('gbp-connector.reconnect') }}</p>
                <a href="{{ route('operator.integrations.google') }}" wire:navigate class="btn-primary w-full sm:w-auto">{{ __('gbp-connector.manage') }}</a>
            </div>
        </div>
    @endif
</div>
