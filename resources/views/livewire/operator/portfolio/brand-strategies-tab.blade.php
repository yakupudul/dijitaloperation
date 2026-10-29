@php
    $field = 'mt-1 block h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $area = 'mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-[13px] text-gray-600 dark:text-gray-400';
    $active = array_sum(array_diff_key($counts, ['archived' => 0]));
    $waiting = (int) ($counts['draft'] ?? 0);
@endphp

<div class="space-y-6" data-brand-strategies-tab>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $active }} güncel strateji{{ $waiting > 0 ? ', '.$waiting.' tanesi kararını bekliyor' : '' }}. Claude yenisini yazarken markanın eski stratejilerini okur.</p>
        <button type="button" wire:click="$toggle('creating')" class="{{ $creating ? 'btn' : 'btn-primary' }} w-full sm:w-auto" data-testid="strategy-new">{{ $creating ? 'Vazgeç' : 'Yeni strateji' }}</button>
    </div>

    @if ($creating)
        <form wire:submit="create" class="card space-y-4 p-5" data-testid="strategy-create">
            <div>
                <h2 class="section-title">Strateji metnini yapıştır</h2>
                <p class="mt-0.5 text-[13px] text-gray-500" title="Claude stratejileri buraya kendisi kaydeder.">Markdown; ## başlıklar içindekiler olur.</p>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="{{ $label }} md:col-span-2">Başlık<input type="text" wire:model="form.title" class="{{ $field }}" placeholder="All-on-4/6 yurt dışı Google Ads">@error('form.title')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                <label class="{{ $label }}">Kanal<select wire:model="form.channel" class="{{ $field }}">@foreach ($channels as $key => $label2)<option value="{{ $key }}">{{ $label2 }}</option>@endforeach</select></label>
                <label class="{{ $label }}">Hizmet<input type="text" wire:model="form.service" class="{{ $field }}" placeholder="All-on-4 / All-on-6"></label>
                <div class="flex gap-2">
                    <label class="{{ $label }} flex-1">Bütçe<input type="text" inputmode="decimal" wire:model="form.budget_amount" class="{{ $field }}" placeholder="4000">@error('form.budget_amount')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                    <label class="{{ $label }}">TRY<select wire:model="form.budget_period" class="{{ $field }}">@foreach ($periods as $key => $label2)<option value="{{ $key }}">{{ $label2 }}</option>@endforeach</select></label>
                </div>
                <label class="{{ $label }}">Diller (virgülle)<input type="text" wire:model="form.languages" class="{{ $field }}" placeholder="İngilizce, Almanca"></label>
                <label class="{{ $label }} md:col-span-3">Hedef pazarlar (virgülle)<input type="text" wire:model="form.markets" class="{{ $field }}" placeholder="Birleşik Krallık, Almanya, İtalya"></label>
            </div>
            <label class="{{ $label }}">Özet<textarea wire:model="form.summary" rows="2" class="{{ $area }}"></textarea></label>
            <label class="{{ $label }}">Strateji metni<textarea wire:model="form.body" rows="12" class="{{ $area }} font-mono text-[13px]"></textarea>@error('form.body')<span class="text-error-700">{{ $message }}</span>@enderror</label>
            <div class="flex justify-end"><button type="submit" class="btn-primary w-full sm:w-auto">Kaydet</button></div>
        </form>
    @endif

    <nav class="no-scrollbar flex gap-1.5 overflow-x-auto" aria-label="Durum" data-testid="strategy-filters">
        <button type="button" wire:click="$set('status', '')" @class(['seg', 'seg-active' => $status === ''])>Güncel <span @class(['text-xs tabular-nums', 'text-accent' => $status === '', 'text-gray-400 dark:text-gray-500' => $status !== ''])>{{ $active }}</span></button>
        @foreach ($statuses as $key => $statusLabel)
            <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['seg', 'seg-active' => $status === $key])>{{ $statusLabel }} <span @class(['text-xs tabular-nums', 'text-accent' => $status === $key, 'text-gray-400 dark:text-gray-500' => $status !== $key])>{{ $counts[$key] ?? 0 }}</span></button>
        @endforeach
    </nav>

    @if ($strategies->isEmpty())
        <div class="card">
            @if ($status !== '')
                <x-ta.empty-state icon="none" size="sm" title="Bu filtrede strateji yok." />
            @else
                <x-ta.empty-state icon="none" title="Bu marka için henüz strateji yok." message="Claude’dan bir strateji istediğinde (ör. “yurt dışı Google Ads stratejisi çıkar”) burada belirir." />
            @endif
        </div>
    @else
        <ul class="card" data-testid="strategy-list">
            @foreach ($strategies as $s)
                @php
                    $markets = $s->markets ?? [];
                    $scopeLine = array_filter([$s->budgetLabel(), implode(', ', array_slice($markets, 0, 4)).(count($markets) > 4 ? ' +'.(count($markets) - 4) : ''), implode(', ', $s->languages ?? [])]);
                @endphp
                <li class="card-row relative flex-wrap items-start transition hover:bg-gray-50 sm:flex-nowrap dark:hover:bg-white/[0.02]" wire:key="strategy-{{ $s->id }}">
                    <div class="min-w-0 flex-1 basis-56">
                        <a href="{{ route('operator.strategy', ['strategyId' => $s->id]) }}" wire:navigate class="block text-sm font-medium leading-snug text-gray-900 after:absolute after:inset-0 dark:text-white">{{ $s->title }}</a>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $s->channelLabel() }}@if ($s->service), {{ $s->service }}@endif</p>
                        @if ($s->summary)<p class="mt-1.5 line-clamp-2 text-[13px] text-gray-600 dark:text-gray-400">{{ $s->summary }}</p>@endif
                        @if ($scopeLine !== [])<p class="mt-1 text-xs text-gray-500">{{ implode('. ', $scopeLine) }}</p>@endif
                    </div>
                    <div class="flex shrink-0 items-center gap-3 sm:flex-col sm:items-end sm:gap-1">
                        @include('livewire.operator.strategy.partials.status', ['status' => $s->status, 'label' => $s->statusLabel()])
                        <span class="text-xs tabular-nums text-gray-500">v{{ $s->current_version }}@if ($s->versions_count > 1), {{ $s->versions_count }} sürüm @endif {{ $s->updated_at?->format('d.m.Y') }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="text-[13px] text-gray-500">Diğer markaların kazanan Meta kampanyalarından hesaplanan tarif için <a href="{{ route('operator.meta-strategy') }}" wire:navigate class="link-neutral">Strateji öner</a>.</p>
</div>
