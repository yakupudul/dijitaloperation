@php
    // İşletme profilleri › Şube sayfaları (panel concept 2026-10-20). The question: "Which branch profiles still need their
    // own page on the site, and what is the next step for each?" The work filters are the numbers on top; then one card per
    // brand (progress and bulk buttons in its head), one row per profile with its next step on the right.
    $tone = fn (string $state): string => match ($state) {
        'linked', 'single' => 'ok',
        'missing', 'failed', 'sent' => 'look',
        default => 'info',
    };
    $shortUrl = fn (string $url): string => \Illuminate\Support\Str::limit((string) preg_replace('~^https?://(www\.)?~', '', rtrim(strtok($url, '?') ?: $url, '/')) ?: '/', 60);
    $input = 'rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:focus:ring-white/10';
    $todo = array_sum($counts) - ($counts['tamam'] ?? 0);
    $sub = $todo === 0 ? 'Bütün profiller kendi sayfasına bağlı.' : $todo.' profilde sıradaki adım bekliyor, '.($counts['tamam'] ?? 0).' tamam.';
@endphp
<div class="dark:text-gray-200" data-gbp-branch-pages>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-branch-pages', 'brandFilter' => $brand])

    <div class="space-y-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="page-title" title="Birden çok şubesi olan markada her Google profili, sitede o şubenin kendi sayfasına bağlanmalı (adres, saatler, o şubenin hizmetleri, yerel işletme işaretlemesi); ana sayfaya bağlı şubeler birbirinin yerine sıralanır. Tek işletmeli markada ana sayfa yeterlidir. Her satır sıradaki adımı gösterir.">İşletme profilleri</h1>
                <p class="page-sub">{{ $sub }}</p>
            </div>
            @include('livewire.operator.gbp.partials.brand-filter')
        </header>
        @include('livewire.operator.gbp.partials.desk-message')

        {{-- 1. The work, as numbers you can click to filter. --}}
        <section class="space-y-2.5">
            @php $firstTodo = collect(['hazirla', 'oku', 'yayinla', 'bagla'])->first(fn (string $k): bool => ($counts[$k] ?? 0) > 0); @endphp
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" aria-label="Durum süzgeci">
                @foreach ($filters as $key => $f)
                    @continue(($counts[$key] ?? 0) === 0 && $filter !== $key)
                    <button type="button" wire:click="setFilter('{{ $key }}')" @class(['soft-card gap-1.5! text-left', 'soft-accent' => $key === $firstTodo, 'soft-amber' => in_array($key, ['veri', 'site'], true) && ($counts[$key] ?? 0) > 0, 'ring-2 ring-gray-900 dark:ring-white' => $filter === $key]) @if ($filter === $key) aria-pressed="true" @endif>
                        <span class="soft-number text-[26px]!">{{ $counts[$key] ?? 0 }}</span>
                        <span class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $f['label'] }}</span>
                    </button>
                @endforeach
            </div>
            @if ($filter !== '' || (($counts['veri'] ?? 0) > 0 && $filter === ''))
                <div class="flex flex-wrap items-start gap-x-4 gap-y-1 text-[13px]">
                    @if (($counts['veri'] ?? 0) > 0 && $filter === '')
                        <p class="flex min-w-0 flex-1 items-start gap-2 text-gray-600 dark:text-gray-300" title="Profil verisi toplandıkça satırlar kendiliğinden açılır (İşletme Profili bağlantısının Google hesabında yetkili olduğundan emin olun)."><span class="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>{{ $counts['veri'] }} profilin adres, saat ve bağlantı bilgisi henüz Google’dan çekilmedi; bunlar için sayfa yazılamaz.</p>
                    @endif
                    @if ($filter !== '')<button type="button" wire:click="setFilter('{{ $filter }}')" class="link-neutral">Süzmeyi kaldır</button>@endif
                </div>
            @endif
        </section>

        {{-- 2. One card per brand. --}}
        @forelse ($groups as $brandName => $locations)
            @php
                $brandId = (int) $locations->first()->brand_id;
                $b = $brands[$brandId] ?? null;
                $hub = $b['hub'] ?? null;
            @endphp
            <section wire:key="brand-{{ $brandId }}">
                <div class="section-head items-center">
                    <h2 class="section-title flex min-w-0 flex-1 items-center gap-2"><x-ta.brand-avatar :name="(string) $brandName" :logo="$locations->first()?->brand?->logo_url" size="sm" /> <span class="min-w-0 break-words">{{ $brandName }} <span class="section-count">{{ $locations->count() }}</span></span></h2>
                    @if ($b)
                        <span class="flex items-center gap-2 text-[13px] text-gray-500 max-sm:w-full" title="{{ $b['no_data'] > 0 ? $b['no_data'].' profil verisi bekliyor' : '' }}">
                            <span class="block h-1 w-16 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"><span class="block h-full bg-gray-900 dark:bg-white" style="width: {{ $b['total'] > 0 ? round($b['done'] / $b['total'] * 100) : 0 }}%"></span></span>
                            <span class="tabular-nums">{{ $b['done'] }}/{{ $b['total'] }} {{ $b['total'] === 1 ? 'işletme' : 'şube' }} tamam{{ $b['no_data'] > 0 ? ', '.$b['no_data'].' profil verisi bekliyor' : '' }}</span>
                        </span>
                    @endif
                    @if ($hub || ($canWrite && $b && $b['missing'] > 0))
                        <span class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                            @if ($hub)
                                @if ($hub['page'])
                                    <a href="{{ $hub['page']->url }}" target="_blank" rel="noopener" class="btn-quiet h-7! px-2!"><x-ta.pill tone="ok">Şubelerimiz sayfası var</x-ta.pill></a>
                                @elseif ($hub['action'] && in_array($hub['action']->status, ['queued', 'running', 'succeeded', 'partial'], true))
                                    <x-ta.pill tone="look">Şubelerimiz sayfası WordPress’te taslak</x-ta.pill>
                                @elseif ($canWrite)
                                    <button type="button" wire:click="sendHub({{ $brandId }})" wire:confirm="Tüm şubeleri (adres, telefon, saat, yol tarifi, şube sayfası) listeleyen “{{ $brandName }} Şubeleri” sayfası WordPress’e taslak olarak gönderilsin mi? Bilgiler profillerden gelir, AI kullanılmaz." @disabled($hub['ready'] < 2) class="btn-quiet disabled:cursor-not-allowed disabled:opacity-50" title="{{ $hub['ready'] < 2 ? 'Önce profil verisi çekilmeli' : 'Tüm şubelere bağlantı veren sayfa' }}">Şubelerimiz sayfası oluştur</button>
                                @endif
                            @endif
                            @if ($canWrite && $b && $b['missing'] > 0)
                                <button type="button" wire:click="prepareMissing({{ $brandId }})" class="btn flex-1 sm:flex-none">{{ $b['missing'] }} sayfayı hazırla</button>
                            @endif
                        </span>
                    @endif
                </div>

                <div class="card">
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
                        <div wire:key="bp-{{ $location->id }}" class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2.5 px-4 py-3.5">
                                <div class="min-w-0 basis-full sm:flex-1 sm:basis-0">
                                    <p class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                        <span class="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                        <x-ta.pill :tone="$tone($s['state'])">{{ $s['label'] }}</x-ta.pill>
                                        @if ($markup)<span class="text-xs text-gray-500">İşaretleme {{ $markup === 'succeeded' ? 'var' : 'ekleniyor' }}</span>@endif
                                    </p>
                                    <p class="mt-0.5 truncate text-[13px] text-gray-500">
                                        Profilin bağlantısı:
                                        @if ($link['current'] !== '')
                                            <a href="{{ $link['current'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $shortUrl($link['current']) }}</a>@if (! $link['on_site'])<span class="font-medium text-error-600 dark:text-error-400">, markanın sitesi değil</span>@elseif ($link['home'] && $multi)<span class="font-medium text-warning-700 dark:text-warning-400">, ana sayfa</span>@endif
                                        @else
                                            <span>{{ $s['state'] === 'no_data' || str_contains($s['label'], 'verisi') ? 'bilinmiyor' : 'yok' }}</span>
                                        @endif
                                    </p>
                                    @if ($page && $s['state'] !== 'linked' && $s['state'] !== 'single')
                                        <p class="truncate text-[13px] text-gray-500">Şube sayfası: <a href="{{ $page->url }}" target="_blank" rel="noopener" class="link-neutral">{{ \Illuminate\Support\Str::limit((string) ($page->title ?: $page->path), 70) }}</a>@if ($s['chosen']) <span class="text-gray-500">(senin seçimin)</span>@endif</p>
                                    @endif
                                    @if ($row?->note)<p class="mt-0.5 text-[13px] text-error-600 dark:text-error-400">{{ $row->note }}</p>@endif
                                    @if ($job && ($job['status'] ?? '') !== 'ready' && in_array($s['state'], ['missing', 'failed'], true))
                                        <p @class(['mt-0.5 text-[13px]', 'text-error-600 dark:text-error-400' => ($job['status'] ?? '') === 'failed', 'text-gray-500' => ($job['status'] ?? '') !== 'failed']) @if ($jobRunning) wire:poll.10s @endif>{{ $job['message'] ?? '' }}</p>
                                    @endif
                                </div>

                                @if ($multi && $s['state'] !== 'no_data')
                                    <ol class="flex items-center gap-1.5 text-xs text-gray-500" aria-label="Adımlar">
                                        @foreach ($steps as $i => $stepLabel)
                                            <li class="flex items-center gap-1.5" title="{{ $i + 1 }}. {{ $stepLabel }}">
                                                <span @class(['h-1.5 w-1.5 rounded-full', 'bg-gray-900 dark:bg-white' => $i < $s['step'], 'ring-1 ring-gray-900 dark:ring-white' => $i === $s['step'], 'bg-gray-300 dark:bg-gray-600' => $i > $s['step']])></span>
                                                @if ($i === $s['step'])<span class="whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $stepLabel }}</span>@endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif

                                @if ($canWrite)
                                    <div class="flex flex-wrap items-center gap-1.5 max-sm:w-full">
                                        @switch($s['state'])
                                            @case('missing')
                                                <button type="button" wire:click="prepare({{ $location->id }})" @disabled($jobRunning) class="btn max-sm:flex-1">{{ $jobRunning ? 'Yazılıyor…' : 'Sayfayı hazırla' }}</button>
                                                <button type="button" wire:click="startPicking({{ $location->id }})" class="btn-quiet">Sitedeki sayfayı seç</button>
                                                @break
                                            @case('ready')
                                            @case('failed')
                                                <button type="button" wire:click="toggle({{ $location->id }})" @class(['max-sm:flex-1', 'btn' => $open !== (int) $location->id, 'btn-quiet' => $open === (int) $location->id])>{{ $open === (int) $location->id ? 'Kapat' : 'Oku ve gönder' }}</button>
                                                <button type="button" wire:click="prepare({{ $location->id }})" @disabled($jobRunning) class="btn-quiet">Yeniden yaz</button>
                                                @break
                                            @case('sent')
                                                @if ($row?->wp_post_id && $row->website_asset_id && ($branchSite = \App\Models\DigitalAsset::query()->find($row->website_asset_id)))<x-operator.open-link :link="app(\App\Services\Operator\ExternalLinks::class)->wordPressPost($branchSite, (int) $row->wp_post_id)" variant="button" label="WordPress’te aç ve yayınla" class="max-sm:flex-1" />@elseif ($row?->edit_url)<a href="{{ $row->edit_url }}" target="_blank" rel="noopener" class="btn max-sm:flex-1">WordPress’te aç ve yayınla</a>@else<span class="text-[13px] text-gray-500">Taslak oluşturuluyor…</span>@endif
                                                @break
                                            @case('unlinked')
                                                <button type="button" wire:click="link({{ $location->id }}, {{ $page->id }})" wire:confirm="Profilin web sitesi bağlantısı bu sayfaya çevrilsin mi? (Geri alınabilir.)" class="btn max-sm:flex-1">Profili bu sayfaya bağla</button>
                                                @if (! $markup && $page->wp_post_id)<button type="button" wire:click="markup({{ $location->id }}, {{ $page->id }})" class="btn-quiet">İşaretleme ekle</button>@endif
                                                @if ($s['chosen'])<button type="button" wire:click="unchoose({{ $location->id }})" class="btn-quiet">Seçimi kaldır</button>@else<button type="button" wire:click="startPicking({{ $location->id }})" class="btn-quiet">Başka sayfa seç</button>@endif
                                                @break
                                            @case('linked')
                                                @if (! $markup && $page?->wp_post_id)<button type="button" wire:click="markup({{ $location->id }}, {{ $page->id }})" class="btn-quiet">İşaretleme ekle</button>@endif
                                                @break
                                            @case('single')
                                                @if (! $link['on_site'] && $page)<button type="button" wire:click="link({{ $location->id }}, {{ $page->id }})" wire:confirm="Profilin web sitesi bağlantısı markanın ana sayfasına çevrilsin mi? (Geri alınabilir.)" class="btn max-sm:flex-1">Profili ana sayfaya bağla</button>@endif
                                                @break
                                        @endswitch
                                    </div>
                                @endif
                            </div>

                            @if ($picking === (int) $location->id)
                                <div class="border-t border-gray-200 bg-gray-50/60 px-4 py-4 dark:border-gray-800 dark:bg-white/[0.02]">
                                    <input type="search" wire:model.live.debounce.300ms="pageQuery" placeholder="Sayfa başlığı ya da adresinde ara (ör. çiğli, şube)" class="h-9 w-full {{ $input }}">
                                    <ul class="card mt-2.5">
                                        @forelse ($pickResults as $result)
                                            <li wire:key="pick-{{ $result->id }}" class="card-row flex-wrap gap-2 py-2.5">
                                                <span class="min-w-0 flex-1 truncate text-sm"><span class="text-gray-900 dark:text-white">{{ $result->title ?: $result->path }}</span> <span class="text-[13px] text-gray-500">{{ $result->path }}</span></span>
                                                <a href="{{ $result->url }}" target="_blank" rel="noopener" class="btn-quiet h-7! px-2!">Aç</a>
                                                <button type="button" wire:click="choose({{ $location->id }}, {{ $result->id }})" class="btn h-7! px-2.5!">Bu sayfayı kullan</button>
                                            </li>
                                        @empty
                                            <li><x-ta.empty-state size="sm" icon="none" :title="$pageQuery === '' ? 'Şube / iletişim sayfası bulunamadı; aramayı deneyin.' : 'Eşleşen sayfa yok.'" /></li>
                                        @endforelse
                                    </ul>
                                </div>
                            @endif

                            @if ($open === (int) $location->id && $openRow && in_array($openRow->status, ['ready', 'failed'], true))
                                @php $content = (array) $openRow->content; @endphp
                                <div class="grid gap-5 border-t border-gray-200 bg-gray-50/60 px-4 py-4 lg:grid-cols-2 dark:border-gray-800 dark:bg-white/[0.02]">
                                    <div class="space-y-3 text-sm">
                                        <label class="block"><span class="text-[13px] text-gray-500">Başlık</span><input type="text" wire:model="form.title" maxlength="90" class="mt-1 w-full {{ $input }}"></label>
                                        <label class="block"><span class="text-[13px] text-gray-500">Adres (slug)</span><input type="text" wire:model="form.slug" maxlength="70" class="mt-1 w-full {{ $input }}"></label>
                                        <label class="block"><span class="text-[13px] text-gray-500">SEO başlığı</span><input type="text" wire:model="form.meta_title" maxlength="70" class="mt-1 w-full {{ $input }}"></label>
                                        <label class="block"><span class="text-[13px] text-gray-500">Meta açıklama</span><textarea wire:model="form.meta_description" rows="2" maxlength="160" class="mt-1 w-full {{ $input }}"></textarea></label>
                                        <label class="block"><span class="text-[13px] text-gray-500">Giriş (paragrafları boş satırla ayır)</span><textarea wire:model="form.intro" rows="7" class="mt-1 w-full {{ $input }}"></textarea></label>
                                        <label class="block"><span class="text-[13px] text-gray-500">Ulaşım</span><textarea wire:model="form.access" rows="2" maxlength="500" class="mt-1 w-full {{ $input }}"></textarea></label>
                                        @if ((array) $openRow->issues !== [])
                                            <div class="tone-look space-y-0.5 rounded-lg px-3 py-2.5 text-[13px]">
                                                @foreach ((array) $openRow->issues as $issue)
                                                    <p>{{ $issue['field'] }}: «{{ $issue['matched'] }}» {{ $issue['blocking'] ? '(gönderimi durdurur)' : '' }} {{ $issue['message'] }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                        <div class="grid gap-2 pt-1 sm:flex sm:flex-wrap">
                                            <button type="button" wire:click="send({{ $location->id }})" wire:confirm="Sayfa WordPress’e taslak olarak gönderilsin mi? Yayınlamayı WordPress’te sen yaparsın." class="btn-dark">WordPress’e taslak gönder</button>
                                            <button type="button" wire:click="save" class="btn">Kaydet</button>
                                            <button type="button" wire:click="discard({{ $location->id }})" wire:confirm="Hazırlanan metin silinsin mi?" class="btn-quiet">Taslağı sil</button>
                                        </div>
                                    </div>
                                    <div class="card p-4 text-sm">
                                        <p class="mb-2 text-[13px] text-gray-500">Sayfa önizlemesi</p>
                                        <h2 class="section-title mb-2">{{ $content['title'] ?? '' }}</h2>
                                        <div class="max-w-none leading-6 text-gray-700 dark:text-gray-300 [&_a]:underline [&_h2]:mt-4 [&_h2]:text-[15px] [&_h2]:font-semibold [&_h2]:text-gray-900 dark:[&_h2]:text-white [&_h3]:mt-2 [&_h3]:font-medium [&_li]:ml-4 [&_li]:list-disc [&_p]:my-1.5 [&_td]:pl-3">{!! \App\Services\Gbp\Desk\BranchPages::html($content) !!}</div>
                                        <p class="mt-3 text-[13px] text-gray-500">Adres, telefon, saatler ve harita bağlantısı profilden gelir; sayfaya ayrıca yerel işletme işaretlemesi (JSON-LD) eklenir.</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="card"><x-ta.empty-state size="sm" icon="none" :title="$filter !== '' ? 'Bu durumda şube yok.' : 'Operasyonel markaya bağlı İşletme Profili yok.'" /></div>
        @endforelse
    </div>
</div>
