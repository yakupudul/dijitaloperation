@php
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $input = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700 focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $small = 'btn h-7! px-2.5! text-xs!';
    $types = collect($groups)->map(fn ($group) => $group['label'])->all();
    $fieldLabels = ['seo_title' => 'SEO başlığı', 'meta_description' => 'Meta açıklama'];
    $num = fn ($value) => number_format((int) $value, 0, ',', '.');
    $icons = [
        'pulse' => '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
        'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3 17.8V21h3.2l6.3-6.3a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
        'hand' => '<path d="M8 13V5.5a1.5 1.5 0 0 1 3 0V12M11 11.5v-2a1.5 1.5 0 0 1 3 0V12M14 10.5a1.5 1.5 0 0 1 3 0V12M17 11.5a1.5 1.5 0 0 1 3 0V16a6 6 0 0 1-6 6h-2c-2.2 0-3.6-1-4.8-2.6L4.4 15.5a1.5 1.5 0 0 1 2.4-1.8L8 15"/>',
    ];
    $iconTone = ['critical' => 'border-transparent bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400', 'look' => 'border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
        'ok' => 'border-transparent bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400'];
    $health = $summary['health'];
    $fixing = $summary['fixing'];
    $waiting = $summary['waiting'];
    $brandId = (int) \App\Models\DigitalAsset::query()->whereKey($this->assetId)->value('brand_id');
