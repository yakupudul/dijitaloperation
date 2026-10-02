@php
    $model = $r['model'];
    $key = $model !== null ? $r['kind'].'-'.$model->id : 'idea-'.$r['idea']->id;
    [$actKind, $actId] = $model !== null ? [$r['kind'], (int) $model->id] : ['idea', (int) $r['idea']->id];
    $page = $model?->page;
    $score = $r['score'];
    $gaps = (array) ($model?->gaps ?? []);
    $recipe = (array) ($model?->recipe ?? []);
    $improveSuggestion = $model !== null ? ($suggestions[$r['kind'].'-'.$model->id.':missing_topic'] ?? null) : null;
    $contentSuggestion = $model !== null ? ($suggestions[$r['kind'].'-'.$model->id.':content'] ?? null) : null;
    $title = $extra ? $r['idea']->title : $cluster->name;
    $type = $extra ? $r['idea']->type : $cluster->page_type;
    $questions = ! $extra && $brand !== null ? \App\Services\Site\ClusterAudit::aiQuestions($cluster, $brand) : [];
    $rowStatuses = collect($statuses)->filter(fn ($line, $k) => str_starts_with($k, $key.':'));
    $rowOverlaps = ! $extra && $model !== null ? ($overlaps[(int) $model->id] ?? collect()) : collect();
    $audited = $model !== null && ($model->rediscovered_at !== null || ($model->audited_at ?? null) !== null);
