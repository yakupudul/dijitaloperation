<div class="flex flex-wrap gap-2">
    <a href="{{ route('demo.asset.create', ['brandId' => $brandId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
        Add digital asset
    </a>
    <a href="{{ route('demo.brand.edit', ['brandId' => $brandId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        Edit brand
    </a>
    @if (! empty($customerId))
        <a href="{{ route('demo.customer', ['customerId' => $customerId]) }}" wire:navigate
            class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Open customer
        </a>
    @endif
</div>