@endphp
<div class="space-y-10" data-suggestions @if ($applyStatus === 'çalışıyor…' || $standardStatus === 'çalışıyor…' || collect($cards)->contains(fn ($card) => str_ends_with((string) ($card['button']['label'] ?? ''), '…'))) wire:poll.10s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="btn-quiet h-7! px-2!">×</button>
        </p>
    @endif
    @if ($errors->any())<p class="text-[13px] text-error-600">{{ $errors->first() }}</p>@endif

    {{-- 1. The page's answer as three tinted cards: is the site healthy, what is being fixed, what waits on yakup. --}}
    @php
        $healthCard = ['critical' => 'soft-red', 'look' => 'soft-amber'][$health['tone']] ?? '';
        $healthIcon = ['critical' => 'text-error-600 dark:text-error-400', 'look' => 'text-warning-600 dark:text-warning-400', 'ok' => 'text-success-600 dark:text-success-400'][$health['tone']] ?? '';
    @endphp
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-site-summary>
        <div @class(['soft-card', $healthCard]) data-summary="health" data-tone="{{ $health['tone'] }}">
            <span class="soft-icon {{ $healthIcon }}" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $icons['pulse'] !!}</svg></span>
            @if (preg_match('/(\d+) sayfada (.+)$/u', $health['title'], $healthParts) === 1)
                <p class="soft-number">{{ $healthParts[1] }} sayfa</p>
                <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white" title="{{ $health['title'] }}">{{ \Illuminate\Support\Str::ucfirst($healthParts[2]) }}</p>
            @else
                <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">{{ $health['title'] }}</p>
            @endif
            @if ($health['line'] !== '')<p class="text-[13px] text-gray-600 dark:text-gray-300">{{ $health['line'] }}.</p>@endif
            <div class="mt-auto pt-1"><button type="button" wire:click="$parent.setTab('teknik')" class="btn">Teknik SEO</button></div>
        </div>
        <div class="soft-card" data-summary="fixing">
            <span class="soft-icon" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $icons['tool'] !!}</svg></span>
            @if ($fixing['approved'] > 0)
                <p class="soft-number">{{ $num($fixing['approved']) }} düzeltme</p>
                <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">Sürüyor</p>
            @else
                <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">Şu an süren düzeltme yok</p>
            @endif
            <p class="text-[13px] text-gray-600 dark:text-gray-300">{{ $fixing['written'] > 0 ? 'Son 28 günde '.$num($fixing['written']).' düzeltme siteye yazıldı, Google yeniden okuyor.' : 'Son 28 günde siteye yazılan düzeltme yok.' }}</p>
            @if ($fixing['approved'] > 0 || $fixing['written'] > 0)
                <div class="mt-auto pt-1"><button type="button" wire:click="$set('status', '{{ $fixing['approved'] > 0 ? 'approved' : 'applied' }}')" class="btn">Gör</button></div>
            @endif
        </div>
        <div @class(['soft-card sm:col-span-2 lg:col-span-1', 'soft-accent' => $waiting['open'] > 0]) data-summary="waiting">
            <span class="soft-icon" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $icons['hand'] !!}</svg></span>
            @if ($waiting['open'] > 0)
                <p class="soft-number" data-waiting-count>{{ $num($waiting['open']) }} iş</p>
                <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">Senden karar bekliyor</p>
            @else
                <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">Senden beklenen iş yok</p>
            @endif
            <p class="text-[13px] text-gray-600 dark:text-gray-300">{{ $waiting['open'] === 0 ? 'Yeni öneriler haftalık analizde gelir.' : ($waiting['ready'] > 0 ? $num($waiting['ready']).' tanesi hazır, gönderince siteye yazılır. Hepsi geri alınabilir.' : 'Her biri için önce düzeltme hazırlanır, sonra sen gönderirsin.') }}</p>
            @if ($waiting['open'] > 0 && $brandId > 0)
                <div class="mt-auto pt-1"><a href="{{ route('operator.repair', ['marka' => $brandId]) }}" wire:navigate class="btn-primary h-10! px-4! text-sm!">Onarım masası</a></div>
            @endif
        </div>
    </section>

    {{-- 2. The list: issue groups in plain words, the most valuable first. --}}
    <section data-section="suggestions">
        <div class="section-head">
            <h2 class="section-title">{{ $status === 'open' ? 'Bekleyen işler' : (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$status] ?? 'Tüm işler') }} <span class="section-count">{{ $num($suggestions->total()) }}</span></h2>
            <span class="text-[13px] text-gray-500">en çok getirecek olan üstte</span>
            <span class="ml-auto flex flex-wrap items-center gap-1" data-image-alts>
                @if ($altStatus)<span class="text-xs text-gray-500">Resim açıklamaları: {{ $altStatus }}</span>@endif
                <button type="button" wire:click="proposeAlts" class="btn-quiet h-7! px-2! text-[13px]!">Resim açıklamalarını hazırla</button>
                <x-operator.ai-prompt-info operation="site.image_alts" />
            </span>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-2" data-filters>
            {{-- URL search: filters as you type; the site's addresses are never listed into the page. --}}
            <input type="search" wire:model.live.debounce.400ms="urlSearch" placeholder="URL ara" aria-label="URL ara" autocomplete="off" class="{{ $select }} w-full min-w-0 pr-2.5! sm:w-56" data-url-search>
            @if ($pagePath !== null)
                <span class="inline-flex h-8 min-w-0 max-w-full items-center gap-1 rounded-lg border border-gray-200 bg-white pl-2.5 pr-1 text-[13px] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" data-page-filter>
                    <span class="min-w-0 truncate" title="{{ $pagePath }}">{{ $pagePath }}</span>
                    <button type="button" wire:click="$set('pageFilter', '')" class="btn-quiet h-6! w-6! px-0!" aria-label="Sayfa filtresini kaldır">×</button>
                </span>
            @endif
            <select wire:model.live="type" aria-label="Tür" class="{{ $select }} min-w-0 flex-1 sm:w-64 sm:flex-none">
                <option value="">Tüm işler</option>
                @foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="status" aria-label="Durum" class="{{ $select }} min-w-0 flex-1 sm:w-44 sm:flex-none">
                <option value="">Tüm durumlar</option>
                @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </div>

        @php $pageGroups = $suggestions->getCollection()->groupBy(fn ($row) => (string) ($row->group ?? $row->action_type)); $firstGroup = $pageGroups->keys()->first(); @endphp
        @php $later = array_diff_key($groups, $pageGroups->all()); @endphp
        <div class="card">
            @forelse ($pageGroups as $groupType => $rows)
                @php $ready = (int) ($groups[$groupType]['ready'] ?? 0); @endphp
                <details class="group/sg border-t border-gray-200 first:border-t-0 dark:border-gray-800" data-suggestion-group="{{ $groupType }}" @if ($groupType === $firstGroup || $openId !== null && $rows->contains('id', $openId)) open @endif>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 hover:bg-gray-50 sm:flex-nowrap dark:hover:bg-white/[0.02]">
                        <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open/sg:rotate-90" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m7.5 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $groups[$groupType]['label'] ?? \App\Services\Site\SiteSuggestionTypes::label($groupType) }}</span>
                            <span class="mt-0.5 block text-[13px] text-gray-500"><span class="tabular-nums" data-group-count>{{ $num($groups[$groupType]['count'] ?? $rows->count()) }}</span> iş{{ $ready > 0 ? ', '.$num($ready).' tanesi gönderilmeye hazır' : '' }}</span>
                        </span>
                        @if ($ready > 1)
                            <span class="w-full pl-7 sm:w-auto sm:pl-0" data-group-send="{{ $groupType }}">
                                <x-ta.button variant="dark" size="sm" class="w-full sm:w-auto" wire:click.prevent="sendGroup('{{ $groupType }}')" wire:confirm="Bu gruptaki hazır {{ $ready }} iş siteye gönderilsin mi? Geri alınabilir." title="Hazır olanların hepsi tek seferde gider, geri alınabilir.">{{ $groupType === \App\Services\Site\ClusterOverlaps::TYPE ? 'Hepsini birleştir' : 'Hepsini gönder' }} ({{ $ready }})</x-ta.button>
                            </span>
                        @endif
                    </summary>
                    <ul class="border-t border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.015]">
                        @foreach ($rows as $s)
                            @php
                                $actionable = in_array($s->status, ['open', 'recheck'], true);
                                $card = $cards[$s->id] ?? ['heading' => $s->title, 'sentence' => $s->reason, 'where' => $s->page?->path, 'button' => null, 'note' => null, 'outcome' => null];
                            @endphp
                            <li class="border-t border-gray-200 py-3.5 pl-4 pr-4 first:border-t-0 sm:pl-11 dark:border-gray-800" wire:key="sg-{{ $s->id }}" data-suggestion="{{ $s->id }}" x-data="{ rejecting: false }">
                                <div class="flex flex-wrap items-start gap-x-4 gap-y-2 sm:flex-nowrap">
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                            <span class="text-sm font-medium text-gray-900 dark:text-white" data-row-heading>{{ $card['heading'] }}</span>
                                            @if ($s->status === 'recheck' || $status !== 'open')
                                                <x-ta.pill :tone="$s->status === 'recheck' ? 'look' : ($s->status === 'applied' ? 'ok' : 'info')">{{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$s->status] ?? $s->status }}</x-ta.pill>
                                            @endif
                                        </p>
                                        @if ($card['sentence'])<p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300" data-row-sentence>{{ $card['sentence'] }}</p>@endif
                                        @if ($card['note'])<p class="mt-0.5 text-[13px] text-gray-500" data-row-note>{{ $card['note'] }}</p>@endif
                                        @if ($card['outcome'])<p class="mt-0.5 text-[13px] text-success-700 dark:text-success-400" data-outcome-line>{{ $card['outcome'] }}</p>@endif
                                        @if ($card['where'])<p class="mt-1 min-w-0 break-all text-xs text-gray-500">{{ $card['where'] }}</p>@endif
                                    </div>
                                    <div class="flex w-full shrink-0 flex-wrap items-center gap-1 sm:w-auto sm:justify-end">
                                        @if ($card['button'])
                                            @if ($card['button']['call'] === '')
                                                <span class="px-2 text-[13px] text-gray-500">{{ $card['button']['label'] }}</span>
                                            @else
                                                <x-ta.button :variant="$card['button']['confirm'] !== null ? 'dark' : 'outline'" size="sm" wire:click="{{ $card['button']['call'] }}" :wire:confirm="$card['button']['confirm']">{{ $card['button']['label'] }}</x-ta.button>
                                            @endif
                                        @endif
                                        @if ($actionable)
                                            <x-ta.button variant="ghost" size="sm" x-show="! rejecting" x-on:click="rejecting = true">Gerek yok</x-ta.button>
                                        @endif
                                        <x-ta.button variant="ghost" size="sm" wire:click="open({{ $s->id }})">{{ $opened?->id === $s->id ? 'Kapat' : 'Neden?' }}</x-ta.button>
                                    </div>
                                </div>
                                @if ($actionable)
                                    <div x-show="rejecting" x-cloak class="mt-3 flex flex-wrap items-center gap-1.5" data-dismiss-reasons>
                                        @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::DISMISS_REASONS as $preset)
                                            <button type="button" wire:click="$set('reasons.{{ $s->id }}', '{{ $preset }}')" class="{{ $small }}">{{ $preset }}</button>
                                        @endforeach
                                        <input type="text" wire:model="reasons.{{ $s->id }}" placeholder="ya da kendin yaz" aria-label="Neden gerek yok" class="h-7 min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-2.5 text-xs sm:max-w-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                        <x-ta.button variant="outline" size="xs" wire:click="dismiss({{ $s->id }})">Kapat</x-ta.button>
                                        <x-ta.button variant="ghost" size="xs" x-on:click="rejecting = false">Vazgeç</x-ta.button>
                                    </div>
                                @endif

                                @if ($opened?->id === $s->id)
                                    <div class="mt-3 min-w-0 space-y-3 break-words border-t border-dashed border-gray-200 pt-3 text-[13px] text-gray-700 dark:border-gray-700 dark:text-gray-300" data-detail>
                                        @if ((array) $s->evidence !== [])
                                            <p class="text-gray-500">Neye bakıldı:
                                                @foreach ((array) $s->evidence as $item){{ ($item['source'] ?? '') !== '' ? $item['source'].': ' : '' }}{{ \Illuminate\Support\Str::limit((string) ($item['value'] ?? ''), 90) }}@if (! $loop->last); @endif @endforeach
                                            </p>
                                        @endif
                                        @if (in_array($s->status, ['approved', 'applied'], true))
                                            <span class="flex items-center gap-1">
                                                <x-ta.button variant="outline" size="xs" wire:click="proposeStandard({{ $s->id }})">Bu karardan standart öner</x-ta.button>
                                                <x-operator.ai-prompt-info operation="site.standard_from_decision" />
                                            </span>
                                        @endif
                                        @if ($applyStatus)<p class="text-gray-500">Düzeltme hazırlığı: {{ $applyStatus }}</p>@endif
                                        @if ($s->action_type === \App\Services\Site\ImageAlts::TYPE)
                                            <ul class="space-y-0.5" data-image-alt-list>
                                                @foreach ((array) data_get($s->action, 'images', []) as $image)<li><span class="text-gray-500">{{ $image['file'] }}</span>: «{{ $image['alt'] }}»</li>@endforeach
                                            </ul>
                                        @endif
                                        @if (data_get($s->action, 'proposal_blocked'))<p class="text-error-600 dark:text-error-400">{{ str_starts_with((string) data_get($s->action, 'proposal_blocked'), 'Kopya') ? '' : 'Uyum kuralına takıldı: ' }}{{ data_get($s->action, 'proposal_blocked') }}</p>@endif
                                        @if (data_get($s->action, 'proposal_warnings'))<p class="text-warning-700 dark:text-warning-400" data-proposal-warnings>{{ data_get($s->action, 'proposal_warnings') }}</p>@endif

                                        @if ($proposal !== [])
                                            <div class="space-y-3" data-proposal>
                                                @foreach ($fieldLabels as $field => $label)
                                                    @if (isset($proposal['new'][$field]))
                                                        <div class="grid gap-2 sm:grid-cols-2">
                                                            <div><p class="text-xs text-gray-500">{{ $label }}, şimdiki</p><p class="mt-0.5 rounded-md bg-error-50/70 px-2 py-1 dark:bg-error-500/10">{{ $proposal['current'][$field] ?? '—' }}</p></div>
                                                            <div><p class="text-xs text-gray-500">{{ $label }}, yeni</p><p class="mt-0.5 rounded-md bg-success-50/70 px-2 py-1 dark:bg-success-500/10">{{ $proposal['new'][$field] }}</p></div>
                                                        </div>
                                                    @endif
                                                @endforeach
                                                @if (! empty($proposal['new']['internal_links']))
                                                    <div>
                                                        <p class="text-xs text-gray-500">Yeni iç bağlantılar</p>
                                                        <ul class="mt-0.5 space-y-0.5">@foreach ($proposal['new']['internal_links'] as $link)<li>«{{ $link['anchor'] }}» bağlantısı {{ $link['url'] }} adresine</li>@endforeach</ul>
                                                    </div>
                                                @endif
                                                @if (! empty($proposal['new']['schema_json']))
                                                    <div>
                                                        <p class="text-xs text-gray-500">Yapılandırılmış veri</p>
                                                        <pre class="mt-0.5 overflow-x-auto rounded-md bg-white p-2 text-xs ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">{{ $proposal['new']['schema_json'] }}</pre>
                                                    </div>
                                                @endif
                                                @if ($diff !== null)
                                                    <div class="grid gap-3 sm:grid-cols-2" data-diff>
                                                        <div><p class="text-xs text-gray-500">Şimdiki</p>@foreach ($diff['left'] as $block)<p @class(['my-1', 'bg-error-50 line-through dark:bg-error-500/10' => $block['changed']])>{{ $block['text'] }}</p>@endforeach</div>
                                                        <div><p class="text-xs text-gray-500">Yeni</p>@foreach ($diff['right'] as $block)<p @class(['my-1', 'bg-success-50 dark:bg-success-500/10' => $block['changed']])>{{ $block['text'] }}</p>@endforeach</div>
                                                    </div>
                                                @endif
                                                @if (! empty($proposal['note']))<p class="text-gray-500">{{ $proposal['note'] }}</p>@endif
                                                @if ($s->applied_at === null)
                                                    <button type="button" wire:click="applyChange({{ $s->id }})" wire:confirm="Hazırlanan düzeltme siteye gönderilsin mi? Geri alınabilir." class="btn-dark w-full sm:w-auto">Siteye gönder</button>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($writes->isNotEmpty())
                                            <ul class="space-y-1" data-writes>
                                                @foreach ($writes as $write)
                                                    <li class="flex flex-wrap items-center gap-2">
                                                        <span>{{ \App\Services\Repair\RepairDesk::WRITE_LABELS[$write->action] ?? 'Site değişikliği' }}, {{ \App\Livewire\Operator\Website\V2\SuggestionsTab::WRITE_STATUS_LABELS[$write->status] ?? $write->status }}</span>
                                                        @if ($write->action === \App\Models\ExternalWriteAction::ACTION_CONTENT_DRAFT && $write->status === 'succeeded' && $s->status !== 'applied')
                                                            <button type="button" wire:click="publishDraft({{ $s->id }})" wire:confirm="Taslaktaki yeni metin canlıya alınsın mı? Geri alınabilir." class="btn-dark h-7! px-2.5! text-xs!">Canlıya al</button>
                                                        @endif
                                                        @if ($write->isUndoable())<button type="button" wire:click="undo({{ $write->id }})" class="{{ $small }}">Geri al</button>@endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if ($s->baseline)
                                            <p class="text-gray-500">Gönderildiği gün, son 28 gün: {{ data_get($s->baseline, 'clicks_28d') ?? '—' }} tıklama, {{ data_get($s->baseline, 'impressions_28d') ?? '—' }} gösterim, sıra {{ data_get($s->baseline, 'position_28d') ?? '—' }}</p>
                                        @endif

                                        @if ($standardStatus)<p class="text-gray-500">Standart önerisi: {{ $standardStatus }}</p>@endif
                                        @if (data_get($s->action, 'standard_id'))
                                            <p class="text-success-700 dark:text-success-400">Standart kütüphanede.</p>
                                        @elseif ($standard !== [])
                                            <div class="grid gap-2 sm:grid-cols-2" data-standard-form>
                                                <input type="text" wire:model="standard.title" aria-label="Başlık" placeholder="Başlık" class="{{ $input }}">
                                                <select wire:model="standard.scope" aria-label="Kapsam" class="{{ $select }} h-9!">
                                                    @foreach (\App\Services\Site\ScopedStandards::SCOPE_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                                </select>
                                                <textarea wire:model="standard.rule" aria-label="Kural" placeholder="Kural" rows="2" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm sm:col-span-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white"></textarea>
                                                <input type="text" wire:model="standard.condition" aria-label="Koşul" placeholder="Koşul" class="{{ $input }}">
                                                <input type="text" wire:model="standard.exceptions" aria-label="İstisnalar" placeholder="İstisnalar" class="{{ $input }}">
                                                <button type="button" wire:click="saveStandard({{ $s->id }})" class="btn-dark sm:col-span-2">Standardı onayla</button>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @empty
                <x-operator.cluster-readiness :asset-id="$this->assetId" what="Öneriler" class="border-b border-gray-200 px-4 py-3.5 dark:border-gray-800" />
                <x-ta.empty-state icon="none" title="Öneri yok" message="Öneriler İçerik fikirleri'nde «Eşleştir» ve «SEO analizi» ile oluşur." />
            @endforelse
            @if ($later !== [] && $type === '')
                <div class="border-t border-gray-200 dark:border-gray-800" data-suggestion-groups-later>
                    @foreach ($later as $groupType => $group)
                        <button type="button" wire:click="$set('type', '{{ $groupType }}')" class="flex w-full items-center gap-3 border-t border-gray-200 px-4 py-3.5 text-left first:border-t-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.02]" data-suggestion-group="{{ $groupType }}">
                            <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m7.5 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span class="min-w-0 flex-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $group['label'] }}</span>
                            <span class="shrink-0 text-[13px] tabular-nums text-gray-500">{{ $num($group['count']) }} iş</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
        @if ($suggestions->hasPages())<div class="pt-3">{{ $suggestions->links() }}</div>@endif
    </section>
</div>
