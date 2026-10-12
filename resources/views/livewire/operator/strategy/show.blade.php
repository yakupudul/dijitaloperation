@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $select = 'mt-1 block w-full rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $chip = 'rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-white/[0.06] dark:text-gray-300';
    $tabs = ['metin' => 'Strateji', 'kapsam' => 'Kapsam', 'dayanak' => 'Dayanak', 'surumler' => 'Sürümler ('.$versions->count().')'];
    $isOld = $shown !== null && $shown->version !== $strategy->current_version;
    $ghost = 'rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/5';
@endphp

<div class="space-y-5">
    <div class="space-y-2">
        <p class="text-xs font-medium text-gray-500"><a href="{{ route('operator.strategies') }}" wire:navigate class="hover:text-brand-600">Stratejiler</a>@if ($strategy->brand) › <a href="{{ route('operator.strategies', ['marka' => $strategy->brand_id]) }}" wire:navigate class="hover:text-brand-600">{{ $strategy->brand->name }}</a>@endif</p>
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1 space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $strategy->title }}</h1>
                <div class="flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                    @include('livewire.operator.strategy.partials.status', ['status' => $strategy->status, 'label' => $strategy->statusLabel()])
                    <span class="{{ $chip }}">{{ $strategy->channelLabel() }}</span>
                    @if ($strategy->service)<span class="{{ $chip }}">{{ $strategy->service }}</span>@endif
                    @if ($strategy->budgetLabel())<span class="{{ $chip }} font-medium">{{ $strategy->budgetLabel() }}</span>@endif
                    @foreach ($strategy->languages ?? [] as $l)<span class="{{ $chip }}">{{ $l }}</span>@endforeach
                    <span>v{{ $strategy->current_version }} · {{ $strategy->updated_at?->format('d.m.Y H:i') }}</span>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2" data-testid="strategy-status-actions">
                @if ($strategy->status === 'draft')
                    <button type="button" wire:click="setStatus('approved')" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Onayla</button>
                @elseif ($strategy->status === 'approved')
                    <button type="button" wire:click="setStatus('active')" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Uygulamaya başladım</button>
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
        @if ($strategy->summary)<p class="max-w-3xl text-sm text-gray-600 dark:text-gray-300">{{ $strategy->summary }}</p>@endif
    </div>

    @if ($flash !== '')
        <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200" data-testid="strategy-flash">{{ $flash }}</p>
    @endif

    <nav class="flex flex-wrap gap-1 border-b border-gray-200 text-sm dark:border-gray-700" aria-label="Strateji">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['-mb-px border-b-2 px-3 py-2 font-medium',
                'border-brand-500 text-gray-900 dark:text-white' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:hover:text-gray-200' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'kapsam')
        <section class="{{ $card }}" data-testid="strategy-scope">
            @if ($editing)
                <form wire:submit="saveScope" class="space-y-3">
                    <div class="grid gap-3 md:grid-cols-3">
                        <label class="text-xs text-gray-500 md:col-span-2">Başlık<input type="text" wire:model="scope.title" class="{{ $select }}">@error('scope.title')<span class="text-rose-600">{{ $message }}</span>@enderror</label>
                        <label class="text-xs text-gray-500">Marka<select wire:model="scope.brand_id" class="{{ $select }}"><option value="">Sistem geneli</option>@foreach ($allBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                        <label class="text-xs text-gray-500">Kanal<select wire:model="scope.channel" class="{{ $select }}">@foreach ($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="text-xs text-gray-500">Hizmet<input type="text" wire:model="scope.service" class="{{ $select }}"></label>
                        <div class="flex gap-2">
                            <label class="flex-1 text-xs text-gray-500">Bütçe<input type="text" inputmode="decimal" wire:model="scope.budget_amount" class="{{ $select }}">@error('scope.budget_amount')<span class="text-rose-600">{{ $message }}</span>@enderror</label>
                            <label class="w-16 text-xs text-gray-500">Para<input type="text" wire:model="scope.budget_currency" class="{{ $select }}"></label>
                            <label class="text-xs text-gray-500">Dönem<select wire:model="scope.budget_period" class="{{ $select }}"><option value="">—</option>@foreach ($periods as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        </div>
                        <label class="text-xs text-gray-500 md:col-span-2">Hedef pazarlar (virgülle)<input type="text" wire:model="scope.markets" class="{{ $select }}"></label>
                        <label class="text-xs text-gray-500">Diller (virgülle)<input type="text" wire:model="scope.languages" class="{{ $select }}"></label>
                    </div>
                    <label class="block text-xs text-gray-500">Özet<textarea wire:model="scope.summary" rows="3" class="{{ $select }}"></textarea></label>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Kaydet</button>
                        <button type="button" wire:click="$set('editing', false)" class="{{ $ghost }}">Vazgeç</button>
                    </div>
                </form>
            @else
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs text-gray-500">Marka</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $strategy->brand?->name ?? 'Sistem geneli' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Kanal</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $strategy->channelLabel() }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Hizmet</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $strategy->service ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Bütçe</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $strategy->budgetLabel() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Diller</dt><dd class="font-medium text-gray-900 dark:text-white">{{ implode(', ', $strategy->languages ?? []) ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Durum</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $strategy->statusLabel() }} · {{ $strategy->status_changed_at?->format('d.m.Y') }}</dd></div>
                    <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-gray-500">Hedef pazarlar</dt><dd class="mt-1 flex flex-wrap gap-1.5">@forelse ($strategy->markets ?? [] as $m)<span class="{{ $chip }}">{{ $m }}</span>@empty<span class="text-gray-500">—</span>@endforelse</dd></div>
                    @if ($strategy->request)<div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-gray-500">İstek</dt><dd class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $strategy->request }}</dd></div>@endif
                    @if ($strategy->source_ref)<div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-gray-500">Nerede hazırlandı</dt><dd class="mt-1 break-all text-gray-700 dark:text-gray-300">@if (str_starts_with($strategy->source_ref, 'https://'))<a href="{{ $strategy->source_ref }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ $strategy->source_ref }}</a>@else{{ $strategy->source_ref }}@endif</dd></div>@endif
                </dl>
                <button type="button" wire:click="startEdit" class="mt-4 {{ $ghost }}">Kapsamı düzenle</button>
            @endif
        </section>
        @if ($others->isNotEmpty())
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $strategy->brand->name }} için diğer stratejiler</h2>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($others as $o)
                        <li class="flex items-center gap-2"><a href="{{ route('operator.strategy', ['strategyId' => $o->id]) }}" wire:navigate class="text-brand-600 hover:underline">{{ $o->title }}</a>@include('livewire.operator.strategy.partials.status', ['status' => $o->status, 'label' => $o->statusLabel()])<span class="text-xs text-gray-400">{{ $o->updated_at?->format('d.m.Y') }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif
    @elseif ($tab === 'dayanak')
        <section class="{{ $card }}" data-testid="strategy-evidence">
            <p class="text-xs text-gray-500">v{{ $shown?->version }} hangi veriye dayanıyor</p>
            @if (empty($shown?->evidence))
                <p class="mt-2 text-sm text-gray-500">Bu sürüm için ayrı dayanak listesi yok; dayandığı veriler metnin içinde.</p>
            @else
                <dl class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($shown->evidence as $row)
                        <div class="grid gap-1 py-2 sm:grid-cols-3"><dt class="font-medium text-gray-900 dark:text-white">{{ $row['label'] ?? '' }}</dt><dd class="text-gray-600 sm:col-span-2 dark:text-gray-300">{{ $row['detail'] ?? '' }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </section>
    @elseif ($tab === 'surumler')
        <section class="{{ $panel }}" data-testid="strategy-versions">
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($versions as $v)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <span class="font-semibold text-gray-900 dark:text-white">v{{ $v->version }}</span>
                        @if ($v->version === $strategy->current_version)<span class="{{ $chip }}">Geçerli</span>@endif
                        <span class="min-w-0 flex-1 text-gray-600 dark:text-gray-300">{{ $v->change_note ?? '—' }}</span>
                        <span class="text-xs text-gray-400">{{ $v->author }} · {{ $v->created_at?->format('d.m.Y H:i') }}</span>
                        <button type="button" wire:click="$set('version', {{ $v->version }}); $set('tab', 'metin')" class="text-sm font-medium text-brand-600 hover:underline">Oku</button>
                        @if ($v->version !== $strategy->current_version)
                            <button type="button" wire:click="restore({{ $v->version }})" wire:confirm="v{{ $v->version }} yeniden geçerli sürüm olsun mu? (Yeni bir sürüm olarak eklenir.)" class="text-sm font-medium text-gray-600 hover:underline dark:text-gray-300">Geri al</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @else
        @if ($revising)
            <form wire:submit="saveRevision" class="{{ $card }} space-y-3" data-testid="strategy-revise">
                <p class="text-sm text-gray-500">Metni değiştir; kaydedince v{{ $versions->max('version') + 1 }} olur, eski sürümler kalır.</p>
                <textarea wire:model="draftBody" rows="24" class="{{ $select }} font-mono"></textarea>
                @error('draftBody')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                <label class="block text-xs text-gray-500">Ne değişti<input type="text" wire:model="changeNote" class="{{ $select }}"></label>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Yeni sürüm olarak kaydet</button>
                    <button type="button" wire:click="$set('revising', false)" class="{{ $ghost }}">Vazgeç</button>
                </div>
            </form>
        @else
            @if ($isOld)
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">Eski sürüm görüntüleniyor: v{{ $shown->version }} ({{ $shown->created_at?->format('d.m.Y') }}). <button type="button" wire:click="$set('version', null)" class="font-semibold underline">Geçerli sürüme dön</button></p>
            @endif
            <div class="grid gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
                <aside class="space-y-3 lg:sticky lg:top-20 lg:self-start">
                    @if ($toc !== [])
                        <nav class="{{ $card }} max-h-[70vh] overflow-y-auto text-sm" aria-label="İçindekiler" data-testid="strategy-toc">
                            <p class="mb-2 text-xs font-medium text-gray-500">İçindekiler</p>
                            <ul class="space-y-1">
                                @foreach ($toc as $h)
                                    <li class="{{ $h['level'] === 3 ? 'pl-3 text-xs' : '' }}"><a href="#{{ $h['id'] }}" class="text-gray-700 hover:text-brand-600 dark:text-gray-300">{{ $h['text'] }}</a></li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @unless ($isOld)<button type="button" wire:click="startRevision" class="{{ $ghost }}">Metni düzenle</button>@endunless
                        <button type="button" wire:click="download" class="{{ $ghost }}">İndir (.md)</button>
                    </div>
                </aside>
                <article class="{{ $card }} prose prose-sm max-w-none overflow-x-auto text-gray-800 dark:prose-invert dark:text-gray-200 md:prose-base prose-table:text-sm prose-th:text-left" data-testid="strategy-body">
                    {!! $html !!}
                </article>
            </div>
        @endif
    @endif
</div>
