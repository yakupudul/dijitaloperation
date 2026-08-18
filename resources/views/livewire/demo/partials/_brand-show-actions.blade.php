<div class="flex flex-wrap gap-2">
    <a href="{{ route('operator.asset.create', ['brandId' => $brandId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
        {{ __('operator.brand.actions.add_asset') }}
    </a>
    <a href="{{ route('operator.files', ['scope' => 'brand', 'brand' => $brandId, 'q' => $brandName ?? '']) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        {{ __('operator.brand.actions.open_files') }}
    </a>
    <a href="{{ route('operator.activity', ['brand' => $brandId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        {{ __('operator.brand.actions.view_activity') }}
    </a>
    <a href="{{ route('operator.brand.edit', ['brandId' => $brandId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        {{ __('operator.brand.actions.edit') }}
    </a>
    @if (! empty($customerId))
        <a href="{{ route('operator.customer', ['customerId' => $customerId]) }}" wire:navigate
            class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            {{ __('operator.brand.actions.open_customer') }}
        </a>
    @endif
</div>
