<label class="flex min-w-0 max-w-full items-center gap-2 text-sm">
    <span class="text-gray-500">Marka</span>
    <select wire:model.live="brand" class="min-w-0 rounded-lg border-gray-200 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
        <option value="">Tüm markalar</option>
        @foreach ($brandOptions as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </select>
</label>
