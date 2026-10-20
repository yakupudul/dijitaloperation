@php
    /** The create form is a real separate object (an inline edit form), so it keeps the hairline `surface`. */
    $card = 'surface p-5';
    $select = 'mt-1 block rounded-lg border-gray-200 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $input = 'mt-1 block w-full rounded-lg border-gray-200 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $pill = fn (bool $on) => $on ? 'rounded-lg bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5';
    $active = array_sum(array_diff_key($counts, ['archived' => 0]));
@endphp

<div class="space-y-8">
    <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1 space-y-1">
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Claude’un hazırladığı tüm stratejiler: kapsamı, bütçesi, dayandığı veri ve her sürümü. Claude yenisini yazarken markanın eski stratejilerini okur.">Stratejiler</h1>
            <p class="text-sm text-gray-500">{{ $active }} güncel strateji</p>
        </div>
        <button type="button" wire:click="$toggle('creating')" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-500" data-testid="strategy-new">{{ $creating ? 'Vazgeç' : 'Yeni strateji' }}</button>
    </div>

    @if ($creating)
        <form wire:submit="create" class="{{ $card }} space-y-3" data-testid="strategy-create">
            <p class="text-sm text-gray-500">Claude stratejileri buraya kendisi kaydeder. Elinde başka bir strateji metni varsa buraya yapıştır (Markdown; ## başlıklar içindekiler olur).</p>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-xs text-gray-500 md:col-span-2">Başlık<input type="text" wire:model="form.title" class="{{ $input }}" placeholder="OPC Klinik · All-on-4/6 yurt dışı Google Ads">@error('form.title')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                <label class="text-xs text-gray-500">Marka<select wire:model="form.brand_id" class="{{ $select }} w-full"><option value="">Sistem geneli</option>@foreach ($allBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label class="text-xs text-gray-500">Kanal<select wire:model="form.channel" class="{{ $select }} w-full">@foreach ($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label class="text-xs text-gray-500">Hizmet<input type="text" wire:model="form.service" class="{{ $input }}" placeholder="All-on-4 / All-on-6"></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-xs text-gray-500">Bütçe<input type="text" inputmode="decimal" wire:model="form.budget_amount" class="{{ $input }}" placeholder="4000">@error('form.budget_amount')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                    <label class="text-xs text-gray-500">TRY<select wire:model="form.budget_period" class="{{ $select }}">@foreach ($periods as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                </div>
                <label class="text-xs text-gray-500 md:col-span-2">Hedef pazarlar (virgülle)<input type="text" wire:model="form.markets" class="{{ $input }}" placeholder="Birleşik Krallık, Almanya, İtalya"></label>
                <label class="text-xs text-gray-500">Diller (virgülle)<input type="text" wire:model="form.languages" class="{{ $input }}" placeholder="İngilizce, Almanca"></label>
            </div>
            <label class="block text-xs text-gray-500">Özet<textarea wire:model="form.summary" rows="2" class="{{ $input }}"></textarea></label>
            <label class="block text-xs text-gray-500">Strateji metni<textarea wire:model="form.body" rows="12" class="{{ $input }} font-mono"></textarea>@error('form.body')<span class="text-error-700">{{ $message }}</span>@enderror</label>
            <button type="submit" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-500">Kaydet</button>
        </form>
    @endif

    <div class="flex flex-wrap items-end gap-3" data-testid="strategy-filters">
        <nav class="flex flex-wrap gap-1" aria-label="Durum">
            <button type="button" wire:click="$set('status', '')" class="{{ $pill($status === '') }}">Güncel <span class="text-xs text-gray-500">{{ $active }}</span></button>
            @foreach ($statuses as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')" class="{{ $pill($status === $key) }}">{{ $label }} <span class="text-xs text-gray-500">{{ $counts[$key] ?? 0 }}</span></button>
            @endforeach
        </nav>
        <span class="flex-1"></span>
        <label class="text-xs text-gray-500">Marka<select wire:model.live="brand" class="{{ $select }}"><option value="">Tüm markalar</option>@foreach ($filterBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        <label class="text-xs text-gray-500">Kanal<select wire:model.live="channel" class="{{ $select }}"><option value="">Tüm kanallar</option>@foreach ($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <label class="text-xs text-gray-500">Ara<input type="search" wire:model.live.debounce.400ms="search" class="{{ $select }}" placeholder="Başlık, hizmet…"></label>
    </div>

    @if ($strategies->isEmpty())
        <div class="py-6 text-sm text-gray-500">
            @if ($brand !== '' || $channel !== '' || $status !== '' || trim($search) !== '')
                Bu filtrede strateji yok.
            @else
                Henüz strateji yok. Claude’dan bir strateji istediğinde (ör. “OPC için yurt dışı Google Ads stratejisi çıkar”) burada belirir.
            @endif
        </div>
    @else
        <ul class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-testid="strategy-list">
            @foreach ($strategies as $s)
                @php
                    $markets = $s->markets ?? [];
                    $scopeLine = array_filter([$s->budgetLabel(), implode(', ', array_slice($markets, 0, 4)).(count($markets) > 4 ? ' +'.(count($markets) - 4) : ''), implode(', ', $s->languages ?? [])]);
                @endphp
                <li>
                    <a href="{{ route('operator.strategy', ['strategyId' => $s->id]) }}" wire:navigate class="flex flex-col gap-1 py-3 transition hover:bg-gray-50 sm:flex-row sm:items-start sm:gap-6 dark:hover:bg-white/[0.03]">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500">
                                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $s->brand?->name ?? 'Sistem geneli' }}</span>
                                <span>{{ $s->channelLabel() }}@if ($s->service), {{ $s->service }}@endif</span>
                            </p>
                            <p class="mt-0.5 text-sm font-medium leading-snug text-gray-900 dark:text-white">{{ $s->title }}</p>
                            @if ($s->summary)<p class="mt-0.5 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">{{ $s->summary }}</p>@endif
                            @if ($scopeLine !== [])<p class="mt-1 text-xs text-gray-700 dark:text-gray-300">{{ implode('. ', $scopeLine) }}</p>@endif
                        </div>
                        <div class="flex shrink-0 flex-row items-center gap-3 sm:flex-col sm:items-end sm:gap-1">
                            @include('livewire.operator.strategy.partials.status', ['status' => $s->status, 'label' => $s->statusLabel()])
                            <span class="text-xs tabular-nums text-gray-500">v{{ $s->current_version }}@if ($s->versions_count > 1), {{ $s->versions_count }} sürüm @endif {{ $s->updated_at?->format('d.m.Y') }}</span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="text-xs text-gray-500">Meta’da diğer markaların kazanan kampanyalarından hesaplanan tarif için: <a href="{{ route('operator.meta-strategy') }}" wire:navigate class="link-neutral">Meta › Strateji öner</a></p>
</div>
