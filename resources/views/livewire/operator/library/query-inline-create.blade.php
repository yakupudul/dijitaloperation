<details class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
    <summary class="cursor-pointer text-xs font-medium text-brand-600">Listede yok mu? Sektör veya hizmet oluştur</summary>
    <div class="mt-3 space-y-3">
        <div class="flex gap-2">
            <input wire:model="newSectorName" aria-label="Yeni sektör adı" placeholder="Yeni sektör adı" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 min-w-0 flex-1" />
            <button type="button" wire:click="createInlineSector('{{ $target }}')" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-2 text-xs dark:border-gray-700 disabled:opacity-50">Sektör ekle</button>
        </div>
        <input wire:model="newServiceName" aria-label="Yeni hizmet adı" placeholder="Seçilen sektöre yeni hizmet" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 w-full" />
        <input wire:model="newServiceWords" aria-label="Eşleştirme kelimeleri" placeholder="Eşleştirme kelimeleri: implant, tek diş" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 w-full" />
        <button type="button" wire:click="createInlineService('{{ $target }}')" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-2 text-xs dark:border-gray-700 disabled:opacity-50">Hizmet ekle ve seç</button>
        @if ($errors->any())<p class="text-xs text-red-600">{{ $errors->first() }}</p>@endif
    </div>
</details>
