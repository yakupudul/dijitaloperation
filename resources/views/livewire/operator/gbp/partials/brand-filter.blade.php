<label class="flex min-w-0 max-w-full items-center">
    <span class="sr-only">Marka</span>
    <select wire:model.live="brand" class="h-8 min-w-0 max-w-full rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 sm:max-w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <option value="">Tüm markalar</option>
        @foreach ($brandOptions as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </select>
</label>
