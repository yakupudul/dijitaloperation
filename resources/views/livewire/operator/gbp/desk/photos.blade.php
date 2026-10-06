<div class="space-y-5 dark:text-gray-200" data-gbp-photos>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme profilleri</h1>
            <p class="mt-1 max-w-3xl text-xs text-gray-500">Gerçek ve güncel fotoğrafı olan profiller daha çok tıklanır. Her profile ayda en az bir yeni fotoğraf hedeflenir: önce sitenin kendi görselleri (hizmet sayfalarındakiler önce), yoksa şubenin kendi fotoğrafını buradan yüklersin. Aynı fotoğraf bir profile bir kez gider; başka şubede kullanılanlar işaretlidir. Gönderilen fotoğraf geri alınabilir.</p>
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-photos', 'brandFilter' => $brand])
    @include('livewire.operator.gbp.partials.desk-message')

    <section class="flex flex-wrap items-center gap-3 text-sm">
        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $staleCount > 0 ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' }}">{{ $staleCount > 0 ? $staleCount.' profilde 30 günden eski ya da hiç fotoğraf yok' : 'Tüm profillerde son 30 günde fotoğraf var' }}</span>
        <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="staleOnly" class="rounded border-gray-300 text-brand-500"> Yalnız fotoğrafı eski olanlar</label>
    </section>

    <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
        @forelse ($groups as $brandName => $locations)
            @php $groupStale = $locations->filter(fn ($l) => $status[$l->id]['stale'] ?? true)->count(); @endphp
            <div class="flex flex-wrap items-center gap-2 bg-gray-50 px-4 py-1.5 text-xs dark:bg-white/[0.03]">
                <span class="font-semibold uppercase tracking-wide text-gray-500">{{ $brandName }} · {{ $locations->count() }}</span>
                @if ($canWrite && $groupStale > 0 && $locations->first()?->brand_id)
                    <button type="button" wire:click="sendMonthly({{ $locations->first()->brand_id }})" wire:confirm="Fotoğrafı 30 günden eski olan {{ $groupStale }} profile siteden birer kullanılmamış fotoğraf gönderilsin mi?" class="ml-auto font-medium text-brand-600 hover:underline">Eski olanlara birer fotoğraf gönder ({{ $groupStale }})</button>
                @endif
            </div>
            @foreach ($locations as $location)
                @php
                    $s = $status[$location->id] ?? null;
                    $isOpen = $open === (int) $location->id;
                @endphp
                <div wire:key="ph-{{ $location->id }}" class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                        @if ($s === null)
                            <span class="text-xs text-gray-400">Profil bağlantısı yok</span>
                        @else
                            <span class="text-xs tabular-nums text-gray-500">{{ $s['photos'] }} fotoğraf</span>
                            <span class="text-xs text-gray-500">{{ $s['days'] !== null ? 'son: '.$s['days'].' gün önce' : 'tarih yok' }}</span>
                            @unless ($s['logo'])<span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">Logo yok</span>@endunless
                            @unless ($s['cover'])<span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">Kapak yok</span>@endunless
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $s['stale'] ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' }}">{{ $s['stale'] ? 'Yeni fotoğraf gerekli' : 'Güncel' }}</span>
                        @endif
                        <button type="button" wire:click="toggle({{ $location->id }})" class="text-xs font-medium text-brand-600 hover:underline">{{ $isOpen ? 'Kapat' : 'Fotoğraf seç' }}</button>
                    </div>

                    @if ($isOpen)
                        <div class="mt-3 space-y-4">
                            <div>
                                <div class="mb-2 flex flex-wrap items-center gap-3">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sitedeki fotoğraflar</h3>
                                    <span class="text-xs text-gray-500">JPG / PNG, kenarı en az {{ \App\Services\Gbp\Desk\PhotoPlan::MIN_SIDE }} px; bu profile daha önce gönderilmemiş olanlar</span>
                                    @if ($canWrite && $pickedCount > 0)
                                        <button type="button" wire:click="send" wire:confirm="Seçilen {{ $pickedCount }} fotoğraf bu profile eklensin mi? (Geri alınabilir.)" class="ml-auto rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Seçilenleri gönder ({{ $pickedCount }})</button>
                                    @endif
                                </div>
                                @if ($candidates === [])
                                    <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 dark:bg-white/[0.03]">Sitede bu profile gönderilebilecek fotoğraf bulunamadı (site bağlı değil, görseller küçük ya da hepsi gönderilmiş). Şubenin kendi fotoğrafını aşağıdan yükleyin.</p>
                                @else
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                        @foreach ($candidates as $index => $c)
                                            <div wire:key="cand-{{ $location->id }}-{{ md5($c['url']) }}" @class(['overflow-hidden rounded-lg ring-1 ring-inset', 'ring-brand-400 ring-2' => $picked[(string) $index] ?? false, 'ring-gray-200 dark:ring-gray-700' => ! ($picked[(string) $index] ?? false)])>
                                                <label class="block cursor-pointer">
                                                    <img src="{{ $c['url'] }}" alt="{{ $c['title'] }}" loading="lazy" class="aspect-square w-full bg-gray-100 object-cover dark:bg-gray-900">
                                                    <span class="flex items-start gap-1.5 p-2 text-[11px] text-gray-600 dark:text-gray-300">
                                                        @if ($canWrite)<input type="checkbox" wire:model.live="picked.{{ $index }}" class="mt-0.5 rounded border-gray-300 text-brand-500">@endif
                                                        <span class="min-w-0">
                                                            <span class="block truncate" title="{{ $c['title'] }}">{{ $c['title'] }}</span>
                                                            @if ($c['width'])<span class="block text-gray-400">{{ $c['width'] }} × {{ $c['height'] }}</span>@endif
                                                            @if ($c['page'])<span class="block truncate text-gray-500" title="{{ $c['page'] }}">Hizmet: {{ $c['page'] }}</span>@endif
                                                            @if ($c['used_by'] > 0)<span class="block text-amber-600">{{ $c['used_by'] }} şubede kullanıldı</span>@endif
                                                        </span>
                                                    </span>
                                                </label>
                                                @if ($canWrite && ($picked[(string) $index] ?? false))
                                                    <select wire:model="categories.{{ $index }}" class="w-full border-0 border-t border-gray-200 py-1 text-[11px] dark:border-gray-700 dark:bg-gray-900">
                                                        @foreach ($labels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                                    </select>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($canWrite)
                                <form wire:submit="uploadPhoto" class="flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <label class="block text-xs"><span class="text-gray-500">Şube fotoğrafı yükle (JPG / PNG, en çok 5 MB, önerilen 720 × 720 ve üstü)</span>
                                        <input type="file" wire:model="photo" accept="image/jpeg,image/png" class="mt-1 block text-sm">
                                    </label>
                                    <label class="block text-xs"><span class="text-gray-500">Tür</span>
                                        <select wire:model="photoCategory" class="mt-1 block rounded-lg border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900">
                                            @foreach ($labels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                        </select>
                                    </label>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="photo,uploadPhoto" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Yükle ve gönder</button>
                                    <span wire:loading wire:target="photo" class="text-xs text-gray-500">Yükleniyor…</span>
                                    @error('photo')<p class="w-full text-xs text-rose-600">{{ $message }}</p>@enderror
                                </form>
                            @endif

                            @if ($history->isNotEmpty())
                                <div>
                                    <h3 class="mb-1 text-sm font-semibold text-gray-900 dark:text-white">MoxDOP’un bu profile gönderdikleri</h3>
                                    <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-700">
                                        @foreach ($history as $item)
                                            @php $write = $item->writeAction; @endphp
                                            <li wire:key="hist-{{ $item->id }}" class="flex flex-wrap items-center gap-3 py-1.5" @if (in_array($write?->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                                <img src="{{ $item->source_url }}" alt="" loading="lazy" class="h-10 w-10 rounded object-cover">
                                                <span class="min-w-0 flex-1 truncate">{{ $item->title ?: basename((string) $item->source_url) }} · {{ $labels[$item->category] ?? $item->category }} · {{ $item->source === 'upload' ? 'yüklendi' : 'siteden' }}</span>
                                                <span class="text-gray-500">{{ $item->created_at?->locale('tr')->translatedFormat('d M Y') }}</span>
                                                <span @class(['font-medium', 'text-emerald-600' => $item->status === 'uploaded', 'text-rose-600' => $item->status === 'failed', 'text-gray-500' => ! in_array($item->status, ['uploaded', 'failed'], true)])>{{ ['sending' => 'Gönderiliyor', 'uploaded' => 'Profilde', 'failed' => 'Gönderilemedi', 'removed' => 'Kaldırıldı'][$item->status] ?? $item->status }}</span>
                                                @if ($item->status === 'failed' && $write?->error)<span class="w-full text-rose-600">{{ \Illuminate\Support\Str::limit($write->error, 200) }}</span>@endif
                                                @if ($canWrite && $write?->isUndoable())<button type="button" wire:click="undo({{ $write->id }})" wire:confirm="Fotoğraf profilden kaldırılsın mı?" class="font-medium text-gray-600 hover:underline dark:text-gray-300">Kaldır</button>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        @empty
            <p class="px-4 py-5 text-sm text-gray-500">{{ $staleOnly ? 'Fotoğrafı eski profil yok.' : 'Operasyonel markaya bağlı İşletme Profili yok.' }}</p>
        @endforelse
    </section>
</div>
