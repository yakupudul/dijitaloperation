<div class="space-y-8 dark:text-gray-200" data-work-desk>
    @if($embedded)
        <div class="flex justify-end">@include('livewire.operator.work.partials.push-toggle', ['devices' => $pushDevices])</div>
    @else
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Genel işler</h1>
            <p class="mt-1 text-sm text-gray-500" title="Aynı türden işler bir arada, en acil olan üstte.">{{ $brandName !== null ? $brandName.' markasının açık işleri' : 'Bütün markaların açık işleri' }}</p>
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
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300" data-brand-filter-on="{{ $brand }}">
            <span>Yalnız <strong>{{ $brandName }}</strong> gösteriliyor.</span>
            <button type="button" wire:click="clearBrand" class="link-neutral" data-clear-brand>Tüm markaları göster</button>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3" data-work-summary>
        <x-ta.metric-card :href="route('operator.repair', array_filter(['marka' => $brand]))" label="Onayını bekleyen" :value="$summary['approve']"
            :hint="'Onarım masası '.$onDesk['brand'].', başlık ve yazı '.($summary['approve'] - $onDesk['brand'])" />
        <x-ta.metric-card label="Sistemin bu hafta yaptığı" :value="$summary['done']" title="Yazılan düzeltmeler ve kendiliğinden kapanan sorunlar" />
        <x-ta.metric-card :href="route('operator.repair', array_filter(['marka' => $brand, 'serit' => 'manual']))" label="Senin elin gerekiyor" :value="$summary['hands']"
            :delta="$summary['hands'] > 0 ? 'bak' : null" :tone="$summary['hands'] > 0 ? 'look' : 'neutral'" title="Sistemin yapamadığı işler" />
    </div>

    @endif

    @if($message !== '')<p role="status" class="tone-info rounded-lg px-3 py-2 text-sm">{{ $message }}</p>@endif

    <nav class="flex gap-5 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Sekmeler" role="tablist">
        @foreach($tabs as $code => $label)
            @continue($code === 'kurulum' && $tab !== 'kurulum')
            <button type="button" role="tab" wire:click="setTab('{{ $code }}')" @class(['-mb-px h-11 shrink-0 border-b-2 whitespace-nowrap', 'border-gray-900 font-medium text-gray-900 dark:border-white dark:text-white' => $tab === $code, 'border-transparent text-gray-500 hover:text-gray-900' => $tab !== $code]) aria-selected="{{ $tab === $code ? 'true' : 'false' }}">
                {{ $label }}
                @if($counts[$code] > 0)<span class="ml-1 text-xs tabular-nums text-gray-500" title="Açık iş">{{ $counts[$code] }} iş</span>@endif
                @if($urgent[$code] > 0)<span class="ml-1 text-xs tabular-nums text-error-700 dark:text-error-400" title="Bunların acil olanları">{{ $urgent[$code] }} acil</span>@endif
            </button>
        @endforeach
    </nav>

    <div class="flex items-center gap-2 text-xs">
        <button type="button" wire:click="setView('acik')" @class(['rounded-md px-2 py-0.5', 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $view === 'acik', 'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5' => $view !== 'acik'])>Açık</button>
        <button type="button" wire:click="setView('yapildi')" @class(['rounded-md px-2 py-0.5', 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $view === 'yapildi', 'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5' => $view !== 'yapildi'])>Yapıldı (30 gün)</button>
        <span class="ml-1 text-gray-500">{{ $total }} iş{{ $brandName === null && count($sections) > 1 ? ', '.(count($sections) + $hiddenSections).' marka' : '' }}</span>
    </div>

    @if(! $embedded && $view === 'acik' && ($onDesk['brand'] > 0 || ($onDesk['preparing'] ?? 0) > 0))
        <a href="{{ route('operator.repair', array_filter(['marka' => $brand])) }}" class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-200 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/5" data-on-desk>
            @php
                $deskLine = 'Hazırlanmış '.$onDesk['brand'].' düzeltme Onarım masasında onayını bekliyor';
                if (($onDesk['preparing'] ?? 0) > 0) {
                    $deskLine .= '; '.number_format($onDesk['preparing'], 0, ',', '.').' sayfa düzeltmesini sistem hazırlıyor (saatte '.\App\Services\Repair\RepairPreparer::BATCH_PAGES_PER_RUN.' sayfa), hazır olan masaya gelir';
                }
            @endphp
            <span title="Sayfa düzeltmeleri burada tekrar gösterilmez.">{{ $deskLine }}.</span>
            <span class="font-medium text-gray-900 dark:text-white">Onarım masasını aç</span>
        </a>
    @endif

    @if($setup !== [] && array_sum($setup) > 0 && ($brand === null || ($setup[$brand] ?? 0) > 0))
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400" data-setup-strip>
            <span class="font-medium text-gray-700 dark:text-gray-300">Marka kurulumu:</span>
            @foreach($brands as $b)
                @if(($setup[$b->id] ?? 0) > 0 && ($brand === null || $brand === (int) $b->id))
                    <a href="{{ route('operator.brand', $b->id) }}" class="text-gray-700 hover:underline dark:text-gray-300">{{ $b->name }} <span class="tabular-nums text-gray-500">{{ $setup[$b->id] }}</span></a>
                @endif
            @endforeach
            <button type="button" wire:click="setTab('kurulum')" class="link-neutral ml-auto">Kurulum işlerini listele</button>
        </div>
    @endif

    @if($queue !== null)
        @include('livewire.operator.work.partials.content-queue')
        @if($sections !== [])<h2 class="section-title pt-2 text-gray-900 dark:text-white">Diğer SEO işleri</h2>@endif
    @endif

    @include('livewire.operator.work.partials.work-groups')
</div>
