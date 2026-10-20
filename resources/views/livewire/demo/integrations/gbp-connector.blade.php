<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Google Business Profile</h1>
            <p class="mt-2 text-sm text-gray-500">{{ __('gbp-connector.found', ['count' => $count]) }}</p>
        </div>
        <a href="{{ route('operator.integrations.google') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700">{{ __('gbp-connector.manage') }}</a>
    </header>
    @if($integration?->isActive())
        <div>
            <h2 class="section-title">{{ __('gbp-connector.title') }}</h2>
            <p class="mt-2 text-sm text-gray-500">{{ __('gbp-connector.description') }}</p>
            <p class="mt-2 text-sm text-gray-500">{{ __('gbp-connector.scope') }}</p>
        </div>
        <livewire:operator.integrations.resource-automations provider="google" resource-type="google_business_profile" :expanded="true" />
    @else
        <p class="rounded-lg p-4 text-sm tone-look">{{ __('gbp-connector.reconnect') }}</p>
    @endif
</div>
