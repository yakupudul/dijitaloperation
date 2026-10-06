@php
    $tone = fn (string $state): string => match ($state) {
        'linked', 'single' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'unlinked', 'ready' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'sent' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'failed', 'missing' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    };
    $shortUrl = fn (string $url): string => \Illuminate\Support\Str::limit((string) preg_replace('~^https?://(www\.)?~', '', rtrim(strtok($url, '?') ?: $url, '/')) ?: '/', 60);
@endphp
<div class="space-y-5 dark:text-gray-200" data-gbp-branch-pages>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme profilleri</h1>
            <p class="mt-1 max-w-3xl text-xs text-gray-500">Birden çok şubesi olan markada her Google profili, sitede o şubenin kendi sayfasına bağlanmalı (adres, saatler, o şubenin hizmetleri, yerel işletme işaretlemesi); ana sayfaya bağlı şubeler birbirinin yerine sıralanır. Tek işletmeli markada ana sayfa yeterlidir. Her satır sıradaki adımı gösterir.</p>
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-branch-pages', 'brandFilter' => $brand])
    @include('livewire.operator.gbp.partials.desk-message')

    <section class="flex flex-wrap gap-2" aria-label="Durum süzgeci">
        @foreach ($filters as $key => $f)
            @continue(($counts[$key] ?? 0) === 0 && $filter !== $key)
            <button type="button" wire:click="setFilter('{{ $key }}')" @class(['rounded-lg px-3 py-2 text-left ring-1 ring-inset', 'bg-brand-50 ring-brand-300 dark:bg-brand-500/10' => $filter === $key, 'bg-white ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700' => $filter !== $key])>
                <span class="block text-lg font-semibold tabular-nums {{ $key === 'tamam' ? 'text-emerald-600' : 'text-gray-900 dark:text-white' }}">{{ $counts[$key] }}</span>
                <span class="block text-xs text-gray-500">{{ $f['label'] }}</span>
            </button>
        @endforeach
        @if ($filter !== '')<button type="button" wire:click="setFilter('{{ $filter }}')" class="self-center text-xs text-brand-600 hover:underline">Süzmeyi kaldır</button>@endif
    </section>
    @if (($counts['veri'] ?? 0) > 0 && $filter === '')
        <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ $counts['veri'] }} profilin adres, saat ve bağlantı bilgisi henüz Google’dan çekilmedi; bunlar için sayfa yazılamaz. Profil verisi toplandıkça satırlar kendiliğinden açılır (İşletme Profili bağlantısının Google hesabında yetkili olduğundan emin olun).</p>
    @endif

    <section class="space-y-4">
        @forelse ($groups as $brandName => $locations)
            @php
                $brandId = (int) $locations->first()->brand_id;
                $b = $brands[$brandId] ?? null;
                $hub = $b['hub'] ?? null;
            @endphp
            <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" wire:key="brand-{{ $brandId }}">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-100 bg-gray-50 px-4 py-2.5 dark:border-gray-700 dark:bg-white/[0.03]">
                    <div class="min-w-0 flex-1">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $brandName }}</span>
                        @if ($b)
                            <span class="ml-2 text-xs text-gray-500">{{ $b['total'] }} {{ $b['total'] === 1 ? 'işletme' : 'şube' }} · <span class="{{ $b['done'] === $b['total'] ? 'text-emerald-600' : '' }}">{{ $b['done'] }} tamam</span>@if ($b['no_data'] > 0) · {{ $b['no_data'] }} profil verisi bekliyor @endif</span>
                            <span class="mt-1 block h-1.5 w-40 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"><span class="block h-full bg-emerald-500" style="width: {{ $b['total'] > 0 ? round($b['done'] / $b['total'] * 100) : 0 }}%"></span></span>
                        @endif
                    </div>
                    @if ($hub)
                        <span class="text-xs">
                            @if ($hub['page'])
                                <a href="{{ $hub['page']->url }}" target="_blank" rel="noopener" class="text-emerald-700 hover:underline dark:text-emerald-300">✓ Şubelerimiz sayfası var ↗</a>
                            @elseif ($hub['action'] && in_array($hub['action']->status, ['queued', 'running', 'succeeded', 'partial'], true))
                                <span class="text-amber-700 dark:text-amber-300">Şubelerimiz sayfası WordPress’te taslak</span>
                            @elseif ($canWrite)
                                <button type="button" wire:click="sendHub({{ $brandId }})" wire:confirm="Tüm şubeleri (adres, telefon, saat, yol tarifi, şube sayfası) listeleyen “{{ $brandName }} Şubeleri” sayfası WordPress’e taslak olarak gönderilsin mi? Bilgiler profillerden gelir, AI kullanılmaz." @disabled($hub['ready'] < 2) class="font-medium text-brand-600 hover:underline disabled:cursor-not-allowed disabled:text-gray-400" title="{{ $hub['ready'] < 2 ? 'Önce profil verisi çekilmeli' : 'Tüm şubelere bağlantı veren sayfa' }}">Şubelerimiz sayfası oluştur</button>
                            @endif
                        </span>
                    @endif
                    @if ($canWrite && $b && $b['missing'] > 0)
                        <button type="button" wire:click="prepareMissing({{ $brandId }})" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">{{ $b['missing'] }} sayfayı hazırla</button>
                    @endif
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($locations as $location)
                        @php
                            $s = $states[$location->id];
                            $page = $s['page'];
                            $row = $s['row'];
                            $link = $s['link'];
                            $job = $running[$location->id] ?? null;
                            $jobRunning = ($job['status'] ?? '') === 'running';
                            $markup = $markups[$location->id] ?? null;
                            $multi = ! in_array($s['state'], ['single', 'no_site'], true);
                        @endphp
                        <div wire:key="bp-{{ $location->id }}" class="px-4 py-3">
                            <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone($s['state']) }}">{{ $s['label'] }}</span>
                                        @if ($markup)<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">İşaretleme {{ $markup === 'succeeded' ? 'var' : 'ekleniyor' }}</span>@endif
                                    </div>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        Profilin bağlantısı:
                                        @if ($link['current'] !== '')
                                            <a href="{{ $link['current'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $shortUrl($link['current']) }}</a>
                                            @if (! $link['on_site'])<span class="font-medium text-rose-600">· markanın sitesi değil</span>@elseif ($link['home'] && $multi)<span class="font-medium text-amber-600">· ana sayfa</span>@endif
                                        @else
                                            <span>{{ $s['state'] === 'no_data' || str_contains($s['label'], 'verisi') ? 'bilinmiyor' : 'yok' }}</span>
                                        @endif
                                        @if ($page && $s['state'] !== 'linked' && $s['state'] !== 'single')
                                            <br>Şube sayfası: <a href="{{ $page->url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ \Illuminate\Support\Str::limit((string) ($page->title ?: $page->path), 70) }} ↗</a>
                                            @if ($s['chosen'])<span class="text-gray-400">(senin seçimin)</span>@endif
                                        @endif
                                    </p>
                                    @if ($row?->note)<p class="mt-0.5 text-xs text-rose-600">{{ $row->note }}</p>@endif
                                    @if ($job && ($job['status'] ?? '') !== 'ready' && in_array($s['state'], ['missing', 'failed'], true))
                                        <p @class(['mt-0.5 text-xs', 'text-rose-600' => ($job['status'] ?? '') === 'failed', 'text-gray-500' => ($job['status'] ?? '') !== 'failed']) @if ($jobRunning) wire:poll.10s @endif>{{ $job['message'] ?? '' }}</p>
                                    @endif
                                </div>

                                @if ($multi && $s['state'] !== 'no_data')
                                    <ol class="flex items-center gap-1 text-[11px]" aria-label="Adımlar">
                                        @foreach ($steps as $i => $stepLabel)
                                            <li class="flex items-center gap-1">
                                                <span @class(['flex h-5 w-5 items-center justify-center rounded-full font-semibold', 'bg-emerald-500 text-white' => $i < $s['step'], 'bg-brand-500 text-white' => $i === $s['step'], 'bg-gray-200 text-gray-500 dark:bg-gray-700' => $i > $s['step']]) title="{{ $stepLabel }}">{{ $i < $s['step'] ? '✓' : $i + 1 }}</span>
                                                <span class="hidden text-gray-500 2xl:inline">{{ $stepLabel }}</span>
                                                @if (! $loop->last)<span class="h-px w-3 bg-gray-300 dark:bg-gray-600"></span>@endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif

                                @if ($canWrite)
                                    <div class="flex flex-wrap items-center gap-3 text-xs font-medium">
                                        @switch($s['state'])
                                            @case('missing')
                                                <button type="button" wire:click="prepare({{ $location->id }})" @disabled($jobRunning) class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white hover:bg-brand-600 disabled:opacity-50">{{ $jobRunning ? 'Yazılıyor…' : 'Sayfayı hazırla' }}</button>
                                                <button type="button" wire:click="startPicking({{ $location->id }})" class="text-gray-600 hover:underline dark:text-gray-300">Sitedeki sayfayı seç</button>
                                                @break
                                            @case('ready')
                                            @case('failed')
                                                <button type="button" wire:click="toggle({{ $location->id }})" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white hover:bg-brand-600">{{ $open === (int) $location->id ? 'Kapat' : 'Oku ve gönder' }}</button>
                                                <button type="button" wire:click="prepare({{ $location->id }})" @disabled($jobRunning) class="text-gray-600 hover:underline disabled:opacity-50 dark:text-gray-300">Yeniden yaz</button>
                                                @break
                                            @case('sent')
                                                @if ($row?->edit_url)<a href="{{ $row->edit_url }}" target="_blank" rel="noopener" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white hover:bg-brand-600">WordPress’te aç ve yayınla ↗</a>@else<span class="text-gray-500">Taslak oluşturuluyor…</span>@endif
                                                @break
                                            @case('unlinked')
                                                <button type="button" wire:click="link({{ $location->id }}, {{ $page->id }})" wire:confirm="Profilin web sitesi bağlantısı bu sayfaya çevrilsin mi? (Geri alınabilir.)" class="rounded-lg bg-success-500 px-3 py-1.5 font-semibold text-white hover:bg-success-600">Profili bu sayfaya bağla</button>
                                                @if (! $markup && $page->wp_post_id)<button type="button" wire:click="markup({{ $location->id }}, {{ $page->id }})" class="text-brand-600 hover:underline">İşaretleme ekle</button>@endif
                                                @if ($s['chosen'])<button type="button" wire:click="unchoose({{ $location->id }})" class="text-gray-500 hover:underline">Seçimi kaldır</button>@else<button type="button" wire:click="startPicking({{ $location->id }})" class="text-gray-500 hover:underline">Başka sayfa seç</button>@endif
                                                @break
                                            @case('linked')
                                                @if (! $markup && $page?->wp_post_id)<button type="button" wire:click="markup({{ $location->id }}, {{ $page->id }})" class="text-brand-600 hover:underline">İşaretleme ekle</button>@endif
                                                @break
                                            @case('single')
                                                @if (! $link['on_site'] && $page)<button type="button" wire:click="link({{ $location->id }}, {{ $page->id }})" wire:confirm="Profilin web sitesi bağlantısı markanın ana sayfasına çevrilsin mi? (Geri alınabilir.)" class="text-success-600 hover:underline">Profili ana sayfaya bağla</button>@endif
                                                @break
                                        @endswitch
                                    </div>
                                @endif
                            </div>

                            @if ($picking === (int) $location->id)
                                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <input type="search" wire:model.live.debounce.300ms="pageQuery" placeholder="Sayfa başlığı ya da adresinde ara (ör. çiğli, şube)" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                                    <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                                        @forelse ($pickResults as $result)
                                            <li wire:key="pick-{{ $result->id }}" class="flex flex-wrap items-center gap-3 py-1.5">
                                                <span class="min-w-0 flex-1 truncate"><span class="text-gray-900 dark:text-white">{{ $result->title ?: $result->path }}</span> <span class="text-xs text-gray-500">{{ $result->path }}</span></span>
                                                <a href="{{ $result->url }}" target="_blank" rel="noopener" class="text-xs text-gray-500 hover:underline">Aç ↗</a>
                                                <button type="button" wire:click="choose({{ $location->id }}, {{ $result->id }})" class="text-xs font-medium text-brand-600 hover:underline">Bu sayfayı kullan</button>
                                            </li>
                                        @empty
                                            <li class="py-1.5 text-xs text-gray-500">{{ $pageQuery === '' ? 'Şube / iletişim sayfası bulunamadı; aramayı deneyin.' : 'Eşleşen sayfa yok.' }}</li>
                                        @endforelse
                                    </ul>
                                </div>
                            @endif

                            @if ($open === (int) $location->id && $openRow && in_array($openRow->status, ['ready', 'failed'], true))
                                @php $content = (array) $openRow->content; @endphp
                                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                                    <div class="space-y-2 text-sm">
                                        <label class="block"><span class="text-xs text-gray-500">Başlık</span><input type="text" wire:model="form.title" maxlength="90" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                        <label class="block"><span class="text-xs text-gray-500">Adres (slug)</span><input type="text" wire:model="form.slug" maxlength="70" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                        <label class="block"><span class="text-xs text-gray-500">SEO başlığı</span><input type="text" wire:model="form.meta_title" maxlength="70" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                        <label class="block"><span class="text-xs text-gray-500">Meta açıklama</span><textarea wire:model="form.meta_description" rows="2" maxlength="160" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                        <label class="block"><span class="text-xs text-gray-500">Giriş (paragrafları boş satırla ayır)</span><textarea wire:model="form.intro" rows="7" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                        <label class="block"><span class="text-xs text-gray-500">Ulaşım</span><textarea wire:model="form.access" rows="2" maxlength="500" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                        @if ((array) $openRow->issues !== [])
                                            <div class="rounded-lg bg-rose-50 p-2 text-xs text-rose-800 dark:bg-rose-950 dark:text-rose-200">
                                                @foreach ((array) $openRow->issues as $issue)
                                                    <p>{{ $issue['field'] }}: «{{ $issue['matched'] }}» {{ $issue['blocking'] ? '(gönderimi durdurur)' : '' }} {{ $issue['message'] }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                        <div class="flex flex-wrap items-center gap-3 pt-1">
                                            <button type="button" wire:click="send({{ $location->id }})" wire:confirm="Sayfa WordPress’e taslak olarak gönderilsin mi? Yayınlamayı WordPress’te sen yaparsın." class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">WordPress’e taslak gönder</button>
                                            <button type="button" wire:click="save" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-200 dark:ring-gray-600">Kaydet</button>
                                            <button type="button" wire:click="discard({{ $location->id }})" wire:confirm="Hazırlanan metin silinsin mi?" class="text-xs text-gray-500 hover:underline">Taslağı sil</button>
                                        </div>
                                    </div>
                                    <div class="rounded-lg bg-white p-4 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                                        <p class="mb-2 text-[11px] uppercase text-gray-400">Sayfa önizlemesi</p>
                                        <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $content['title'] ?? '' }}</h2>
                                        <div class="prose prose-sm max-w-none dark:prose-invert [&_h2]:mt-4 [&_h2]:font-semibold [&_h3]:mt-2 [&_h3]:font-medium [&_li]:ml-4 [&_li]:list-disc [&_p]:my-1.5 [&_td]:pl-3">{!! \App\Services\Gbp\Desk\BranchPages::html($content) !!}</div>
                                        <p class="mt-3 text-[11px] text-gray-400">Adres, telefon, saatler ve harita bağlantısı profilden gelir; sayfaya ayrıca yerel işletme işaretlemesi (JSON-LD) eklenir.</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="rounded-xl bg-white px-4 py-5 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">{{ $filter !== '' ? 'Bu durumda şube yok.' : 'Operasyonel markaya bağlı İşletme Profili yok.' }}</p>
        @endforelse
    </section>
</div>
