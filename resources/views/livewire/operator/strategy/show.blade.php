@php
    $field = 'mt-1 block h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $area = 'mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-[13px] text-gray-600 dark:text-gray-400';
    $isOld = $shown !== null && $shown->version !== $strategy->current_version;
    $statusHint = ['draft' => 'Claude hazırladı, senin kararını bekliyor.', 'approved' => 'Onayladın; kampanya kurulurken bu belge esas.', 'active' => 'Uygulamada; sonuçlar geldikçe yeni sürüm açılır.', 'archived' => 'Arşivde; yalnız bakmak için.'][$strategy->status] ?? '';
    $facts = [
        ['Marka', $strategy->brand?->name ?? 'Sistem geneli'],
        ['Kanal', $strategy->channelLabel()],
        ['Hizmet', $strategy->service ?? '—'],
        ['Bütçe', $strategy->budgetLabel() ?? '—'],
        ['Diller', implode(', ', $strategy->languages ?? []) ?: '—'],
        ['Sürüm', 'v'.$strategy->current_version.', '.$strategy->updated_at?->format('d.m.Y')],
    ];
@endphp

<div class="space-y-10" x-data="{ active: '', spy() { const hs = [...$el.querySelectorAll('.strategy-doc h2[id], .strategy-doc h3[id]')]; const top = window.scrollY + 120; let cur = ''; for (const h of hs) { if (h.offsetTop <= top) cur = h.id; } this.active = cur; } }" x-init="spy()" @scroll.window.passive="spy()">
    {{-- Head: where you are, the title, the status and the one decision. --}}
    <section class="space-y-6" data-testid="strategy-header">
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
            <div class="min-w-0 flex-1 basis-[28rem]">
                <p class="text-[13px] text-gray-500"><a href="{{ route('operator.strategies') }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">Stratejiler</a>@if ($strategy->brand) / <a href="{{ route('operator.strategies', ['marka' => $strategy->brand_id]) }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">{{ $strategy->brand->name }}</a>@endif</p>
                <h1 class="page-title mt-1">{{ $strategy->title }}</h1>
                <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                    @include('livewire.operator.strategy.partials.status', ['status' => $strategy->status, 'label' => $strategy->statusLabel()])
                    <span class="text-[13px] text-gray-500">{{ $statusHint }}</span>
                </p>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto" data-testid="strategy-status-actions">
                @if ($strategy->status === 'draft')
                    <button type="button" wire:click="setStatus('approved')" class="btn-primary flex-1 sm:flex-none">Onayla</button>
                @elseif ($strategy->status === 'approved')
                    <button type="button" wire:click="setStatus('active')" class="btn-primary flex-1 sm:flex-none">Uygulamaya başladım</button>
                    <button type="button" wire:click="setStatus('draft')" class="btn">Taslağa al</button>
                @elseif ($strategy->status === 'archived')
                    <button type="button" wire:click="setStatus('draft')" class="btn">Arşivden çıkar</button>
                @else
                    <button type="button" wire:click="setStatus('approved')" class="btn">Uygulama durdu</button>
                @endif
                @if ($strategy->status !== 'archived')
                    <button type="button" wire:click="setStatus('archived')" class="btn-quiet">Arşivle</button>
                @endif
            </div>
        </div>

        @if ($strategy->summary)<p class="max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $strategy->summary }}</p>@endif

        {{-- The whole frame at a glance. --}}
        <dl class="metric-strip grid-cols-2 sm:grid-cols-3 lg:grid-cols-6" data-testid="strategy-facts">
            @foreach ($facts as [$factLabel, $value])
                <div class="min-w-0 bg-white px-4 py-3 dark:bg-gray-900">
                    <dt class="text-xs text-gray-500">{{ $factLabel }}</dt>
                    <dd class="mt-0.5 flex min-w-0 items-center gap-1.5 text-sm font-medium text-gray-900 dark:text-white" title="{{ $value }}">
                        @if ($loop->first && $strategy->brand)<x-ta.brand-avatar :name="$strategy->brand->name" :logo="$strategy->brand->logo_url" size="xs" />@endif
                        <span class="truncate">{{ $value }}</span>
                    </dd>
                </div>
            @endforeach
            @if ($strategy->markets)
                <div class="col-span-full min-w-0 bg-white px-4 py-3 dark:bg-gray-900">
                    <dt class="text-xs text-gray-500">Hedef pazarlar</dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ implode(', ', $strategy->markets) }}</dd>
                </div>
            @endif
        </dl>
    </section>

    @if ($flash !== '')
        <p class="tone-info rounded-lg px-3 py-2 text-sm" data-testid="strategy-flash">{{ $flash }}</p>
    @endif

    @if ($editing)
        <form wire:submit="saveScope" class="card space-y-4 p-5" data-testid="strategy-scope-form">
            <h2 class="section-title">Kapsamı düzenle</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="{{ $label }} md:col-span-2">Başlık<input type="text" wire:model="scope.title" class="{{ $field }}">@error('scope.title')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                <label class="{{ $label }}">Marka<select wire:model="scope.brand_id" class="{{ $field }}"><option value="">Sistem geneli</option>@foreach ($allBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label class="{{ $label }}">Kanal<select wire:model="scope.channel" class="{{ $field }}">@foreach ($channels as $key => $channelLabel)<option value="{{ $key }}">{{ $channelLabel }}</option>@endforeach</select></label>
                <label class="{{ $label }}">Hizmet<input type="text" wire:model="scope.service" class="{{ $field }}"></label>
                <div class="flex gap-2">
                    <label class="{{ $label }} flex-1">Bütçe<input type="text" inputmode="decimal" wire:model="scope.budget_amount" class="{{ $field }}">@error('scope.budget_amount')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                    <label class="{{ $label }} w-16">Para<input type="text" wire:model="scope.budget_currency" class="{{ $field }}"></label>
                    <label class="{{ $label }}">Dönem<select wire:model="scope.budget_period" class="{{ $field }}"><option value="">—</option>@foreach ($periods as $key => $periodLabel)<option value="{{ $key }}">{{ $periodLabel }}</option>@endforeach</select></label>
                </div>
                <label class="{{ $label }} md:col-span-2">Hedef pazarlar (virgülle)<input type="text" wire:model="scope.markets" class="{{ $field }}"></label>
                <label class="{{ $label }}">Diller (virgülle)<input type="text" wire:model="scope.languages" class="{{ $field }}"></label>
            </div>
            <label class="{{ $label }}">Özet<textarea wire:model="scope.summary" rows="3" class="{{ $area }}"></textarea></label>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Kaydet</button>
                <button type="button" wire:click="$set('editing', false)" class="btn">Vazgeç</button>
            </div>
        </form>
    @endif

    @if ($revising)
        <form wire:submit="saveRevision" class="card space-y-4 p-5" data-testid="strategy-revise">
            <div>
                <h2 class="section-title">Metni düzenle</h2>
                <p class="mt-0.5 text-[13px] text-gray-500">Kaydedince v{{ $versions->max('version') + 1 }} olur; eski sürümler kalır.</p>
            </div>
            <textarea wire:model="draftBody" rows="28" class="{{ $area }} font-mono text-xs leading-6"></textarea>
            @error('draftBody')<span class="text-xs text-error-700">{{ $message }}</span>@enderror
            <label class="{{ $label }}">Ne değişti<input type="text" wire:model="changeNote" class="{{ $field }}" placeholder="Örn. Almanca kampanya bütçesi güncellendi"></label>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Yeni sürüm olarak kaydet</button>
                <button type="button" wire:click="$set('revising', false)" class="btn">Vazgeç</button>
            </div>
        </form>
    @else
        @if ($isOld)
            <p class="tone-look rounded-lg px-3 py-2 text-sm">Eski sürüm görüntüleniyor: <strong class="font-medium">v{{ $shown->version }}</strong> ({{ $shown->created_at?->format('d.m.Y H:i') }}@if ($shown->change_note), {{ $shown->change_note }}@endif). <button type="button" wire:click="$set('version', null)" class="font-medium underline">Geçerli sürüme dön</button></p>
        @endif

        <div class="grid gap-x-10 gap-y-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
            {{-- Table of contents: sticky beside the text on a wide screen, folded above it on a phone. --}}
            <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                @if ($toc !== [])
                    <details class="group card lg:overflow-visible lg:rounded-none lg:border-0 lg:bg-transparent lg:shadow-none dark:lg:bg-transparent" wire:ignore.self x-init="if (window.innerWidth >= 1024) $el.open = true">
                        <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-gray-900 lg:hidden dark:text-white">
                            İçindekiler <span class="text-xs font-normal text-gray-500">{{ $readMinutes }} dk okuma</span>
                        </summary>
                        <nav class="strategy-toc border-t border-gray-200 px-2 py-2 lg:max-h-[75vh] lg:overflow-y-auto lg:border-0 lg:p-0 dark:border-gray-800" aria-label="İçindekiler" data-testid="strategy-toc">
                            <p class="mb-2 hidden px-2 text-[13px] font-medium text-gray-900 lg:block dark:text-white">İçindekiler <span class="font-normal text-gray-500">{{ $readMinutes }} dk</span></p>
                            <ul class="space-y-0.5 text-[13px]">
                                @foreach ($toc as $h)
                                    <li><a href="#{{ $h['id'] }}" :class="active === '{{ $h['id'] }}' ? 'is-active' : ''" @class(['block rounded-md py-1 leading-5 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white', 'px-2' => $h['level'] === 2, 'pr-2 pl-5 text-xs' => $h['level'] === 3])>{{ $h['text'] }}</a></li>
                                @endforeach
                            </ul>
                        </nav>
                    </details>
                @endif
            </aside>

            <div class="min-w-0 space-y-10">
                {{-- The document. --}}
                <article class="card" data-testid="strategy-body">
                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 py-2.5 dark:border-gray-800">
                        <span class="min-w-0 flex-1 text-xs text-gray-500">v{{ $shown?->version }}, {{ $shown?->author }}, {{ $shown?->created_at?->format('d.m.Y H:i') }}</span>
                        @unless ($isOld)<button type="button" wire:click="startRevision" class="btn">Metni düzenle</button>@endunless
                        <button type="button" wire:click="download" class="btn-quiet">İndir (.md)</button>
                    </div>
                    <div class="strategy-doc px-5 py-6 sm:px-8 [&>h1:first-child+h2]:mt-0 [&>h1:first-child+h2]:border-t-0 [&>h1:first-child+h2]:pt-0 [&>h2:first-child]:mt-0 [&>h2:first-child]:border-t-0 [&>h2:first-child]:pt-0">{!! $html !!}</div>
                </article>

                @if ($planCounts !== null)
                    <section data-testid="strategy-ads-editor">
                        <div class="section-head">
                            <h2 class="section-title">Google Ads Editor <span class="section-count">{{ $planCounts['campaigns'] }} kampanya</span></h2>
                        </div>
                        <div class="card">
                            <div class="card-row flex-wrap items-start sm:flex-nowrap">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $planCounts['campaigns'] }} kampanya, {{ $planCounts['ad_groups'] }} reklam grubu, {{ $planCounts['keywords'] }} anahtar kelime, {{ $planCounts['negatives'] }} negatif, {{ $planCounts['ads'] }} reklam</p>
                                    @if ($strategy->isExportable())
                                        <p class="mt-0.5 text-[13px] text-gray-500" title="Konum seçeneği, hariç konum, zamanlama ve yaş ayarları dosyaya girmez; Excel'in Notlar sayfasında ve yorum sütununda yazar.">Kampanyalar duraklatılmış gelir; sen açarsın. Editor'da Hesap, İçe aktar, Dosyadan.</p>
                                    @else
                                        <p class="mt-0.5 text-[13px] text-gray-500">Kampanyalar duraklatılmış gelir; sen açarsın. Stratejiyi onaylayınca Ads Editor dosyası burada indirilir.</p>
                                    @endif
                                </div>
                                @if ($strategy->isExportable())
                                    <div class="flex w-full shrink-0 gap-2 sm:w-auto">
                                        <a href="{{ route('operator.strategy.ads-editor', ['strategyId' => $strategy->id, 'format' => 'csv']) }}" class="btn-primary flex-1 sm:flex-none">Ads Editor dosyası (.csv)</a>
                                        <a href="{{ route('operator.strategy.ads-editor', ['strategyId' => $strategy->id, 'format' => 'xlsx']) }}" class="btn flex-1 sm:flex-none">Excel (.xlsx)</a>
                                    </div>
                                @endif
                            </div>
                            @foreach ($strategy->plan['campaigns'] as $c)
                                <div class="card-row py-2.5! text-[13px]">
                                    <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-white" title="{{ $c['name'] }}">{{ $c['name'] }}</span>
                                    @if (isset($c['daily_budget']))<span class="shrink-0 tabular-nums text-gray-500">{{ number_format((float) $c['daily_budget'], 0, ',', '.') }}/gün</span>@endif
                                    <span class="shrink-0 tabular-nums text-gray-500">{{ count($c['ad_groups'] ?? []) }} grup</span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="grid gap-10 md:grid-cols-2">
                    <section class="min-w-0" data-testid="strategy-evidence">
                        <div class="section-head"><h2 class="section-title">Dayanak @if (! empty($shown?->evidence))<span class="section-count">{{ count($shown->evidence) }}</span>@endif</h2></div>
                        <ul class="card">
                            @if (empty($shown?->evidence))
                                <li><x-ta.empty-state size="sm" title="Ayrı dayanak listesi yok" message="Bu sürümün dayandığı veriler metnin içinde." /></li>
                            @else
                                @foreach ($shown->evidence as $row)
                                    <li class="card-row items-start"><div class="min-w-0"><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['label'] ?? '' }}</p>@if (! empty($row['detail']))<p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>@endif</div></li>
                                @endforeach
                            @endif
                        </ul>
                    </section>

                    <section class="min-w-0" data-testid="strategy-versions">
                        <div class="section-head"><h2 class="section-title">Sürümler ({{ $versions->count() }})</h2></div>
                        <ul class="card">
                            @foreach ($versions as $v)
                                <li class="card-row items-start">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">v{{ $v->version }} @if ($v->version === $strategy->current_version)<span class="font-normal text-gray-500">geçerli</span>@elseif ($v->version === $shown?->version)<span class="font-normal text-gray-500">açık</span>@endif</p>
                                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $v->change_note ?? '—' }}, {{ $v->author }}, {{ $v->created_at?->format('d.m.Y H:i') }}</p>
                                    </div>
                                    <div class="flex shrink-0 gap-1">
                                        @if ($v->version !== $shown?->version)
                                            <button type="button" wire:click="$set('version', {{ $v->version === $strategy->current_version ? 'null' : $v->version }})" class="btn-quiet h-7! px-2! text-xs!">Oku</button>
                                        @endif
                                        @if ($v->version !== $strategy->current_version)
                                            <button type="button" wire:click="restore({{ $v->version }})" wire:confirm="v{{ $v->version }} yeniden geçerli sürüm olsun mu? (Yeni bir sürüm olarak eklenir.)" class="btn h-7! px-2.5! text-xs!">Geri al</button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="min-w-0" data-testid="strategy-scope">
                        <div class="section-head">
                            <h2 class="section-title">Kapsam</h2>
                            @unless ($editing)<button type="button" wire:click="startEdit" class="link-neutral ml-auto text-[13px]">Kapsamı düzenle</button>@endunless
                        </div>
                        <dl class="card">
                            @if ($strategy->request)<div class="card-row block"><dt class="text-xs text-gray-500">İstek</dt><dd class="mt-0.5 line-clamp-6 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300" title="{{ $strategy->request }}">{{ $strategy->request }}</dd></div>@endif
                            <div class="card-row block"><dt class="text-xs text-gray-500">Durum</dt><dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $strategy->statusLabel() }}, {{ $strategy->status_changed_at?->format('d.m.Y') }}</dd></div>
                            @if ($strategy->source_ref)<div class="card-row block"><dt class="text-xs text-gray-500">Nerede hazırlandı</dt><dd class="mt-0.5 break-all text-sm text-gray-700 dark:text-gray-300">@if (str_starts_with($strategy->source_ref, 'https://'))<a href="{{ $strategy->source_ref }}" target="_blank" rel="noopener" class="link-neutral">Proje konuşması</a>@else{{ $strategy->source_ref }}@endif</dd></div>@endif
                        </dl>
                    </section>

                    @if ($others->isNotEmpty())
                        <section class="min-w-0">
                            <div class="section-head"><h2 class="section-title">{{ $strategy->brand->name }}, diğer stratejiler</h2></div>
                            <ul class="card">
                                @foreach ($others as $o)
                                    <li class="card-row py-3!"><a href="{{ route('operator.strategy', ['strategyId' => $o->id]) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm text-gray-900 hover:underline dark:text-white">{{ $o->title }}</a>@include('livewire.operator.strategy.partials.status', ['status' => $o->status, 'label' => $o->statusLabel()])</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
