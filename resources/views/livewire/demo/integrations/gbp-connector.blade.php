<div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Google Business Profile</h1>
            <p class="mt-2 text-sm text-gray-500">{{ __('gbp-connector.found', ['count' => $count]) }}</p>
        </div>
        <a href="{{ route('operator.integrations.google') }}" wire:navigate class="rounded-lg border px-4 py-2 text-sm dark:border-gray-700">{{ __('gbp-connector.manage') }}</a>
    </header>
    @if($integration?->isActive())
        <div class="rounded-xl border bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="font-semibold">{{ __('gbp-connector.title') }}</h2>
            <p class="mt-2 text-sm text-gray-500">{{ __('gbp-connector.description') }}</p>
            <p class="mt-2 text-sm text-gray-500">{{ __('gbp-connector.scope') }}</p>
        </div>
        <livewire:operator.integrations.resource-automations provider="google" resource-type="google_business_profile" :expanded="true" />
    @else
        <p class="rounded-xl border p-5">{{ __('gbp-connector.reconnect') }}</p>
    @endif
</div>
