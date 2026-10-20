@php
    // İşletme profilleri › Fotoğraflar (panel concept 2026-10-20). The question: "Which profiles need a new photo this
    // month?" One card per brand (with its bulk button), one row per profile; a row opens to pick site photos or upload one.
    $input = 'rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 focus:border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:focus:ring-white/10';
    $check = 'h-4 w-4 shrink-0 rounded border-gray-300 accent-gray-900 dark:accent-white';
@endphp
<div class="dark:text-gray-200" data-gbp-photos>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-photos', 'brandFilter' => $brand])

    <div class="space-y-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="page-title" title="Gerçek ve güncel fotoğrafı olan profiller daha çok tıklanır. Her profile ayda en az bir yeni fotoğraf hedeflenir: önce sitenin kendi görselleri (hizmet sayfalarındakiler önce), yoksa şubenin kendi fotoğrafını buradan yüklersin. Aynı fotoğraf bir profile bir kez gider; başka şubede kullanılanlar işaretlidir. Gönderilen fotoğraf geri alınabilir.">İşletme profilleri</h1>
                <p class="page-sub">{{ $staleCount > 0 ? $staleCount.' profilde 30 günden eski ya da hiç fotoğraf yok.' : 'Tüm profillerde son 30 günde fotoğraf var.' }}</p>
            </div>
            @include('livewire.operator.gbp.partials.brand-filter')
        </header>
        @include('livewire.operator.gbp.partials.desk-message')

        <label @class(['seg relative cursor-pointer has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-gray-400', 'seg-active' => $staleOnly])><input type="checkbox" wire:model.live="staleOnly" class="sr-only"> Yalnız fotoğrafı eski olanlar @if ($staleCount > 0)<span @class(['tabular-nums', 'text-gray-500' => ! $staleOnly, 'text-white/70 dark:text-gray-500' => $staleOnly])>{{ $staleCount }}</span>@endif</label>

        @forelse ($groups as $brandName => $locations)
            @php $groupStale = $locations->filter(fn ($l) => $status[$l->id]['stale'] ?? true)->count(); @endphp
            <section wire:key="ph-brand-{{ md5((string) $brandName) }}">
                <div class="section-head">
                    <h2 class="section-title flex min-w-0 flex-1 items-center gap-2"><x-ta.brand-avatar :name="(string) $brandName" :logo="$locations->first()?->brand?->logo_url" size="sm" /> <span class="min-w-0 break-words">{{ $brandName }} <span class="section-count">{{ $locations->count() }}</span></span></h2>
                    @if ($canWrite && $groupStale > 0 && $locations->first()?->brand_id)
                        <button type="button" wire:click="sendMonthly({{ $locations->first()->brand_id }})" wire:confirm="Fotoğrafı 30 günden eski olan {{ $groupStale }} profile siteden birer kullanılmamış fotoğraf gönderilsin mi?" class="btn w-full sm:w-auto">Eski olanlara birer fotoğraf gönder ({{ $groupStale }})</button>
                    @endif
                </div>
                <div class="card">
                    @foreach ($locations as $location)
                        @php
                            $s = $status[$location->id] ?? null;
                            $isOpen = $open === (int) $location->id;
                            $meta = $s === null ? ['Profil bağlantısı yok'] : array_values(array_filter([
                                $s['photos'].' fotoğraf',
                                $s['days'] !== null ? 'son: '.$s['days'].' gün önce' : 'tarih yok',
                                $s['logo'] ? null : 'Logo yok',
                                $s['cover'] ? null : 'Kapak yok',
                            ]));
                        @endphp
                        <div wire:key="ph-{{ $location->id }}" class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-3.5">
                                <div class="min-w-0 basis-full sm:flex-1 sm:basis-0">
                                    <p class="truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</p>
                                    <p class="mt-0.5 text-[13px] tabular-nums text-gray-500">{{ implode(' · ', $meta) }}</p>
                                </div>
                                @if ($s !== null)
                                    <x-ta.pill :tone="$s['stale'] ? 'look' : 'ok'">{{ $s['stale'] ? 'Yeni fotoğraf gerekli' : 'Güncel' }}</x-ta.pill>
                                @endif
                                <button type="button" wire:click="toggle({{ $location->id }})" @class(['ml-auto', 'btn' => ! $isOpen, 'btn-quiet' => $isOpen])>{{ $isOpen ? 'Kapat' : 'Fotoğraf seç' }}</button>
                            </div>

                            @if ($isOpen)
                                <div class="space-y-5 border-t border-gray-200 bg-gray-50/60 px-4 py-4 dark:border-gray-800 dark:bg-white/[0.02]">
                                    <div>
                                        <div class="mb-2.5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sitedeki fotoğraflar</h3>
                                            <span class="min-w-0 flex-1 text-[13px] text-gray-500">JPG / PNG, kenarı en az {{ \App\Services\Gbp\Desk\PhotoPlan::MIN_SIDE }} px; bu profile daha önce gönderilmemiş olanlar</span>
                                            @if ($canWrite && $pickedCount > 0)
                                                <button type="button" wire:click="send" wire:confirm="Seçilen {{ $pickedCount }} fotoğraf bu profile eklensin mi? (Geri alınabilir.)" class="btn-primary w-full sm:w-auto">Seçilenleri gönder ({{ $pickedCount }})</button>
                                            @endif
                                        </div>
                                        @if ($candidates === [])
                                            <p class="text-[13px] text-gray-500">Sitede bu profile gönderilebilecek fotoğraf bulunamadı (site bağlı değil, görseller küçük ya da hepsi gönderilmiş). Şubenin kendi fotoğrafını aşağıdan yükleyin.</p>
                                        @else
                                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                                @foreach ($candidates as $index => $c)
                                                    @php $isPicked = $picked[(string) $index] ?? false; @endphp
                                                    <div wire:key="cand-{{ $location->id }}-{{ md5($c['url']) }}" @class(['overflow-hidden rounded-lg border bg-white shadow-card dark:bg-gray-900', 'border-gray-900 ring-1 ring-gray-900 dark:border-white dark:ring-white' => $isPicked, 'border-gray-200 dark:border-gray-800' => ! $isPicked])>
                                                        <label class="block cursor-pointer">
                                                            <img src="{{ $c['url'] }}" alt="{{ $c['title'] }}" loading="lazy" class="aspect-square w-full bg-gray-100 object-cover dark:bg-gray-800">
                                                            <span class="flex items-start gap-2 p-2 text-[13px] text-gray-700 dark:text-gray-300">
                                                                @if ($canWrite)<input type="checkbox" wire:model.live="picked.{{ $index }}" class="mt-0.5 {{ $check }}">@endif
                                                                <span class="min-w-0">
                                                                    <span class="block truncate" title="{{ $c['title'] }}">{{ $c['title'] }}</span>
                                                                    @if ($c['width'])<span class="block text-xs tabular-nums text-gray-500">{{ $c['width'] }} × {{ $c['height'] }}</span>@endif
                                                                    @if ($c['page'])<span class="block truncate text-xs text-gray-500" title="{{ $c['page'] }}">Hizmet: {{ $c['page'] }}</span>@endif
                                                                    @if ($c['used_by'] > 0)<span class="block text-xs text-warning-700 dark:text-warning-400">{{ $c['used_by'] }} şubede kullanıldı</span>@endif
                                                                </span>
                                                            </span>
                                                        </label>
                                                        @if ($canWrite && $isPicked)
                                                            <select wire:model="categories.{{ $index }}" class="block w-full border-0 border-t border-gray-200 bg-white px-2 py-1.5 text-[13px] dark:border-gray-800 dark:bg-gray-900">
                                                                @foreach ($labels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                                            </select>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    @if ($canWrite)
                                        <form wire:submit="uploadPhoto" class="card flex flex-wrap items-end gap-3 p-4">
                                            <label class="block min-w-0 basis-full text-[13px] sm:flex-1 sm:basis-0"><span class="font-medium text-gray-900 dark:text-white">Şube fotoğrafı yükle</span> <span class="text-gray-500">(JPG / PNG, en çok 5 MB, önerilen 720 × 720 ve üstü)</span>
                                                <input type="file" wire:model="photo" accept="image/jpeg,image/png" class="mt-2 block w-full max-w-full text-[13px] text-gray-600 file:mr-3 file:h-8 file:cursor-pointer file:rounded-lg file:border file:border-gray-300/80 file:bg-white file:px-3 file:text-[13px] file:font-medium file:text-gray-900 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-white/[0.04] dark:file:text-gray-200">
                                            </label>
                                            <label class="block text-[13px]"><span class="text-gray-500">Tür</span>
                                                <select wire:model="photoCategory" class="mt-1 block h-8 {{ $input }} py-0 text-[13px]">
                                                    @foreach ($labels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                                </select>
                                            </label>
                                            <button type="submit" wire:loading.attr="disabled" wire:target="photo,uploadPhoto" class="btn">Yükle ve gönder</button>
                                            <span wire:loading wire:target="photo" class="text-[13px] text-gray-500">Yükleniyor…</span>
                                            @error('photo')<p class="w-full text-[13px] text-error-600 dark:text-error-400">{{ $message }}</p>@enderror
                                        </form>
                                    @endif

                                    @if ($history->isNotEmpty())
                                        <div>
                                            <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">MoxDOP’un bu profile gönderdikleri</h3>
                                            <ul class="card">
                                                @foreach ($history as $item)
                                                    @php $write = $item->writeAction; @endphp
                                                    <li wire:key="hist-{{ $item->id }}" class="card-row flex-wrap gap-3 py-2.5 text-[13px]" @if (in_array($write?->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>
                                                        <img src="{{ $item->source_url }}" alt="" loading="lazy" class="h-10 w-10 shrink-0 rounded-md bg-gray-100 object-cover dark:bg-gray-800">
                                                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-white">{{ $item->title ?: basename((string) $item->source_url) }}<span class="text-gray-500">, {{ $labels[$item->category] ?? $item->category }}, {{ $item->source === 'upload' ? 'yüklendi' : 'siteden' }}</span></span>
                                                        <span class="tabular-nums text-gray-500">{{ $item->created_at?->locale('tr')->translatedFormat('d M Y') }}</span>
                                                        @php $tone = ['uploaded' => 'ok', 'failed' => 'look'][$item->status] ?? 'info'; @endphp
                                                        <x-ta.pill :tone="$tone">{{ ['sending' => 'Gönderiliyor', 'uploaded' => 'Profilde', 'failed' => 'Gönderilemedi', 'removed' => 'Kaldırıldı'][$item->status] ?? $item->status }}</x-ta.pill>
                                                        @if ($canWrite && $write?->isUndoable())<button type="button" wire:click="undo({{ $write->id }})" wire:confirm="Fotoğraf profilden kaldırılsın mı?" class="btn-quiet h-7! px-2!">Kaldır</button>@endif
                                                        @if ($item->status === 'failed' && $write?->error)<span class="w-full text-error-600 dark:text-error-400">{{ \Illuminate\Support\Str::limit($write->error, 200) }}</span>@endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="card"><x-ta.empty-state size="sm" :icon="$staleOnly ? 'check' : 'none'" :title="$staleOnly ? 'Fotoğrafı eski profil yok.' : 'Operasyonel markaya bağlı İşletme Profili yok.'" /></div>
        @endforelse
    </div>
</div>
