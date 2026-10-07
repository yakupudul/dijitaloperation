@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $ghost = 'inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800';
    $tabClass = fn (bool $active): string => $active
        ? '-mb-px h-11 shrink-0 border-b-2 border-gray-900 text-sm font-semibold text-gray-900 dark:border-white dark:text-white'
        : '-mb-px h-11 shrink-0 border-b-2 border-transparent text-sm font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
@endphp
<div class="space-y-5 text-sm dark:text-gray-200" data-brand-screen>
    @include('livewire.demo.partials.flash')

    <header class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <a href="{{ route('operator.brands') }}" wire:navigate class="text-xs text-gray-500 hover:text-brand-600" aria-label="Markalar">←</a>
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $brandModel->name }}</h1>
                    @if ($operational)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" data-brand-state="active"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Aktif</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300" data-brand-state="passive" title="{{ \App\Support\ServiceScope::NOT_SERVED }}"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Pasif</span>
                    @endif
                </div>
                <p class="text-xs text-gray-500">
                    @if ($customer)<a href="{{ route('operator.customer', ['customerId' => $customer->id]) }}" wire:navigate class="hover:underline">{{ $customer->name }}</a>@endif
                    @if ($sectors !== []) · {{ implode(', ', $sectors) }}@endif
                    @if ($areas !== []) · {{ implode(' · ', array_slice($areas, 0, 3)) }}@if (count($areas) > 3) +{{ count($areas) - 3 }}@endif @endif
                    @foreach ($websites as $website)
                        · <a href="{{ route('operator.website', ['assetId' => $website->id]) }}" wire:navigate data-open-website="{{ $website->id }}" title="Siteyi aç" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $website->domain ?: $website->name }}</a>
                    @endforeach
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($tab === 'ozet')
                    <div class="inline-flex rounded-lg bg-gray-100 p-0.5 dark:bg-gray-800" role="group" aria-label="Dönem" data-period-selector>
                        @foreach (\App\Services\Operator\BrandOverviewReader::PERIODS as $periodDays => $periodLabel)
                            <button type="button" wire:click="setPeriod('last_{{ $periodDays }}')" aria-pressed="{{ $days === $periodDays ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1.5 text-xs font-medium transition', 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-white' => $days === $periodDays, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $days !== $periodDays])>{{ $periodLabel }}</button>
                        @endforeach
                    </div>
                @endif
                @if ($tab === 'varliklar')
                    <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="{{ $ghost }}">Varlık ekle</a>
                @endif
                @if ($tab === 'ayarlar')
                    <a href="{{ route('operator.brand.edit', ['brandId' => $brandModel->id]) }}" wire:navigate class="{{ $ghost }}">Düzenle</a>
                @endif
                <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate data-auto-setup @class(['inline-flex h-9 items-center rounded-lg px-3 text-sm font-semibold', 'bg-brand-500 text-white hover:bg-brand-600' => ! $checklist['complete'], $ghost => $checklist['complete']])>Otomatik kur</a>
            </div>
        </div>

        <nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Sekmeler">
            <div class="flex min-w-max items-center gap-x-6 border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Marka">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" class="{{ $tabClass($tab === $key) }}">{{ $label }}</button>
                @endforeach
            </div>
        </nav>
    </header>

    @if ($tab === 'ozet')
        @include('livewire.operator.portfolio.partials.brand-overview')
    @elseif ($channelComponent !== null)
        @include('livewire.operator.portfolio.partials.brand-workspace')
    @elseif ($tab === 'karne')
        <livewire:operator.workspace.brand-scorecard-tab :brand-id="(int) $brandModel->id" :key="'brand-scorecard-'.$brandModel->id" />
    @elseif ($tab === 'dosya')
        <livewire:operator.workspace.brand-dossier-tab :brand-id="(int) $brandModel->id" :key="'brand-dossier-'.$brandModel->id" />
    @elseif ($tab === 'varliklar')
        @include('livewire.operator.portfolio.partials.brand-assets')
    @else
        {{-- ============================================================ AYARLAR --}}
        <div class="inline-flex rounded-lg bg-gray-100 p-0.5 dark:bg-gray-800" role="group" aria-label="Ayarlar" data-settings-nav>
            @foreach (\App\Livewire\Operator\Portfolio\BrandShow::SETTINGS as $key => $label)
                <button type="button" wire:click="setSub('{{ $key }}')" data-sub="{{ $key }}" aria-pressed="{{ $sub === $key ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1.5 text-xs font-medium transition', 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-white' => $sub === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $sub !== $key])>{{ $label }}</button>
            @endforeach
        </div>

        @if ($sub === 'marka')
            @include('livewire.operator.portfolio.partials.brand-info')
        @else
            <section class="{{ $card }}" data-brand-files>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Dosyalar</h2>
                        <p class="text-xs text-gray-500">Sözleşme, teklif, logo, marka kılavuzu gibi bu markaya ait dosyalar.</p>
                    </div>
                    <a href="{{ route('operator.files', ['scope' => 'brand', 'brand' => $brandModel->id]) }}" wire:navigate class="inline-flex h-9 items-center rounded-lg bg-brand-500 px-3 text-sm font-semibold text-white hover:bg-brand-600">Dosya yükle</a>
                </div>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($brandFiles as $file)
                        <li wire:key="brand-file-{{ $file->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-800 dark:text-white/90">{{ $file->original_name }}</p>
                                <p class="text-xs text-gray-500">{{ $file->created_at?->format('d.m.Y') }} · {{ number_format(((int) $file->size) / 1024, 0, ',', '.') }} KB @if ($file->description) · {{ $file->description }}@endif</p>
                            </div>
                            <a href="{{ route('operator.files.download', $file) }}" class="text-xs font-medium text-brand-600 hover:underline">İndir</a>
                        </li>
                    @empty
                        <li class="py-6 text-center text-gray-500">Bu markaya bağlı dosya yok.</li>
                    @endforelse
                </ul>
            </section>
        @endif
    @endif
</div>
