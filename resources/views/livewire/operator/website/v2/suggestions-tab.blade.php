@php
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $types = \App\Services\Site\SiteSuggestionTypes::ANALYSIS;
    $fieldLabels = ['seo_title' => 'SEO başlığı', 'meta_description' => 'Meta açıklama'];
@endphp
<div class="space-y-4" data-suggestions @if ($applyStatus === 'çalışıyor…' || $standardStatus === 'çalışıyor…') wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-gray-50 p-2 text-gray-800 dark:bg-gray-950 dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif
    @if ($errors->any())<p class="text-xs text-error-600">{{ $errors->first() }}</p>@endif

    <section class="surface flex flex-wrap items-center gap-2 p-3" data-filters>
        <select wire:model.live="pageFilter" aria-label="URL" class="{{ $input }} w-full min-w-0 sm:w-auto sm:max-w-xs">
            <option value="">Tüm URL’ler</option>
            @foreach ($pageOptions as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
        </select>
        <select wire:model.live="type" aria-label="Tür" class="{{ $input }} min-w-0 flex-1 sm:flex-none">
            <option value="">Tüm türler</option>
            @foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="status" aria-label="Durum" class="{{ $input }} min-w-0 flex-1 sm:flex-none">
            <option value="">Tüm durumlar</option>
            @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <span class="text-xs tabular-nums text-gray-500">{{ number_format($suggestions->total(), 0, ',', '.') }} öneri</span>
        <span class="flex flex-wrap items-center gap-1 sm:ml-auto" data-image-alts>
            @if ($altStatus)<span class="text-xs text-gray-500">Görsel alt metni: {{ $altStatus }}</span>@endif
            <button type="button" wire:click="proposeAlts" class="{{ $ghost }}">Görsel alt metni öner</button>
            <x-operator.ai-prompt-info operation="site.image_alts" />
        </span>
    </section>

    <section class="space-y-3" data-section="suggestions">
        @php $pageGroups = $suggestions->getCollection()->groupBy(fn ($row) => (string) $row->action_type); $firstGroup = $pageGroups->keys()->first(); @endphp
        @forelse ($pageGroups as $groupType => $rows)
            <details class="surface group/sg" data-suggestion-group="{{ $groupType }}" @if ($groupType === $firstGroup || $openId !== null && $rows->contains('id', $openId)) open @endif>
                <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-2.5 text-sm">
                    <span class="text-xs text-gray-400 transition group-open/sg:rotate-90" aria-hidden="true">▸</span>
                    <span class="min-w-0 flex-1 break-words font-medium text-gray-900 dark:text-white">{{ $groups[$groupType]['label'] ?? \App\Services\Site\SiteSuggestionTypes::label($groupType) }}</span>
                    <span class="shrink-0 text-xs tabular-nums text-gray-500" data-group-count>{{ number_format($groups[$groupType]['count'] ?? $rows->count(), 0, ',', '.') }}</span>
                </summary>
        <ul class="divide-y divide-gray-100 border-t border-gray-100 px-4 text-xs dark:divide-gray-800 dark:border-gray-800">
            @foreach ($rows as $s)
                @php $outcome = $s->status === 'applied' ? \App\Services\Outcomes\OutcomeTracker::latest($s) : null; $actionable = in_array($s->status, ['open', 'recheck'], true); @endphp
                <li class="py-2" wire:key="sg-{{ $s->id }}" data-suggestion="{{ $s->id }}" x-data="{ rejecting: false }">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5" title="{{ $s->reason }}">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $s->title }}</span>
                            @if ($s->page?->path)<span class="min-w-0 break-all text-gray-500">{{ $s->page->path }}</span>@endif
                            @if ($s->status === 'recheck' || $status !== 'open')
                                <x-ta.pill :tone="$s->status === 'recheck' ? 'look' : ($s->status === 'applied' ? 'ok' : 'info')">{{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$s->status] ?? $s->status }}</x-ta.pill>
                            @endif
                            @if ($outcome !== null)
                                <span title="{{ $outcome['reason'] }}">@include('livewire.demo.partials.outcome-badge', ['verdict' => $outcome['verdict']])</span>
                                <span class="text-gray-500" data-outcome-line>{{ $outcome['line'] }}</span>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                            @if ($s->action_type === \App\Services\Site\ImageAlts::TYPE && $actionable)
                                <x-ta.button variant="outline" size="xs" wire:click="applyAlts({{ $s->id }})" wire:confirm="Alt metinler WordPress’e yazılsın mı? (geri alınabilir)">Onayla ve WordPress’e gönder</x-ta.button>
                            @endif
                            @if ($actionable && $s->action_type !== \App\Services\Site\ImageAlts::TYPE)
                                <x-ta.button variant="outline" size="xs" wire:click="approve({{ $s->id }})">Onayla</x-ta.button>
                            @endif
                            @if (\App\Services\Site\SiteSuggestionTypes::applicable((string) $s->action_type) && in_array($s->status, ['open', 'approved', 'recheck'], true) && $s->applied_at === null)
                                <x-ta.button variant="ghost" size="xs" wire:click="aiDo({{ $s->id }})">AI ile yap</x-ta.button>
                                <x-operator.ai-prompt-info operation="site.apply_change" />
                            @endif
                            @if ($actionable)
                                <x-ta.button variant="ghost" size="xs" x-show="! rejecting" x-on:click="rejecting = true">Reddet</x-ta.button>
                            @endif
                            <x-ta.button variant="ghost" size="xs" wire:click="open({{ $s->id }})">{{ $opened?->id === $s->id ? 'Kapat' : 'Aç' }}</x-ta.button>
                        </div>
                    </div>
                    @if ($actionable)
                        <div x-show="rejecting" x-cloak class="mt-2 flex flex-wrap items-center gap-1.5">
                            <input type="text" wire:model="reasons.{{ $s->id }}" placeholder="Neden" aria-label="Reddetme nedeni" class="{{ $input }} min-w-0 flex-1 sm:max-w-xs">
                            <x-ta.button variant="outline" size="xs" wire:click="dismiss({{ $s->id }})">Reddet</x-ta.button>
                            <x-ta.button variant="ghost" size="xs" x-on:click="rejecting = false">Vazgeç</x-ta.button>
                        </div>
                    @endif

                    @if ($opened?->id === $s->id)
                        <div class="mt-2 min-w-0 space-y-3 break-words rounded-lg bg-gray-50 p-3 dark:bg-gray-950" data-detail>
                            @if ($s->reason)<p class="text-gray-700 dark:text-gray-300">{{ $s->reason }}</p>@endif
                            @if ((array) $s->evidence !== [])
                                <p class="text-gray-500">
                                    @foreach ((array) $s->evidence as $item){{ ($item['source'] ?? '') !== '' ? $item['source'].': ' : '' }}{{ \Illuminate\Support\Str::limit((string) ($item['value'] ?? ''), 90) }}@if (! $loop->last) · @endif @endforeach
                                </p>
                            @endif
                            @if (in_array($s->status, ['approved', 'applied'], true))
                                <span class="flex items-center gap-1">
                                    <x-ta.button variant="outline" size="xs" wire:click="proposeStandard({{ $s->id }})">Bu karardan standart öner</x-ta.button>
                                    <x-operator.ai-prompt-info operation="site.standard_from_decision" />
                                </span>
                            @endif
                            @if ($applyStatus)<p class="text-gray-500">AI ile yap: {{ $applyStatus }}</p>@endif
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
                                                <div><p class="font-semibold text-gray-500">{{ $label }} · mevcut</p><p class="rounded bg-error-50 p-1 dark:bg-error-500/10">{{ $proposal['current'][$field] ?? '—' }}</p></div>
                                                <div><p class="font-semibold text-gray-500">{{ $label }} · yeni</p><p class="rounded bg-success-50 p-1 dark:bg-success-500/10">{{ $proposal['new'][$field] }}</p></div>
                                            </div>
                                        @endif
                                    @endforeach
                                    @if (! empty($proposal['new']['internal_links']))
                                        <p class="mt-2 font-semibold text-gray-500">Yeni iç bağlantılar</p>
                                        <ul>@foreach ($proposal['new']['internal_links'] as $link)<li>«{{ $link['anchor'] }}» → {{ $link['url'] }}</li>@endforeach</ul>
                                    @endif
                                    @if (! empty($proposal['new']['schema_json']))
                                        <p class="mt-2 font-semibold text-gray-500">Yapılandırılmış veri</p>
                                        <pre class="overflow-x-auto rounded bg-white p-2 text-[11px] dark:bg-gray-900">{{ $proposal['new']['schema_json'] }}</pre>
                                    @endif
                                    @if ($diff !== null)
                                        <div class="mt-2 grid gap-2 sm:grid-cols-2" data-diff>
                                            <div><p class="font-semibold text-gray-500">Mevcut</p>@foreach ($diff['left'] as $block)<p @class(['my-1', 'bg-error-50 line-through dark:bg-error-500/10' => $block['changed']])>{{ $block['text'] }}</p>@endforeach</div>
                                            <div><p class="font-semibold text-gray-500">Yeni</p>@foreach ($diff['right'] as $block)<p @class(['my-1', 'bg-success-50 dark:bg-success-500/10' => $block['changed']])>{{ $block['text'] }}</p>@endforeach</div>
                                        </div>
                                    @endif
                                    @if (! empty($proposal['note']))<p class="mt-1 text-gray-500">{{ $proposal['note'] }}</p>@endif
                                    @if ($s->applied_at === null)
                                        <button type="button" wire:click="applyChange({{ $s->id }})" wire:confirm="Yeni sürüm WordPress’e gönderilsin mi? (geri alınabilir)" class="{{ $btn }} mt-2">Onayla ve WordPress’e gönder</button>
                                    @endif
                                </div>
                            @endif

                            @if ($writes->isNotEmpty())
                                <ul data-writes>
                                    @foreach ($writes as $write)
                                        <li class="flex items-center gap-2">
                                            <span>{{ $write->action }} · {{ $write->status }}</span>
                                            @if ($write->action === \App\Models\ExternalWriteAction::ACTION_CONTENT_DRAFT && $write->status === 'succeeded' && $s->status !== 'applied')
                                                <button type="button" wire:click="publishDraft({{ $s->id }})" class="{{ $btn }}">Canlıya al</button>
                                            @endif
                                            @if ($write->isUndoable())<button type="button" wire:click="undo({{ $write->id }})" class="{{ $ghost }}">Geri al</button>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($s->baseline)
                                <p class="text-gray-500">Başlangıç (28g): {{ data_get($s->baseline, 'clicks_28d') ?? '—' }} tık · {{ data_get($s->baseline, 'impressions_28d') ?? '—' }} gösterim · poz. {{ data_get($s->baseline, 'position_28d') ?? '—' }}</p>
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
            <div class="surface divide-y divide-gray-100 dark:divide-gray-800" data-suggestion-groups-later>
                @foreach ($later as $groupType => $group)
                    <button type="button" wire:click="$set('type', '{{ $groupType }}')" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-suggestion-group="{{ $groupType }}">
                        <span class="text-xs text-gray-400" aria-hidden="true">▸</span>
                        <span class="min-w-0 flex-1 break-words font-medium text-gray-700 dark:text-gray-200">{{ $group['label'] }}</span>
                        <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ number_format($group['count'], 0, ',', '.') }}</span>
                    </button>
                @endforeach
            </div>
        @endif
        <div>{{ $suggestions->links() }}</div>
    </section>
</div>
