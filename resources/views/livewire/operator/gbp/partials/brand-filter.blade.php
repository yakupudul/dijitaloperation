<label class="flex items-center gap-2 text-sm">
    <span class="text-gray-500">Marka</span>
    <select wire:model.live="brand" class="rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
        <option value="">Tüm markalar</option>
        @foreach ($brandOptions as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </select>
</label>