@endphp
<tr wire:key="row-{{ $key }}" data-idea-row="{{ $key }}" data-state="{{ $r['state'] }}" @class(['align-top', 'text-gray-700 dark:text-gray-300' => $extra])>
    <td @class(['py-1.5', 'pl-6' => $extra])>
        <span @class(['font-semibold' => ! $extra])>{{ $extra ? '↳ ' : '' }}{{ $title }}</span>
        @if (! $extra && $model->language)<span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600">{{ $model->language }}</span>@endif
        @if ($model?->locked)<span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600">elle</span>@endif
        @if ($extra)
            <p class="text-gray-500">{{ $r['idea']->angle }} · üreten: {{ $r['idea']->originBrand?->name ?? 'Sorgular (genel)' }}</p>
        @else
            <p class="text-gray-500">{{ $cluster->service?->primaryName?->raw_label }}</p>
        @endif
    </td>
    <td>{{ $types[$type] ?? \App\Models\Cluster::PAGE_TYPE_LABELS[$type] ?? $type }}</td>
    <td class="text-right tabular-nums">{{ $demand !== null ? $num($demand) : '' }}</td>
    <td class="max-w-[14rem] truncate">
        @if ($page)<a href="{{ $page->url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ $page->path ?: '/' }}</a>@else<span class="text-gray-400">—</span>@endif
    </td>
    <td class="text-right tabular-nums">
        @if ($score?->score !== null){{ $score->score }}@if ($score->previous_score !== null && $score->previous_score != $score->score) <span class="text-gray-400">{{ $score->score > $score->previous_score ? '↑' : '↓' }}</span>@endif
        @elseif ($score)<span class="text-gray-400">{{ ['low_data' => 'veri az', 'no_gsc' => 'GSC yok', 'no_gsc_data' => 'veri yok', 'no_page' => '—'][$score->state] ?? '—' }}</span>
        @else<span class="text-gray-400">—</span>@endif
    </td>
    <td class="max-w-[22rem]">
        <span class="{{ $chip }} {{ $tone[$r['state']] ?? '' }}" data-idea-state>{{ \App\Services\Site\ContentIdeaState::LABELS[$r['state']] ?? $r['state'] }}</span>
        <p class="mt-0.5 text-gray-500">{{ $r['reason'] }}</p>
        @if (! $extra && $r['state'] === 'no_page')
            {{-- Eşleşmeyen küme: the content idea comes from the cluster itself (need, page type, sections) — nothing invented. --}}
            <div class="mt-1 rounded-lg bg-blue-50 p-2 text-blue-900 dark:bg-blue-950 dark:text-blue-100" data-content-idea>
                <p><span class="font-semibold">İçerik önerisi:</span> yeni {{ \App\Models\Cluster::PAGE_TYPE_LABELS[$cluster->page_type] ?? 'içerik' }} sayfası · «{{ $cluster->name }}»@if ($cluster->user_need) — {{ $cluster->user_need }}@endif</p>
                @if ((array) $cluster->subtopics !== [])<p class="mt-0.5">Bölümler: {{ implode(' · ', (array) $cluster->subtopics) }}</p>@endif
                @if ($cluster->mainQuery)<p class="mt-0.5 text-blue-700 dark:text-blue-300">Hedef sorgu: «{{ $cluster->mainQuery->text }}»</p>@endif
            </div>
        @endif
        @if ($rowOverlaps->isNotEmpty())
            <div class="mt-1 rounded-lg bg-amber-50 p-2 text-amber-900 dark:bg-amber-500/10 dark:text-amber-100" data-overlaps>
                <p class="font-semibold">Bu kümeyle eşleşen diğer sayfalar ({{ $rowOverlaps->count() }})</p>
                @foreach ($rowOverlaps as $overlap)
                    <div class="mt-1 flex flex-wrap items-center gap-2" wire:key="overlap-{{ $overlap->id }}" data-overlap="{{ $overlap->id }}">
                        <a href="{{ data_get($overlap->action, 'overlap_url') }}" target="_blank" rel="noopener" class="font-medium underline">{{ parse_url((string) data_get($overlap->action, 'overlap_url'), PHP_URL_PATH) ?: '/' }}</a>
                        <span class="text-amber-800 dark:text-amber-200">{{ $overlap->reason }}</span>
                        @if (in_array($overlap->status, ['open', 'recheck'], true))
                            @if (data_get($overlap->action, 'recommendation') === \App\Services\Site\ClusterOverlaps::REDIRECT)
                                <button type="button" wire:click="mergeOverlap({{ $overlap->id }})" wire:confirm="Sayfa ana sayfaya 301 ile yönlendirilsin mi? (WordPress, geri alınabilir)" class="{{ $btn }}" data-merge-overlap>301 ile birleştir</button>
                            @endif
                            <button type="button" wire:click="keepOverlap({{ $overlap->id }})" class="{{ $ghost }}" data-keep-overlap>Ayrı kalsın</button>
                        @else
                            <span class="{{ $chip }} bg-gray-100 text-gray-600">{{ $overlap->status === 'dismissed' ? 'ayrı kalıyor' : 'yönlendirildi' }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if ($gaps !== [] || $questions !== [])
            <details class="mt-1" data-gaps>
                <summary class="cursor-pointer font-medium text-brand-600">Eksikler ({{ count($gaps) }})</summary>
                <ul class="mt-1 list-disc space-y-0.5 pl-4">@foreach ($gaps as $gap)<li>{{ $gap['text'] ?? '' }} <span class="text-gray-400">· {{ $kinds[$gap['kind'] ?? ''] ?? '' }}</span></li>@endforeach</ul>
                @if ($questions !== [])<p class="mt-1 font-medium text-gray-600">AI asistanına sorulanlar</p><ul class="list-disc pl-4 text-gray-500">@foreach ($questions as $question)<li>{{ $question }}</li>@endforeach</ul>@endif
            </details>
        @endif
        @foreach ($rowStatuses as $k => $line)
            <p class="text-gray-500">{{ \App\Services\Site\SiteOperations::LABELS[\Illuminate\Support\Str::after($k, ':')] ?? '' }}: {{ $line }}</p>
        @endforeach
    </td>
    <td class="text-right">
        <div class="flex flex-wrap justify-end gap-1">
            @if ($r['state'] !== 'excluded')
                <button type="button" wire:click="rediscover('{{ $actKind }}', {{ $actId }})" class="{{ $ghost }}" data-rediscover>Yeniden keşfet</button>
                <button type="button" wire:click="recipe('{{ $actKind }}', {{ $actId }})" class="{{ $ghost }}" data-recipe>SEO analizi</button>
            @endif
            @if ($recipe !== [])<button type="button" wire:click="toggle('{{ $key }}:recipe')" class="{{ $ghost }}">Reçete</button>@endif
            @if ($r['state'] === 'improve' && $page?->wp_post_id !== null)
                <button type="button" wire:click="improve('{{ $r['kind'] }}', {{ $model->id }})" class="{{ $btn }}" data-improve>AI ile geliştir</button>
            @endif
            @if ($improveSuggestion !== null && data_get($improveSuggestion->action, 'proposal') && $improveSuggestion->applied_at === null)
                <a href="{{ route('operator.website', ['assetId' => $assetId, 'tab' => 'ozet', 'sub' => 'oneriler', 'oneri' => $improveSuggestion->id]) }}" wire:navigate class="{{ $btn }}" data-preview>Önizle ve güncelle</a>
            @elseif ($improveSuggestion !== null && data_get($improveSuggestion->action, 'proposal_blocked'))
                <span class="text-rose-600">{{ \Illuminate\Support\Str::limit((string) data_get($improveSuggestion->action, 'proposal_blocked'), 140) }}</span>
            @endif
            @if ($r['state'] === 'no_page' && $audited)
                <button type="button" wire:click="produce('{{ $r['kind'] }}', {{ $model->id }})" class="{{ $btn }}" data-produce>AI ile üret</button>
            @endif
            @if ($contentSuggestion !== null)
                <a href="{{ route('operator.website', ['assetId' => $assetId, 'tab' => 'ozet', 'sub' => 'icerik']) }}" wire:navigate class="{{ $ghost }}" data-content-link>İçerik sekmesinde</a>
            @endif
            @if ($model !== null)<button type="button" wire:click="toggle('{{ $key }}:edit')" class="{{ $ghost }}">{{ $extra ? 'Sayfa seç' : 'Düzenle' }}</button>@endif
        </div>
    </td>
</tr>
@if ($open === $key.':recipe' && $recipe !== [])
    <tr wire:key="recipe-{{ $key }}"><td colspan="7" class="bg-gray-50 p-3 dark:bg-gray-950" data-recipe-panel>
        @if (! empty($recipe['summary']))<p class="font-semibold">{{ $recipe['summary'] }}</p>@endif
        <ol class="mt-1 list-decimal space-y-1 pl-5">
            @foreach ((array) ($recipe['steps'] ?? []) as $step)
                <li><span class="font-medium">{{ \App\Ai\Agents\Site\ContentRecipeAgent::AREA_LABELS[$step['area']] ?? $step['area'] }} · {{ $step['where'] }}</span> — {{ $step['action'] }} <span class="text-gray-500">Neden: {{ $step['why'] }}</span></li>
            @endforeach
        </ol>
        @if (! empty($recipe['seo_title']))<p class="mt-1">SEO başlığı: {{ $recipe['seo_title'] }}</p>@endif
        @if (! empty($recipe['meta_description']))<p>Meta açıklama: {{ $recipe['meta_description'] }}</p>@endif
        @if (! empty($recipe['expected_effect']))<p class="mt-1 text-gray-500">Beklenen etki: {{ $recipe['expected_effect'] }} · ölçüm {{ $recipe['measure_after_days'] ?? 56 }} gün sonra</p>@endif
        <x-operator.ai-prompt-info operation="site.content_recipe" />
    </td></tr>
@endif
@if ($open === $key.':edit' && $model !== null)
    <tr wire:key="edit-{{ $key }}"><td colspan="7" class="bg-gray-50 p-3 dark:bg-gray-950" data-edit-panel>
        <div class="flex flex-wrap items-end gap-2">
            <input type="search" wire:model.live.debounce.400ms="pageSearch" placeholder="URL ara" aria-label="URL ara" class="{{ $input }} w-40">
            @if ($extra)
                <select wire:model="ideaPage.{{ $model->id }}" aria-label="Sayfa" class="{{ $input }} max-w-[16rem]">
                    <option value="">— sayfa yok</option>
                    @foreach ($pageOptions as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
                </select>
                <button type="button" wire:click="saveIdea({{ $model->id }})" class="{{ $btn }}">Kaydet</button>
            @else
                <label class="space-y-0.5"><span class="block text-gray-500">Hedef URL</span>
                    <select wire:model="edit.{{ $model->id }}.page" aria-label="Hedef URL" class="{{ $input }} max-w-[16rem]">
                        <option value="">— sayfa yok</option>
                        @foreach ($pageOptions as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
                    </select></label>
                <label class="space-y-0.5"><span class="block text-gray-500">Ek URL</span>
                    <select multiple wire:model="edit.{{ $model->id }}.extra" aria-label="Ek URL" class="{{ $input }} h-14 max-w-[16rem]">
                        @foreach ($pageOptions as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
                    </select></label>
                <label class="space-y-0.5"><span class="block text-gray-500">Hedef sorgu</span>
                    <input type="text" wire:model="edit.{{ $model->id }}.target" placeholder="{{ $model->target_query }}" aria-label="Hedef sorgu" class="{{ $input }} w-48"></label>
                <label class="text-gray-500"><input type="checkbox" wire:model="edit.{{ $model->id }}.excluded"> bu markada hariç</label>
                <button type="button" wire:click="saveMain({{ $model->id }})" class="{{ $btn }}">Kaydet</button>
            @endif
        </div>
    </td></tr>
@endif
