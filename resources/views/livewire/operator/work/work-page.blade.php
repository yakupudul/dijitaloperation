<div class="space-y-5 dark:text-gray-200" data-work-desk>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Genel işler</h1>
            <p class="mt-1 text-xs text-gray-500">{{ $brandName !== null ? $brandName.' markasının açık işleri' : 'Bütün markaların açık işleri' }}; aynı türden işler tek kartta, en acil olan üstte.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="brand" aria-label="Marka" autocomplete="off" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" data-brand-filter>
                <option value="" @selected($brand === null)>Tüm markalar</option>
                @foreach($brands as $b)<option value="{{ $b->id }}" @selected($brand === (int) $b->id)>{{ $b->name }}{{ ($brandCounts[$b->id] ?? 0) > 0 ? ' ('.$brandCounts[$b->id].')' : '' }}</option>@endforeach
            </select>
            @include('livewire.operator.work.partials.push-toggle', ['devices' => $pushDevices])
        </div>
    </header>

    @if($brandName !== null)
        <div class="flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" data-brand-filter-on="{{ $brand }}">
            <span>Yalnız <strong>{{ $brandName }}</strong> gösteriliyor.</span>
            <button type="button" wire:click="clearBrand" class="font-semibold underline underline-offset-2" data-clear-brand>Tüm markaları göster</button>
        </div>
    @endif

    @if($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif

    <nav class="flex gap-5 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Sekmeler" role="tablist">
        @foreach($tabs as $code => $label)
            <button type="button" role="tab" wire:click="setTab('{{ $code }}')" @class(['-mb-px h-11 shrink-0 border-b-2 whitespace-nowrap', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $tab === $code, 'border-transparent text-gray-500 hover:text-gray-800' => $tab !== $code]) aria-selected="{{ $tab === $code ? 'true' : 'false' }}">
                {{ $label }}
                @if($urgent[$code] > 0)<span class="ml-1 rounded-full bg-rose-100 px-1.5 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" title="Acil">{{ $urgent[$code] }}</span>@endif
                @if($counts[$code] > 0)<span class="ml-1 rounded-full bg-gray-100 px-1.5 text-xs text-gray-600 dark:bg-gray-800">{{ $counts[$code] }}</span>@endif
            </button>
        @endforeach
    </nav>

    <div class="flex items-center gap-2 text-xs">
        <button type="button" wire:click="setView('acik')" @class(['rounded-full px-3 py-1 ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $view === 'acik', 'ring-gray-300 dark:ring-gray-700' => $view !== 'acik'])>Açık</button>
        <button type="button" wire:click="setView('yapildi')" @class(['rounded-full px-3 py-1 ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $view === 'yapildi', 'ring-gray-300 dark:ring-gray-700' => $view !== 'yapildi'])>Yapıldı (30 gün)</button>
        <span class="text-gray-400">{{ $total }} iş{{ $brandName === null && count($sections) > 1 ? ' · '.(count($sections) + $hiddenSections).' marka' : '' }}</span>
    </div>

    @if($queue !== null)
        @include('livewire.operator.work.partials.content-queue')
        @if($sections !== [])<h2 class="pt-2 text-base font-semibold text-gray-900 dark:text-white">Diğer SEO işleri</h2>@endif
    @endif

    @include('livewire.operator.work.partials.work-groups')
</div>
