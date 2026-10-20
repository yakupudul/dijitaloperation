@php
    /** Inline edit forms are real separate objects and keep the hairline `surface`; page sections are flat. */
    $card = 'surface p-5';
    $select = 'mt-1 block w-full rounded-lg border-gray-200 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $rail = 'min-w-0';
    $railTitle = 'text-sm font-semibold text-gray-900 dark:text-white';
    $ghost = 'inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/5';
    $primary = 'inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-500';
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

<div class="space-y-8" x-data="{ active: '', spy() { const hs = [...$el.querySelectorAll('.strategy-doc h2[id], .strategy-doc h3[id]')]; const top = window.scrollY + 120; let cur = ''; for (const h of hs) { if (h.offsetTop <= top) cur = h.id; } this.active = cur; } }" x-init="spy()" @scroll.window.passive="spy()">
    {{-- Üst şerit: nerede olduğun, başlık, durum ve ana karar düğmesi --}}
    <section class="space-y-5" data-testid="strategy-header">
        <p class="text-xs text-gray-500"><a href="{{ route('operator.strategies') }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">Stratejiler</a>@if ($strategy->brand) <span class="mx-1">›</span><a href="{{ route('operator.strategies', ['marka' => $strategy->brand_id]) }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">{{ $strategy->brand->name }}</a>@endif</p>
        <div class="flex flex-wrap items-start gap-4">
            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    @include('livewire.operator.strategy.partials.status', ['status' => $strategy->status, 'label' => $strategy->statusLabel()])
                    <span class="text-xs text-gray-500">{{ $statusHint }}</span>
                </div>
                <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $strategy->title }}</h1>
                @if ($strategy->summary)<p class="max-w-3xl text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $strategy->summary }}</p>@endif
            </div>
            <div class="flex flex-wrap items-center gap-2" data-testid="strategy-status-actions">
                @if ($strategy->status === 'draft')
                    <button type="button" wire:click="setStatus('approved')" class="{{ $primary }}">Onayla</button>
                @elseif ($strategy->status === 'approved')
                    <button type="button" wire:click="setStatus('active')" class="{{ $primary }}">Uygulamaya başladım</button>
                    <button type="button" wire:click="setStatus('draft')" class="{{ $ghost }}">Taslağa al</button>
                @elseif ($strategy->status === 'archived')
                    <button type="button" wire:click="setStatus('draft')" class="{{ $ghost }}">Arşivden çıkar</button>
                @else
                    <button type="button" wire:click="setStatus('approved')" class="{{ $ghost }}">Uygulama durdu</button>
                @endif
                @if ($strategy->status !== 'archived')
                    <button type="button" wire:click="setStatus('archived')" class="{{ $ghost }}">Arşivle</button>
                @endif
            </div>
        </div>

        {{-- Kapsam şeridi: tek bakışta bütün çerçeve --}}
        <dl class="grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-3 lg:grid-cols-6" data-testid="strategy-facts">
            @foreach ($facts as [$label, $value])
                <div class="min-w-0"><dt class="text-xs text-gray-500">{{ $label }}</dt><dd class="mt-0.5 truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $value }}">{{ $value }}</dd></div>
            @endforeach
        </dl>
        @if ($strategy->markets)
            <p class="text-sm text-gray-700 dark:text-gray-300"><span class="mr-2 text-xs text-gray-500">Hedef pazarlar</span>{{ implode(', ', $strategy->markets) }}</p>
        @endif
    </section>

    @if ($flash !== '')
        <p class="tone-info rounded-lg px-3 py-2 text-sm" data-testid="strategy-flash">{{ $flash }}</p>
    @endif

    @if ($editing)
        <form wire:submit="saveScope" class="{{ $card }} space-y-3" data-testid="strategy-scope-form">
            <h2 class="section-title">Kapsamı düzenle</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-xs text-gray-500 md:col-span-2">Başlık<input type="text" wire:model="scope.title" class="{{ $select }}">@error('scope.title')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                <label class="text-xs text-gray-500">Marka<select wire:model="scope.brand_id" class="{{ $select }}"><option value="">Sistem geneli</option>@foreach ($allBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label class="text-xs text-gray-500">Kanal<select wire:model="scope.channel" class="{{ $select }}">@foreach ($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label class="text-xs text-gray-500">Hizmet<input type="text" wire:model="scope.service" class="{{ $select }}"></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-xs text-gray-500">Bütçe<input type="text" inputmode="decimal" wire:model="scope.budget_amount" class="{{ $select }}">@error('scope.budget_amount')<span class="text-error-700">{{ $message }}</span>@enderror</label>
                    <label class="w-16 text-xs text-gray-500">Para<input type="text" wire:model="scope.budget_currency" class="{{ $select }}"></label>
                    <label class="text-xs text-gray-500">Dönem<select wire:model="scope.budget_period" class="{{ $select }}"><option value="">—</option>@foreach ($periods as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                </div>
                <label class="text-xs text-gray-500 md:col-span-2">Hedef pazarlar (virgülle)<input type="text" wire:model="scope.markets" class="{{ $select }}"></label>
                <label class="text-xs text-gray-500">Diller (virgülle)<input type="text" wire:model="scope.languages" class="{{ $select }}"></label>
            </div>
            <label class="block text-xs text-gray-500">Özet<textarea wire:model="scope.summary" rows="3" class="{{ $select }}"></textarea></label>
            <div class="flex gap-2">
                <button type="submit" class="{{ $primary }}">Kaydet</button>
                <button type="button" wire:click="$set('editing', false)" class="{{ $ghost }}">Vazgeç</button>
            </div>
        </form>
    @endif

    @if ($revising)
        <form wire:submit="saveRevision" class="{{ $card }} space-y-3" data-testid="strategy-revise">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="section-title">Metni düzenle</h2>
                <span class="text-xs text-gray-500">Kaydedince v{{ $versions->max('version') + 1 }} olur; eski sürümler kalır.</span>
            </div>
            <textarea wire:model="draftBody" rows="28" class="{{ $select }} font-mono text-xs leading-6"></textarea>
            @error('draftBody')<span class="text-xs text-error-700">{{ $message }}</span>@enderror
            <label class="block text-xs text-gray-500">Ne değişti<input type="text" wire:model="changeNote" class="{{ $select }}" placeholder="Örn. Almanca kampanya bütçesi güncellendi"></label>
            <div class="flex gap-2">
                <button type="submit" class="{{ $primary }}">Yeni sürüm olarak kaydet</button>
                <button type="button" wire:click="$set('revising', false)" class="{{ $ghost }}">Vazgeç</button>
            </div>
        </form>
    @else
        @if ($isOld)
            <p class="tone-look rounded-lg px-3 py-2 text-sm">Eski sürüm görüntüleniyor: <strong class="font-medium">v{{ $shown->version }}</strong> ({{ $shown->created_at?->format('d.m.Y H:i') }}@if ($shown->change_note), {{ $shown->change_note }}@endif). <button type="button" wire:click="$set('version', null)" class="font-medium underline">Geçerli sürüme dön</button></p>
        @endif

        <div class="grid gap-10 lg:grid-cols-[14rem_minmax(0,1fr)] 2xl:grid-cols-[14rem_minmax(0,1fr)_18rem]">
            {{-- Sol: içindekiler (yapışkan) --}}
            <aside class="space-y-3 lg:sticky lg:top-24 lg:self-start 2xl:order-none">
                @if ($toc !== [])
                    <nav class="strategy-toc lg:max-h-[75vh] lg:overflow-y-auto" aria-label="İçindekiler" data-testid="strategy-toc">
                        <p class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">İçindekiler <span class="text-xs font-normal text-gray-500">{{ $readMinutes }} dk okuma</span></p>
                        <ul class="space-y-0.5 text-sm">
                            @foreach ($toc as $h)
                                <li><a href="#{{ $h['id'] }}" :class="active === '{{ $h['id'] }}' ? 'is-active' : ''" @class(['block rounded-md py-1 leading-5 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white', 'pl-2' => $h['level'] === 2, 'pl-5 text-xs text-gray-500' => $h['level'] === 3])>{{ $h['text'] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
            </aside>

            {{-- Orta: belge --}}
            <article class="min-w-0 lg:order-2 2xl:order-none" data-testid="strategy-body">
                <div class="mb-4 flex flex-wrap items-center gap-2 border-b border-gray-200 pb-4 text-xs text-gray-500 dark:border-gray-800">
                    <span>v{{ $shown?->version }}, {{ $shown?->author }}, {{ $shown?->created_at?->format('d.m.Y H:i') }}</span>
                    <span class="flex-1"></span>
                    @unless ($isOld)<button type="button" wire:click="startRevision" class="{{ $ghost }} !py-1.5">Metni düzenle</button>@endunless
                    <button type="button" wire:click="download" class="{{ $ghost }} !py-1.5">İndir (.md)</button>
                </div>
                <div class="strategy-doc">{!! $html !!}</div>
            </article>

            {{-- Sağ: dayanak, sürümler, kapsam ve markanın diğer stratejileri --}}
            <aside class="grid gap-8 lg:order-first lg:col-span-2 lg:grid-cols-3 2xl:order-none 2xl:col-span-1 2xl:grid-cols-1 2xl:self-start">
                <section class="{{ $rail }}" data-testid="strategy-evidence">
                    <h2 class="{{ $railTitle }}">Dayanak</h2>
                    @if (empty($shown?->evidence))
                        <p class="mt-2 text-sm text-gray-500">Bu sürüm için ayrı dayanak listesi yok; dayandığı veriler metnin içinde.</p>
                    @else
                        <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                            @foreach ($shown->evidence as $row)
                                <li class="py-2"><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['label'] ?? '' }}</p>@if (! empty($row['detail']))<p class="mt-0.5 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>@endif</li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($planCounts !== null)
                    <section class="{{ $rail }}" data-testid="strategy-ads-editor">
                        <h2 class="{{ $railTitle }}">Google Ads Editor</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $planCounts['campaigns'] }} kampanya, {{ $planCounts['ad_groups'] }} reklam grubu, {{ $planCounts['keywords'] }} anahtar kelime, {{ $planCounts['negatives'] }} negatif, {{ $planCounts['ads'] }} reklam. Kampanyalar duraklatılmış gelir; sen açarsın.</p>
                        <ul class="mt-2 space-y-1 text-xs text-gray-500">
                            @foreach ($strategy->plan['campaigns'] as $c)
                                <li class="flex items-center gap-2"><span class="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $c['name'] }}">{{ $c['name'] }}</span>@if (isset($c['daily_budget']))<span>{{ number_format((float) $c['daily_budget'], 0, ',', '.') }}/gün</span>@endif<span>{{ count($c['ad_groups'] ?? []) }} grup</span></li>
                            @endforeach
                        </ul>
                        @if ($strategy->isExportable())
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('operator.strategy.ads-editor', ['strategyId' => $strategy->id, 'format' => 'csv']) }}" class="{{ $ghost }} !py-1.5">Ads Editor dosyası (.csv)</a>
                                <a href="{{ route('operator.strategy.ads-editor', ['strategyId' => $strategy->id, 'format' => 'xlsx']) }}" class="{{ $ghost }} !py-1.5">Excel (.xlsx)</a>
                            </div>
                            <p class="mt-2 text-xs text-gray-500" title="Konum seçeneği, hariç konum, zamanlama ve yaş ayarları dosyaya girmez; Excel'in Notlar sayfasında ve yorum sütununda yazar.">Editor'da Hesap › İçe aktar › Dosyadan.</p>
                        @else
                            <p class="mt-3 text-xs text-gray-500">Stratejiyi onaylayınca Ads Editor dosyası burada indirilir.</p>
                        @endif
                    </section>
                @endif

                <section class="{{ $rail }}" data-testid="strategy-versions">
                    <h2 class="{{ $railTitle }}">Sürümler ({{ $versions->count() }})</h2>
                    <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @foreach ($versions as $v)
                            <li @class(['py-2', 'opacity-70' => $v->version !== $strategy->current_version && $v->version !== $shown?->version])>
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium text-gray-900 dark:text-white">v{{ $v->version }}</span>
                                    @if ($v->version === $strategy->current_version)<span class="text-xs text-gray-500">Geçerli</span>@endif
                                    <span class="flex-1"></span>
                                    @if ($v->version !== $shown?->version)
                                        <button type="button" wire:click="$set('version', {{ $v->version === $strategy->current_version ? 'null' : $v->version }})" class="link-neutral text-xs">Oku</button>
                                    @endif
                                    @if ($v->version !== $strategy->current_version)
                                        <button type="button" wire:click="restore({{ $v->version }})" wire:confirm="v{{ $v->version }} yeniden geçerli sürüm olsun mu? (Yeni bir sürüm olarak eklenir.)" class="link-neutral text-xs">Geri al</button>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $v->change_note ?? '—' }}, {{ $v->author }}, {{ $v->created_at?->format('d.m.Y H:i') }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="{{ $rail }}" data-testid="strategy-scope">
                    <h2 class="{{ $railTitle }}">Kapsam</h2>
                    <dl class="mt-2 space-y-2 text-sm">
                        @if ($strategy->request)<div><dt class="text-xs text-gray-500">İstek</dt><dd class="mt-0.5 line-clamp-6 whitespace-pre-line text-gray-700 dark:text-gray-300" title="{{ $strategy->request }}">{{ $strategy->request }}</dd></div>@endif
                        <div><dt class="text-xs text-gray-500">Durum</dt><dd class="text-gray-900 dark:text-white">{{ $strategy->statusLabel() }}, {{ $strategy->status_changed_at?->format('d.m.Y') }}</dd></div>
                        @if ($strategy->source_ref)<div><dt class="text-xs text-gray-500">Nerede hazırlandı</dt><dd class="mt-0.5 break-all text-gray-700 dark:text-gray-300">@if (str_starts_with($strategy->source_ref, 'https://'))<a href="{{ $strategy->source_ref }}" target="_blank" rel="noopener" class="link-neutral">Proje konuşması</a>@else{{ $strategy->source_ref }}@endif</dd></div>@endif
                    </dl>
                    @unless ($editing)<button type="button" wire:click="startEdit" class="mt-3 {{ $ghost }} !py-1.5">Kapsamı düzenle</button>@endunless
                </section>

                @if ($others->isNotEmpty())
                    <section class="{{ $rail }}">
                        <h2 class="{{ $railTitle }}">{{ $strategy->brand->name }}, diğer stratejiler</h2>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            @foreach ($others as $o)
                                <li class="flex items-center gap-2"><a href="{{ route('operator.strategy', ['strategyId' => $o->id]) }}" wire:navigate class="min-w-0 flex-1 truncate text-gray-700 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">{{ $o->title }}</a>@include('livewire.operator.strategy.partials.status', ['status' => $o->status, 'label' => $o->statusLabel()])</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </aside>
        </div>
    @endif
</div>
