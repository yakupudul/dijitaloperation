@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $types = \App\Services\Site\SiteSuggestionTypes::ANALYSIS;
    $fieldLabels = ['seo_title' => 'SEO başlığı', 'meta_description' => 'Meta açıklama'];
@endphp
<div class="space-y-4" data-suggestions @if ($applyStatus === 'çalışıyor…' || $standardStatus === 'çalışıyor…') wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif
    @if ($errors->any())<p class="text-xs text-rose-600">{{ $errors->first() }}</p>@endif

    <section class="{{ $card }} flex flex-wrap items-center gap-2" data-filters>
        <select wire:model.live="pageFilter" aria-label="URL" class="{{ $input }} max-w-xs">
            <option value="">Tüm URL’ler</option>
            @foreach ($pageOptions as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
        </select>
        <select wire:model.live="type" aria-label="Tür" class="{{ $input }}">
            <option value="">Tüm türler</option>
            @foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="status" aria-label="Durum" class="{{ $input }}">
            <option value="">Tüm durumlar</option>
            @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <span class="text-xs text-gray-500">{{ $suggestions->total() }} öneri</span>
    </section>

    <section class="{{ $card }}" data-section="suggestions">
        <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
            @forelse ($suggestions as $s)
                <li class="py-2" wire:key="sg-{{ $s->id }}" data-suggestion="{{ $s->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $s->title }}
                                <span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600 dark:bg-gray-800">{{ $types[$s->action_type] ?? $s->action_type }}</span>
                                <span class="{{ $chip }} ml-1 {{ $s->status === 'recheck' ? 'bg-amber-50 text-amber-700' : 'bg-gray-50 text-gray-500' }}">{{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$s->status] ?? $s->status }}</span>
                                @if ($s->status === 'applied' && ($outcome = \App\Services\Outcomes\OutcomeTracker::latest($s)) !== null)
                                    <span title="{{ $outcome['reason'] }}">@include('livewire.demo.partials.outcome-badge', ['verdict' => $outcome['verdict']])</span>
                                @endif
                            </p>
                            <p class="text-gray-500">{{ $s->page?->path }} · {{ $s->reason }}</p>
                            <p class="text-gray-400">
                                @foreach ((array) $s->evidence as $item){{ ($item['source'] ?? '') !== '' ? $item['source'].': ' : '' }}{{ \Illuminate\Support\Str::limit((string) ($item['value'] ?? ''), 90) }}@if (! $loop->last) · @endif @endforeach
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                            @if (in_array($s->status, ['open', 'recheck'], true))
                                <button type="button" wire:click="approve({{ $s->id }})" class="{{ $ghost }}">Onayla</button>
                                <input type="text" wire:model="reasons.{{ $s->id }}" placeholder="Neden" aria-label="Reddetme nedeni" class="{{ $input }} w-28">
                                <button type="button" wire:click="dismiss({{ $s->id }})" class="{{ $ghost }}">Reddet</button>
                            @endif
                            @if (\App\Services\Site\SiteSuggestionTypes::applicable((string) $s->action_type) && in_array($s->status, ['open', 'approved', 'recheck'], true) && $s->applied_at === null)
                                <button type="button" wire:click="aiDo({{ $s->id }})" class="{{ $btn }}">AI ile yap</button>
                                <x-operator.ai-prompt-info operation="site.apply_change" />
                            @endif
                            @if (in_array($s->status, ['approved', 'applied'], true))
                                <button type="button" wire:click="proposeStandard({{ $s->id }})" class="{{ $ghost }}">Bu karardan standart öner</button>
                                <x-operator.ai-prompt-info operation="site.standard_from_decision" />
                            @endif
                            <button type="button" wire:click="open({{ $s->id }})" class="{{ $ghost }}">{{ $opened?->id === $s->id ? 'Kapat' : 'Aç' }}</button>
                        </div>
                    </div>

                    @if ($opened?->id === $s->id)
                        <div class="mt-2 space-y-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-950" data-detail>
                            @if ($applyStatus)<p class="text-gray-500">AI ile yap: {{ $applyStatus }}</p>@endif
                            @if (data_get($s->action, 'proposal_blocked'))<p class="text-rose-600">Uyum kuralına takıldı: {{ data_get($s->action, 'proposal_blocked') }}</p>@endif

                            @if ($proposal !== [])
                                <div data-proposal>
                                    @foreach ($fieldLabels as $field => $label)
                                        @if (isset($proposal['new'][$field]))
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                <div><p class="font-semibold text-gray-500">{{ $label }} · mevcut</p><p class="rounded bg-rose-50 p-1 dark:bg-rose-500/10">{{ $proposal['current'][$field] ?? '—' }}</p></div>
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
                                            <div><p class="font-semibold text-gray-500">Mevcut</p>@foreach ($diff['left'] as $block)<p @class(['my-1', 'bg-rose-50 line-through dark:bg-rose-500/10' => $block['changed']])>{{ $block['text'] }}</p>@endforeach</div>
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
            @empty
                <li class="py-3 text-gray-500">Öneri yok · Kümeler & Sayfalar’da "Analiz et".</li>
            @endforelse
        </ul>
        <div class="mt-2">{{ $suggestions->links() }}</div>
    </section>
</div>
