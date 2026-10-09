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

    <div class="grid gap-2 sm:grid-cols-3" data-work-summary>
        <a href="{{ route('operator.repair', array_filter(['marka' => $brand])) }}" class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 hover:ring-brand-300 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs text-gray-500">Onayını bekleyen</p>
            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $summary['approve'] }}</p>
            <p class="text-xs text-gray-500">Onarım masası {{ $onDesk['brand'] }} · başlık ve yazı {{ $summary['approve'] - $onDesk['brand'] }}</p>
        </a>
        <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs text-gray-500">Sistemin bu hafta yaptığı</p>
            <p class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $summary['done'] }}</p>
            <p class="text-xs text-gray-500">yazılan düzeltmeler ve kendiliğinden kapanan sorunlar</p>
        </div>
        <a href="{{ route('operator.repair', array_filter(['marka' => $brand, 'serit' => 'manual'])) }}" class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 hover:ring-brand-300 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs text-gray-500">Senin elin gerekiyor</p>
            <p @class(['text-2xl font-semibold', 'text-amber-600' => $summary['hands'] > 0, 'text-gray-900 dark:text-white' => $summary['hands'] === 0])>{{ $summary['hands'] }}</p>
            <p class="text-xs text-gray-500">sistemin yapamadığı işler</p>
        </a>
    </div>

    @if($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif

    <nav class="flex gap-5 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Sekmeler" role="tablist">
        @foreach($tabs as $code => $label)
            @continue($code === 'kurulum' && $tab !== 'kurulum')
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

    @if($view === 'acik' && ($onDesk['brand'] > 0 || ($onDesk['preparing'] ?? 0) > 0))
        <a href="{{ route('operator.repair', array_filter(['marka' => $brand])) }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-brand-50 px-4 py-2 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200" data-on-desk>
            @php
                $deskLine = 'Hazırlanmış '.$onDesk['brand'].' düzeltme Onarım masasında onayını bekliyor';
                if (($onDesk['preparing'] ?? 0) > 0) {
                    $deskLine .= '; '.number_format($onDesk['preparing'], 0, ',', '.').' sayfa düzeltmesini sistem hazırlıyor (saatte '.\App\Services\Repair\RepairPreparer::BATCH_PAGES_PER_RUN.' sayfa), hazır olan masaya gelir';
                }
            @endphp
            <span>{{ $deskLine }}. Sayfa düzeltmeleri burada tekrar gösterilmez.</span>
            <span class="font-semibold">Onarım masasını aç →</span>
        </a>
    @endif

    @if($setup !== [] && array_sum($setup) > 0 && ($brand === null || ($setup[$brand] ?? 0) > 0))
        <div class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-4 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300" data-setup-strip>
            <span class="font-semibold">Marka kurulumu:</span>
            @foreach($brands as $b)
                @if(($setup[$b->id] ?? 0) > 0 && ($brand === null || $brand === (int) $b->id))
                    <a href="{{ route('operator.brand', $b->id) }}" class="rounded-full bg-white px-2 py-0.5 ring-1 ring-inset ring-gray-200 hover:ring-brand-300 dark:bg-gray-900 dark:ring-gray-700">{{ $b->name }} · {{ $setup[$b->id] }}</a>
                @endif
            @endforeach
            <button type="button" wire:click="setTab('kurulum')" class="ml-auto font-semibold text-brand-600 hover:underline">Kurulum işlerini listele</button>
        </div>
    @endif

    @if($queue !== null)
        @include('livewire.operator.work.partials.content-queue')
        @if($sections !== [])<h2 class="pt-2 text-base font-semibold text-gray-900 dark:text-white">Diğer SEO işleri</h2>@endif
    @endif

    @include('livewire.operator.work.partials.work-groups')
</div>
