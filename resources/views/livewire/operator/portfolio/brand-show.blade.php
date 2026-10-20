@php
    // Panel concept (2026-10-20): the brand page answers "how is this brand doing and what does it need from me".
    // Head = the brand's own logo, its name and one gray line; the tabs underline; each tab is sections of white cards.
    // Canlı panel (2026-10-20): the period and the settings sections are pill tabs (seg), the active one near-black.
    $segment = fn (bool $active): string => $active ? 'seg seg-active h-8! px-3!' : 'seg h-8! px-3!';
    $segmentGroup = 'inline-flex flex-wrap items-center gap-1.5';
    $headLine = collect([
        $sectors !== [] ? e(implode(', ', $sectors)) : null,
        $areas !== [] ? e(implode(', ', array_slice($areas, 0, 3))).(count($areas) > 3 ? ' +'.(count($areas) - 3) : '') : null,
    ])->filter()->implode(', ');
@endphp
<div class="space-y-8 text-sm dark:text-gray-200" data-brand-screen>
    @include('livewire.demo.partials.flash')

    <header class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
            <div class="flex min-w-0 flex-1 items-center gap-3.5">
                <a href="{{ route('operator.brands') }}" wire:navigate class="-ml-1 hidden shrink-0 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-900 sm:block dark:hover:bg-white/5 dark:hover:text-white" aria-label="Markalar" title="Markalar"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12.5 15 7.5 10l5-5" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
                <x-ta.brand-avatar :name="$brandModel->name" :logo="$brandModel->logo_url" size="lg" class="h-12! w-12! rounded-xl! text-base!" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 class="page-title min-w-0 break-words">{{ $brandModel->name }}</h1>
                        @if ($operational)
                            <x-ta.pill tone="ok" data-brand-state="active">Aktif</x-ta.pill>
                        @else
                            <x-ta.pill tone="info" data-brand-state="passive" title="{{ \App\Support\ServiceScope::NOT_SERVED }}">Pasif</x-ta.pill>
                        @endif
                    </div>
                    <p class="page-sub flex flex-wrap items-baseline gap-x-3 gap-y-0.5 break-words">
                        @if ($headLine !== '')<span class="min-w-0">{!! $headLine !!}</span>@endif
                        @foreach ($websites as $website)
                            <a href="{{ route('operator.website', ['assetId' => $website->id]) }}" wire:navigate data-open-website="{{ $website->id }}" title="Siteyi aç" class="link-neutral min-w-0 break-all">{{ $website->domain ?: $website->name }}</a>
                        @endforeach
                    </p>
                </div>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0">
                <x-operator.quick-access :groups="$quickAccess" />
                @if ($tab === 'ozet')
                    <div class="{{ $segmentGroup }}" role="group" aria-label="Dönem" data-period-selector>
                        @foreach (\App\Services\Operator\BrandOverviewReader::PERIODS as $periodDays => $periodLabel)
                            <button type="button" wire:click="setPeriod('last_{{ $periodDays }}')" aria-pressed="{{ $days === $periodDays ? 'true' : 'false' }}" class="{{ $segment($days === $periodDays) }}">{{ $periodLabel }}</button>
                        @endforeach
                    </div>
                @endif
                @if ($tab === 'varliklar')
                    <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="btn">Varlık ekle</a>
                @endif
                @if ($tab === 'ayarlar')
                    <a href="{{ route('operator.brand.edit', ['brandId' => $brandModel->id]) }}" wire:navigate class="btn">Düzenle</a>
                @endif
                {{-- The page's one main action while the brand is not set up; elsewhere (and once set up) a bordered button. --}}
                <x-ta.button size="sm" :variant="! $checklist['complete'] && $tab === 'ozet' ? 'primary' : 'outline'" :href="route('operator.brand.setup', ['brand' => $brandModel->id])" wire:navigate data-auto-setup>Otomatik kur</x-ta.button>
            </div>
        </div>

        <nav class="no-scrollbar -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Sekmeler">
            <div class="flex min-w-max items-center gap-x-2 border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Marka">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['tab', 'tab-active' => $tab === $key])>{{ $label }}</button>
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
        {{-- Epikriz: the record every AI step reads (layers, questions), then what the AI knows (Marka bilgi kartı), whether the brand looks the same everywhere (Kimlik tutarlılığı), then the file itself. --}}
        <div class="space-y-10" data-brand-file-tab>
            <livewire:operator.portfolio.brand-epicrisis-tab lazy :brand-id="(int) $brandModel->id" :key="'brand-epicrisis-'.$brandModel->id" />
            <livewire:operator.portfolio.brand-facts-card lazy :brand-id="(int) $brandModel->id" :key="'brand-facts-'.$brandModel->id" />
            <livewire:operator.portfolio.brand-identity-card lazy :brand-id="(int) $brandModel->id" :key="'brand-identity-'.$brandModel->id" />
            <livewire:operator.workspace.brand-dossier-tab :brand-id="(int) $brandModel->id" :key="'brand-dossier-'.$brandModel->id" />
        </div>
    @elseif ($tab === 'varliklar')
        @include('livewire.operator.portfolio.partials.brand-assets')
    @else
        {{-- ============================================================ AYARLAR --}}
        <div class="{{ $segmentGroup }}" role="group" aria-label="Ayarlar" data-settings-nav>
            @foreach (\App\Livewire\Operator\Portfolio\BrandShow::SETTINGS as $key => $label)
                <button type="button" wire:click="setSub('{{ $key }}')" data-sub="{{ $key }}" aria-pressed="{{ $sub === $key ? 'true' : 'false' }}" class="{{ $segment($sub === $key) }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($sub === 'marka')
            @include('livewire.operator.portfolio.partials.brand-info')
        @else
            <section data-brand-files>
                <div class="section-head">
                    <h2 class="section-title" title="Sözleşme, teklif, logo, marka kılavuzu gibi bu markaya ait dosyalar.">Dosyalar <span class="section-count">{{ count($brandFiles) }}</span></h2>
                    <a href="{{ route('operator.files', ['scope' => 'brand', 'brand' => $brandModel->id]) }}" wire:navigate class="btn-primary ml-auto">Dosya yükle</a>
                </div>
                <ul class="card">
                    @forelse ($brandFiles as $file)
                        <li wire:key="brand-file-{{ $file->id }}" class="card-row">
                            <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $file->original_name }}</p>
                                <p class="mt-0.5 truncate text-[13px] text-gray-500">{{ $file->created_at?->format('d.m.Y') }}, {{ number_format(((int) $file->size) / 1024, 0, ',', '.') }} KB @if ($file->description), {{ $file->description }}@endif</p>
                            </div>
                            <a href="{{ route('operator.files.download', $file) }}" class="btn-quiet">İndir</a>
                        </li>
                    @empty
                        <li class="px-4 py-3.5 text-[13px] text-gray-500">Bu markaya bağlı dosya yok.</li>
                    @endforelse
                </ul>
            </section>
        @endif
    @endif
</div>
