@php
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $types = collect($groups)->map(fn ($group) => $group['label'])->all();
    $fieldLabels = ['seo_title' => 'SEO başlığı', 'meta_description' => 'Meta açıklama'];
@endphp
<div class="space-y-10" data-suggestions @if ($applyStatus === 'çalışıyor…' || $standardStatus === 'çalışıyor…' || collect($cards)->contains(fn ($card) => str_ends_with((string) ($card['button']['label'] ?? ''), '…'))) wire:poll.10s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-gray-50 p-2 text-gray-800 dark:bg-gray-950 dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif
    @if ($errors->any())<p class="text-xs text-error-600">{{ $errors->first() }}</p>@endif

    <section class="flex flex-wrap items-center gap-2" data-filters>
        <select wire:model.live="pageFilter" aria-label="URL" class="{{ $input }} w-full min-w-0 sm:w-auto sm:max-w-xs">
            <option value="">Tüm URL’ler</option>
            @foreach ($pageOptions as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
        </select>
        <select wire:model.live="type" aria-label="Tür" class="{{ $input }} min-w-0 flex-1 sm:flex-none">
            <option value="">Tüm işler</option>
            @foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="status" aria-label="Durum" class="{{ $input }} min-w-0 flex-1 sm:flex-none">
            <option value="">Tüm durumlar</option>
            @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <span class="text-xs tabular-nums text-gray-500">{{ number_format($suggestions->total(), 0, ',', '.') }} iş · en çok getirecek olan üstte</span>
        <span class="flex flex-wrap items-center gap-1 sm:ml-auto" data-image-alts>
            @if ($altStatus)<span class="text-xs text-gray-500">Resim açıklamaları: {{ $altStatus }}</span>@endif
            <button type="button" wire:click="proposeAlts" class="{{ $ghost }}">Resim açıklamalarını hazırla</button>
            <x-operator.ai-prompt-info operation="site.image_alts" />
        </span>
    </section>

    <section class="border-t border-gray-200 dark:border-gray-800" data-section="suggestions">
        @php $pageGroups = $suggestions->getCollection()->groupBy(fn ($row) => (string) ($row->group ?? $row->action_type)); $firstGroup = $pageGroups->keys()->first(); @endphp
        @forelse ($pageGroups as $groupType => $rows)
            <details class="group/sg border-b border-gray-200 dark:border-gray-800" data-suggestion-group="{{ $groupType }}" @if ($groupType === $firstGroup || $openId !== null && $rows->contains('id', $openId)) open @endif>
                <summary class="flex cursor-pointer list-none items-center gap-2 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                    <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition group-open/sg:rotate-90" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m7.5 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="min-w-0 flex-1 break-words font-medium text-gray-900 dark:text-white">{{ $groups[$groupType]['label'] ?? \App\Services\Site\SiteSuggestionTypes::label($groupType) }}</span>
                    <span class="shrink-0 text-xs tabular-nums text-gray-500" data-group-count>{{ number_format($groups[$groupType]['count'] ?? $rows->count(), 0, ',', '.') }}</span>
                </summary>
                @if (($groups[$groupType]['ready'] ?? 0) > 1)
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 py-2 pl-5 text-sm dark:border-gray-800" data-group-send="{{ $groupType }}">
                        <span class="min-w-0 flex-1 text-gray-600 dark:text-gray-300">{{ $groupType === \App\Services\Site\ClusterOverlaps::TYPE ? $groups[$groupType]['ready'].' birleştirme hazır' : $groups[$groupType]['ready'].' iş hazır' }}, hepsi tek seferde gider, geri alınabilir.</span>
                        <x-ta.button variant="outline" size="xs" wire:click="sendGroup('{{ $groupType }}')" wire:confirm="Bu gruptaki hazır {{ $groups[$groupType]['ready'] }} iş siteye gönderilsin mi? Geri alınabilir.">{{ $groupType === \App\Services\Site\ClusterOverlaps::TYPE ? 'Hepsini birleştir' : 'Hepsini gönder' }} ({{ $groups[$groupType]['ready'] }})</x-ta.button>
                    </div>
                @endif
        <ul class="divide-y divide-gray-200 border-t border-gray-200 pl-5 text-sm dark:divide-gray-800 dark:border-gray-800">
            @foreach ($rows as $s)
                @php $actionable = in_array($s->status, ['open', 'recheck'], true); @endphp
                <li class="py-2.5" wire:key="sg-{{ $s->id }}" data-suggestion="{{ $s->id }}" x-data="{ rejecting: false }">
                    @php $card = $cards[$s->id] ?? ['heading' => $s->title, 'sentence' => $s->reason, 'where' => $s->page?->path, 'button' => null, 'note' => null, 'outcome' => null]; @endphp
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-medium text-gray-900 dark:text-white" data-row-heading>{{ $card['heading'] }}</span>
                                @if ($s->status === 'recheck' || $status !== 'open')
                                    <x-ta.pill :tone="$s->status === 'recheck' ? 'look' : ($s->status === 'applied' ? 'ok' : 'info')">{{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$s->status] ?? $s->status }}</x-ta.pill>
                                @endif
                            </p>
                            @if ($card['sentence'])<p class="text-gray-700 dark:text-gray-300" data-row-sentence>{{ $card['sentence'] }}</p>@endif
                            @if ($card['note'])<p class="text-gray-500" data-row-note>{{ $card['note'] }}</p>@endif
                            @if ($card['outcome'])<p class="text-success-700 dark:text-success-300" data-outcome-line>{{ $card['outcome'] }}</p>@endif
                            @if ($card['where'])<p class="min-w-0 break-all text-xs text-gray-500">{{ $card['where'] }}</p>@endif
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                            @if ($card['button'])
                                @if ($card['button']['call'] === '')
                                    <span class="px-2 text-gray-500">{{ $card['button']['label'] }}</span>
                                @else
                                    <x-ta.button variant="outline" size="xs" wire:click="{{ $card['button']['call'] }}" :wire:confirm="$card['button']['confirm']">{{ $card['button']['label'] }}</x-ta.button>
                                @endif
                            @endif
                            @if ($actionable)
                                <x-ta.button variant="ghost" size="xs" x-show="! rejecting" x-on:click="rejecting = true">Gerek yok</x-ta.button>
                            @endif
                            <x-ta.button variant="ghost" size="xs" wire:click="open({{ $s->id }})">{{ $opened?->id === $s->id ? 'Kapat' : 'Neden?' }}</x-ta.button>
                        </div>
                    </div>
                    @if ($actionable)
                        <div x-show="rejecting" x-cloak class="mt-2 flex flex-wrap items-center gap-1.5" data-dismiss-reasons>
                            @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::DISMISS_REASONS as $preset)
                                <button type="button" wire:click="$set('reasons.{{ $s->id }}', '{{ $preset }}')" class="{{ $ghost }}">{{ $preset }}</button>
                            @endforeach
                            <input type="text" wire:model="reasons.{{ $s->id }}" placeholder="ya da kendin yaz" aria-label="Neden gerek yok" class="{{ $input }} min-w-0 flex-1 sm:max-w-xs">
                            <x-ta.button variant="outline" size="xs" wire:click="dismiss({{ $s->id }})">Kapat</x-ta.button>
                            <x-ta.button variant="ghost" size="xs" x-on:click="rejecting = false">Vazgeç</x-ta.button>
                        </div>
                    @endif

                    @if ($opened?->id === $s->id)
                        <div class="mt-2 min-w-0 space-y-3 break-words surface p-3 text-xs" data-detail>
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
                                <ul data-image-alt-list>
                                    @foreach ((array) data_get($s->action, 'images', []) as $image)<li><span class="text-gray-500">{{ $image['file'] }}</span> → «{{ $image['alt'] }}»</li>@endforeach
                                </ul>
                            @endif
                            @if (data_get($s->action, 'proposal_blocked'))<p class="text-error-600">{{ str_starts_with((string) data_get($s->action, 'proposal_blocked'), 'Kopya') ? '' : 'Uyum kuralına takıldı: ' }}{{ data_get($s->action, 'proposal_blocked') }}</p>@endif
                            @if (data_get($s->action, 'proposal_warnings'))<p class="text-warning-700" data-proposal-warnings>{{ data_get($s->action, 'proposal_warnings') }}</p>@endif

                            @if ($proposal !== [])
                                <div data-proposal>
                                    @foreach ($fieldLabels as $field => $label)
                                        @if (isset($proposal['new'][$field]))
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                <div><p class="font-semibold text-gray-500">{{ $label }} (mevcut)</p><p class="rounded bg-error-50 p-1 dark:bg-error-500/10">{{ $proposal['current'][$field] ?? '—' }}</p></div>
                                                <div><p class="font-semibold text-gray-500">{{ $label }} (yeni)</p><p class="rounded bg-success-50 p-1 dark:bg-success-500/10">{{ $proposal['new'][$field] }}</p></div>
                                            </div>
                                        @endif
                                    @endforeach
                                    @if (! empty($proposal['new']['internal_links']))
                                        <p class="mt-2 font-semibold text-gray-500">Yeni iç bağlantılar</p>
                                        <ul>@foreach ($proposal['new']['internal_links'] as $link)<li>«{{ $link['anchor'] }}» → {{ $link['url'] }}</li>@endforeach</ul>
                                    @endif
                                    @if (! empty($proposal['new']['schema_json']))
                                        <p class="mt-2 font-semibold text-gray-500">Yapılandırılmış veri</p>
                                        <pre class="overflow-x-auto rounded bg-white p-2 text-xs dark:bg-gray-900">{{ $proposal['new']['schema_json'] }}</pre>
                                    @endif
                                    @if ($diff !== null)
                                        <div class="mt-2 grid gap-2 sm:grid-cols-2" data-diff>
                                            <div><p class="font-semibold text-gray-500">Mevcut</p>@foreach ($diff['left'] as $block)<p @class(['my-1', 'bg-error-50 line-through dark:bg-error-500/10' => $block['changed']])>{{ $block['text'] }}</p>@endforeach</div>
                                            <div><p class="font-semibold text-gray-500">Yeni</p>@foreach ($diff['right'] as $block)<p @class(['my-1', 'bg-success-50 dark:bg-success-500/10' => $block['changed']])>{{ $block['text'] }}</p>@endforeach</div>
                                        </div>
                                    @endif
                                    @if (! empty($proposal['note']))<p class="mt-1 text-gray-500">{{ $proposal['note'] }}</p>@endif
                                    @if ($s->applied_at === null)
                                        <button type="button" wire:click="applyChange({{ $s->id }})" wire:confirm="Hazırlanan düzeltme siteye gönderilsin mi? Geri alınabilir." class="{{ $btn }} mt-2">Siteye gönder</button>
                                    @endif
                                </div>
                            @endif

                            @if ($writes->isNotEmpty())
                                <ul data-writes>
                                    @foreach ($writes as $write)
                                        <li class="flex items-center gap-2">
                                            <span>{{ \App\Services\Repair\RepairDesk::WRITE_LABELS[$write->action] ?? 'Site değişikliği' }}, {{ \App\Livewire\Operator\Website\V2\SuggestionsTab::WRITE_STATUS_LABELS[$write->status] ?? $write->status }}</span>
                                            @if ($write->action === \App\Models\ExternalWriteAction::ACTION_CONTENT_DRAFT && $write->status === 'succeeded' && $s->status !== 'applied')
                                                <button type="button" wire:click="publishDraft({{ $s->id }})" wire:confirm="Taslaktaki yeni metin canlıya alınsın mı? Geri alınabilir." class="{{ $btn }}">Canlıya al</button>
                                            @endif
                                            @if ($write->isUndoable())<button type="button" wire:click="undo({{ $write->id }})" class="{{ $ghost }}">Geri al</button>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($s->baseline)
                                <p class="text-gray-500">Gönderildiği gün (son 28 gün): {{ data_get($s->baseline, 'clicks_28d') ?? '—' }} tıklama, {{ data_get($s->baseline, 'impressions_28d') ?? '—' }} gösterim, sıra {{ data_get($s->baseline, 'position_28d') ?? '—' }}</p>
                            @endif

                            @if ($standardStatus)<p class="text-gray-500">Standart önerisi: {{ $standardStatus }}</p>@endif
                            @if (data_get($s->action, 'standard_id'))
                                <p class="text-success-700">Standart kütüphanede.</p>
                            @elseif ($standard !== [])
                                <div class="grid gap-2 sm:grid-cols-2" data-standard-form>
                                    <input type="text" wire:model="standard.title" aria-label="Başlık" placeholder="Başlık" class="{{ $input }}">
                                    <select wire:model="standard.scope" aria-label="Kapsam" class="{{ $input }}">
                                        @foreach (\App\Services\Site\ScopedStandards::SCOPE_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                    </select>
                                    <textarea wire:model="standard.rule" aria-label="Kural" placeholder="Kural" rows="2" class="{{ $input }} sm:col-span-2"></textarea>
                                    <input type="text" wire:model="standard.condition" aria-label="Koşul" placeholder="Koşul" class="{{ $input }}">
                                    <input type="text" wire:model="standard.exceptions" aria-label="İstisnalar" placeholder="İstisnalar" class="{{ $input }}">
                                    <button type="button" wire:click="saveStandard({{ $s->id }})" class="{{ $btn }} sm:col-span-2">Standardı onayla</button>
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
            </details>
        @empty
            <div class="space-y-2">
                <x-operator.cluster-readiness :asset-id="$this->assetId" what="Öneriler" />
                <x-ta.empty-state size="sm" title="Öneri yok" message="Öneriler Sorgular › İçerik fikirleri'nde «Eşleştir» ve «SEO analizi» ile oluşur." />
            </div>
        @endforelse
        @php $later = array_diff_key($groups, $pageGroups->all()); @endphp
        @if ($later !== [] && $type === '')
            <div class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-suggestion-groups-later>
                @foreach ($later as $groupType => $group)
                    <button type="button" wire:click="$set('type', '{{ $groupType }}')" class="flex w-full items-center gap-2 py-2.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-suggestion-group="{{ $groupType }}">
                        <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m7.5 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="min-w-0 flex-1 break-words font-medium text-gray-700 dark:text-gray-200">{{ $group['label'] }}</span>
                        <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ number_format($group['count'], 0, ',', '.') }}</span>
                    </button>
                @endforeach
            </div>
        @endif
        <div class="pt-3">{{ $suggestions->links() }}</div>
    </section>
</div>
