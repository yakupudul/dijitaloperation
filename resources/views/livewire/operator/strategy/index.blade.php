@php
    $field = 'mt-1 block h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $area = 'mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-[13px] text-gray-600 dark:text-gray-400';
    $filter = 'h-8 w-full rounded-lg border border-gray-200 bg-white py-0 pl-3 text-[13px] text-gray-800 sm:w-auto dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $active = array_sum(array_diff_key($counts, ['archived' => 0]));
    $waiting = (int) ($counts['draft'] ?? 0);
@endphp

<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="page-title" title="Claude’un hazırladığı tüm stratejiler: kapsamı, bütçesi, dayandığı veri ve her sürümü. Claude yenisini yazarken markanın eski stratejilerini okur.">Stratejiler</h1>
            <p class="page-sub">{{ $active }} güncel strateji{{ $waiting > 0 ? ', '.$waiting.' tanesi kararını bekliyor' : '' }}.</p>
        </div>
        <button type="button" wire:click="$toggle('creating')" class="{{ $creating ? 'btn' : 'btn-primary' }} w-full sm:w-auto" data-testid="strategy-new">{{ $creating ? 'Vazgeç' : 'Yeni strateji' }}</button>
    </header>

    @if ($creating)
        <form wire:submit="create" class="card space-y-4 p-5" data-testid="strategy-create">
            <div>
                <h2 class="section-title">Strateji metnini yapıştır</h2>
                <p class="mt-0.5 text-[13px] text-gray-500" title="Claude stratejileri buraya kendisi kaydeder.">Markdown; ## başlıklar içindekiler olur.</p>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="{{ $label }} md:col-span-2">Başlık<input type="text" wire:model="form.title" class="{{ $field }}" placeholder="OPC Klinik, All-on-4/6 yurt dışı Google Ads">@error('form.title')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                <label class="{{ $label }}">Marka<select wire:model="form.brand_id" class="{{ $field }}"><option value="">Sistem geneli</option>@foreach ($allBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label class="{{ $label }}">Kanal<select wire:model="form.channel" class="{{ $field }}">@foreach ($channels as $key => $label2)<option value="{{ $key }}">{{ $label2 }}</option>@endforeach</select></label>
                <label class="{{ $label }}">Hizmet<input type="text" wire:model="form.service" class="{{ $field }}" placeholder="All-on-4 / All-on-6"></label>
                <div class="flex gap-2">
                    <label class="{{ $label }} flex-1">Bütçe<input type="text" inputmode="decimal" wire:model="form.budget_amount" class="{{ $field }}" placeholder="4000">@error('form.budget_amount')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                    <label class="{{ $label }}">TRY<select wire:model="form.budget_period" class="{{ $field }}">@foreach ($periods as $key => $label2)<option value="{{ $key }}">{{ $label2 }}</option>@endforeach</select></label>
                </div>
                <label class="{{ $label }} md:col-span-2">Hedef pazarlar (virgülle)<input type="text" wire:model="form.markets" class="{{ $field }}" placeholder="Birleşik Krallık, Almanya, İtalya"></label>
                <label class="{{ $label }}">Diller (virgülle)<input type="text" wire:model="form.languages" class="{{ $field }}" placeholder="İngilizce, Almanca"></label>
            </div>
            <label class="{{ $label }}">Özet<textarea wire:model="form.summary" rows="2" class="{{ $area }}"></textarea></label>
            <label class="{{ $label }}">Strateji metni<textarea wire:model="form.body" rows="12" class="{{ $area }} font-mono text-[13px]"></textarea>@error('form.body')<span class="text-error-700">{{ $message }}</span>@enderror</label>
            <div class="flex justify-end"><button type="submit" class="btn-primary w-full sm:w-auto">Kaydet</button></div>
        </form>
    @endif

    <section class="space-y-4">
        <div class="space-y-4" data-testid="strategy-filters">
            <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Durum">
                <button type="button" wire:click="$set('status', '')" @class(['tab', 'tab-active' => $status === ''])>Güncel <span class="font-normal text-gray-500">{{ $active }}</span></button>
                @foreach ($statuses as $key => $statusLabel)
                    <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['tab', 'tab-active' => $status === $key])>{{ $statusLabel }} <span class="font-normal text-gray-500">{{ $counts[$key] ?? 0 }}</span></button>
                @endforeach
            </nav>
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model.live="brand" aria-label="Marka" class="{{ $filter }} sm:max-w-64"><option value="">Tüm markalar</option>@foreach ($filterBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <select wire:model.live="channel" aria-label="Kanal" class="{{ $filter }}"><option value="">Tüm kanallar</option>@foreach ($channels as $key => $channelLabel)<option value="{{ $key }}">{{ $channelLabel }}</option>@endforeach</select>
                <input type="search" wire:model.live.debounce.400ms="search" aria-label="Ara" class="{{ $filter }} px-3 sm:ml-auto sm:w-56" placeholder="Başlık, hizmet ara">
            </div>
        </div>

        @if ($strategies->isEmpty())
            <div class="card">
                @if ($brand !== '' || $channel !== '' || $status !== '' || trim($search) !== '')
                    <x-ta.empty-state icon="none" size="sm" title="Bu filtrede strateji yok." />
                @else
                    <x-ta.empty-state icon="none" title="Henüz strateji yok." message="Claude’dan bir strateji istediğinde (ör. “OPC için yurt dışı Google Ads stratejisi çıkar”) burada belirir." />
                @endif
            </div>
        @else
            <ul class="card" data-testid="strategy-list">
                @foreach ($strategies as $s)
                    @php
                        $markets = $s->markets ?? [];
                        $scopeLine = array_filter([$s->budgetLabel(), implode(', ', array_slice($markets, 0, 4)).(count($markets) > 4 ? ' +'.(count($markets) - 4) : ''), implode(', ', $s->languages ?? [])]);
                    @endphp
                    <li class="card-row relative flex-wrap items-start transition hover:bg-gray-50 sm:flex-nowrap dark:hover:bg-white/[0.02]">
                        @if ($s->brand)
                            <x-ta.brand-avatar :name="$s->brand->name" :logo="$s->brand->logo_url" />
                        @else
                            <span class="row-icon h-7! w-7!" aria-hidden="true"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14"/><path d="M4 19a2 2 0 0 0 2 2h14V17H6a2 2 0 0 0-2 2z"/></svg></span>
                        @endif
                        <div class="min-w-0 flex-1 basis-56">
                            <a href="{{ route('operator.strategy', ['strategyId' => $s->id]) }}" wire:navigate class="block text-sm font-medium leading-snug text-gray-900 after:absolute after:inset-0 dark:text-white">{{ $s->title }}</a>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $s->brand?->name ?? 'Sistem geneli' }}, {{ $s->channelLabel() }}@if ($s->service), {{ $s->service }}@endif</p>
                            @if ($s->summary)<p class="mt-1.5 line-clamp-2 text-[13px] text-gray-600 dark:text-gray-400">{{ $s->summary }}</p>@endif
                            @if ($scopeLine !== [])<p class="mt-1 text-xs text-gray-500">{{ implode('. ', $scopeLine) }}</p>@endif
                        </div>
                        <div class="flex shrink-0 items-center gap-3 pl-[42px] sm:flex-col sm:items-end sm:gap-1 sm:pl-0">
                            @include('livewire.operator.strategy.partials.status', ['status' => $s->status, 'label' => $s->statusLabel()])
                            <span class="text-xs tabular-nums text-gray-500">v{{ $s->current_version }}@if ($s->versions_count > 1), {{ $s->versions_count }} sürüm @endif {{ $s->updated_at?->format('d.m.Y') }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="text-[13px] text-gray-500">Diğer markaların kazanan Meta kampanyalarından hesaplanan tarif için <a href="{{ route('operator.meta-strategy') }}" wire:navigate class="link-neutral">Strateji öner</a>.</p>
</div>
