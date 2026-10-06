@php
    $tone = fn (string $state): string => match ($state) {
        'linked' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'unlinked', 'ready' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'sent' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'failed', 'missing' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    };
@endphp
<div class="space-y-5 dark:text-gray-200" data-gbp-branch-pages>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme profilleri</h1>
            <p class="mt-1 max-w-3xl text-xs text-gray-500">Her şube, sitede kendi sayfasına bağlanmalı: adres, çalışma saatleri, o şubenin hizmetleri ve yerel işletme işaretlemesi. Google profili ana sayfa yerine bu sayfaya bağlı olunca şube kendi bölgesinde daha iyi sıralanır. Akış: sayfayı hazırla → oku → WordPress’e taslak gönder → WordPress’te yayınla → profili sayfaya bağla.</p>
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-branch-pages', 'brandFilter' => $brand])
    @include('livewire.operator.gbp.partials.desk-message')

    <section class="flex flex-wrap items-center gap-2">
        @foreach (['linked', 'unlinked', 'sent', 'ready', 'missing', 'no_site'] as $key)
            @if (($counts[$key] ?? 0) > 0)<span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $tone($key) }}">{{ $counts[$key] }} · {{ $labels[$key] }}</span>@endif
        @endforeach
        @if ($canWrite && (($counts['missing'] ?? 0) + ($counts['failed'] ?? 0)) > 0)
            <button type="button" wire:click="prepareMissing" class="ml-auto rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600">Eksik sayfaları hazırla ({{ ($counts['missing'] ?? 0) + ($counts['failed'] ?? 0) }})</button>
        @endif
    </section>

    <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
        @forelse ($groups as $brandName => $locations)
            <div class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.03]">{{ $brandName }} · {{ $locations->count() }}</div>
            @foreach ($locations as $location)
                @php
                    $s = $states[$location->id];
                    $page = $s['page'];
                    $row = $s['row'];
                    $job = $running[$location->id] ?? null;
                    $markup = $markups[$location->id] ?? null;
                @endphp
                <div wire:key="bp-{{ $location->id }}" class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                            <span class="block text-xs text-gray-500">
                                @if ($page)<a href="{{ $page->url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ \Illuminate\Support\Str::limit((string) ($page->title ?: $page->path), 70) }} ↗</a>@endif
                                @if ($row?->note) <span class="text-rose-600">{{ $row->note }}</span>@endif
                                @if ($job && ($job['status'] ?? '') !== 'ready')<span @class(['text-rose-600' => ($job['status'] ?? '') === 'failed']) @if (($job['status'] ?? '') === 'running') wire:poll.10s @endif>{{ $job['message'] ?? '' }}</span>@endif
                            </span>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone($s['state']) }}">{{ $s['label'] }}</span>
                        @if ($markup)<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">İşaretleme {{ $markup === 'succeeded' ? 'eklendi' : 'ekleniyor' }}</span>@endif
                        @if ($canWrite)
                            <span class="flex flex-wrap gap-3 text-xs font-medium">
                                @switch($s['state'])
                                    @case('unlinked')
                                        <button type="button" wire:click="link({{ $location->id }}, {{ $page->id }})" wire:confirm="Profilin web sitesi bağlantısı bu sayfaya çevrilsin mi? (Geri alınabilir.)" class="text-success-600 hover:underline">Profili bu sayfaya bağla</button>
                                        @if (! $markup)<button type="button" wire:click="markup({{ $location->id }}, {{ $page->id }})" class="text-brand-600 hover:underline">İşaretleme ekle</button>@endif
                                        @break
                                    @case('linked')
                                        @if (! $markup && $page?->wp_post_id)<button type="button" wire:click="markup({{ $location->id }}, {{ $page->id }})" class="text-brand-600 hover:underline">İşaretleme ekle</button>@endif
                                        @break
                                    @case('sent')
                                        @if ($row?->edit_url)<a href="{{ $row->edit_url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">WordPress’te aç ↗</a>@endif
                                        @break
                                    @case('ready')
                                    @case('failed')
                                        <button type="button" wire:click="toggle({{ $location->id }})" class="text-brand-600 hover:underline">{{ $open === (int) $location->id ? 'Kapat' : 'Oku / düzenle' }}</button>
                                        <button type="button" wire:click="send({{ $location->id }})" wire:confirm="Sayfa WordPress’e taslak olarak gönderilsin mi? Yayınlamayı WordPress’te sen yaparsın." class="text-success-600 hover:underline">WordPress’e taslak gönder</button>
                                        <button type="button" wire:click="prepare({{ $location->id }})" class="text-gray-600 hover:underline dark:text-gray-300">Yeniden yaz</button>
                                        @break
                                    @case('missing')
                                        <button type="button" wire:click="prepare({{ $location->id }})" @disabled(($job['status'] ?? '') === 'running') class="text-brand-600 hover:underline disabled:opacity-50">Sayfayı hazırla</button>
                                        @break
                                @endswitch
                            </span>
                        @endif
                    </div>

                    @if ($open === (int) $location->id && $openRow)
                        @php $content = (array) $openRow->content; @endphp
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <div class="space-y-2 text-sm">
                                <label class="block"><span class="text-xs text-gray-500">Başlık</span><input type="text" wire:model="form.title" maxlength="90" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                <label class="block"><span class="text-xs text-gray-500">Adres (slug)</span><input type="text" wire:model="form.slug" maxlength="70" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                <label class="block"><span class="text-xs text-gray-500">SEO başlığı</span><input type="text" wire:model="form.meta_title" maxlength="70" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
                                <label class="block"><span class="text-xs text-gray-500">Meta açıklama</span><textarea wire:model="form.meta_description" rows="2" maxlength="160" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                <label class="block"><span class="text-xs text-gray-500">Giriş (paragrafları boş satırla ayır)</span><textarea wire:model="form.intro" rows="7" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                <label class="block"><span class="text-xs text-gray-500">Ulaşım</span><textarea wire:model="form.access" rows="2" maxlength="500" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                <div class="flex flex-wrap gap-3">
                                    <button type="button" wire:click="save" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white">Kaydet</button>
                                    <button type="button" wire:click="discard({{ $location->id }})" wire:confirm="Hazırlanan metin silinsin mi?" class="text-xs text-gray-500 hover:underline">Taslağı sil</button>
                                </div>
                                @if ((array) $openRow->issues !== [])
                                    <div class="rounded-lg bg-rose-50 p-2 text-xs text-rose-800 dark:bg-rose-950 dark:text-rose-200">
                                        @foreach ((array) $openRow->issues as $issue)
                                            <p>{{ $issue['field'] }}: «{{ $issue['matched'] }}» {{ $issue['blocking'] ? '(gönderimi durdurur)' : '' }} {{ $issue['message'] }}</p>
                                        @endforeach
                                    </div>
                                @endif
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
        @empty
            <p class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok.</p>
        @endforelse
    </section>
</div>
