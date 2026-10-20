@php
    $a = (array) $s->action;
    $sharedNegative = $s->action_type === 'ads_negative' && ($a['scope'] ?? '') === 'shared';
    $editable = in_array($s->action_type, ['ads_rsa', 'ads_negative', 'ads_campaign'], true);
    $quiet = 'btn-quiet h-7! px-2! text-xs!';
    /** Row approval: near-black (canlı panel), the page's accent stays on its one main action. */
    $approveClass = 'btn-dark h-7! px-2.5! text-xs!';
@endphp
<div class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="sug-{{ $s->id }}">
    <div class="flex flex-wrap items-start gap-x-3.5 gap-y-2 sm:flex-nowrap">
        @if ($sharedNegative && $canWrite)
            <input type="checkbox" wire:model.live="selectedNegatives" value="{{ $s->id }}" aria-label="Seç" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 dark:border-gray-700">
        @endif
        <div class="min-w-0 flex-1">
            <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}@if ($a['locked'] ?? false) <span class="ml-1 text-xs font-normal text-gray-500">Düzenlendi</span>@endif</p>
            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $s->reason }}</p>
            @if (in_array($s->action_type, ['ads_check', 'ads_advice'], true) && filled($a['todo'] ?? null))
                <p class="mt-0.5 text-xs text-gray-500">@if (filled($a['impact'] ?? null)){{ $a['impact'] }}. @endif Yapılacak (Google Ads'te elle): {{ $a['todo'] }}</p>
            @elseif ($s->action_type === 'ads_negative')
                <p class="mt-0.5 text-xs text-gray-500">{{ $matchLabel((string) $a['match_type']) }} eşleme, {{ $scopeLabel($a) }}, {{ $money($a['cost'] ?? null) }} {{ $cur }}</p>
                <p class="text-xs text-gray-500">Engelleyebileceği faydalı sorgu: {{ ($a['blocks'] ?? []) === [] ? 'yok' : implode(', ', array_slice($a['blocks'], 0, 5)) }}</p>
            @endif
        </div>
        <div class="flex w-full shrink-0 flex-wrap items-center gap-1 sm:w-auto sm:justify-end">
            @if ($sharedNegative && $canWrite)
                <button type="button" x-on:click="if (confirm('Negatif Google Ads paylaşılan listesine eklensin mi? Sonradan geri alınabilir.')) $wire.approveSuggestion({{ $s->id }})" class="{{ $approveClass }}">Gönder</button>
            @else
                <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="{{ $approveClass }}">Onayla</button>
            @endif
            @if ($editable)
                <button type="button" wire:click="startEdit({{ $s->id }})" class="{{ $quiet }}">Düzenle</button>
            @endif
            <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="{{ $quiet }}">Reddet</button>
            <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="{{ $quiet }}">Ertele</button>
        </div>
    </div>

    @if (is_array($a['proposal'] ?? null))
        <p class="mt-1.5 text-xs text-warning-700 dark:text-warning-300">AI değişiklik önerisi var. <button type="button" wire:click="acceptProposal({{ $s->id }})" class="font-medium underline underline-offset-2">AI önerisini uygula</button></p>
    @endif

    @if ($editingId === $s->id)
        <div class="mt-3 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-white/[0.02]">
            @if ($s->action_type === 'ads_rsa')
                <label class="block text-xs text-gray-500">Başlıklar (satır başına bir, en çok 30 karakter)<textarea wire:model="edit.headlines" rows="6" class="{{ $input }} mt-1 h-auto! w-full py-2"></textarea></label>
                @error('edit.headlines')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                <label class="block text-xs text-gray-500">Açıklamalar (satır başına bir, en çok 90 karakter)<textarea wire:model="edit.descriptions" rows="4" class="{{ $input }} mt-1 h-auto! w-full py-2"></textarea></label>
                @error('edit.descriptions')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-2">
                    <input type="url" wire:model="edit.final_url" aria-label="Son URL" placeholder="Son URL" class="{{ $input }} min-w-0 flex-1">
                    <input type="text" wire:model="edit.path1" aria-label="Yol 1" placeholder="yol 1" class="{{ $input }} w-28">
                    <input type="text" wire:model="edit.path2" aria-label="Yol 2" placeholder="yol 2" class="{{ $input }} w-28">
                </div>
                @error('edit.final_url')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
            @elseif ($s->action_type === 'ads_negative')
                <div class="flex flex-wrap gap-2">
                    <input type="text" wire:model="edit.text" aria-label="Negatif" class="{{ $input }} min-w-0 flex-1">
                    <select wire:model="edit.match_type" aria-label="Eşleme" class="{{ $input }}"><option value="EXACT">Tam</option><option value="PHRASE">Sıralı</option><option value="BROAD">Geniş</option></select>
                    <select wire:model.live="edit.scope" aria-label="Kapsam" class="{{ $input }}"><option value="shared">Paylaşılan liste</option><option value="campaign">Kampanya</option><option value="ad_group">Reklam grubu</option></select>
                </div>
                @if (($edit['scope'] ?? 'shared') !== 'shared')
                    <div class="flex flex-wrap gap-2">
                        <input type="text" wire:model="edit.campaign" aria-label="Kampanya" placeholder="Kampanya" class="{{ $input }} min-w-0 flex-1">
                        @if (($edit['scope'] ?? '') === 'ad_group')<input type="text" wire:model="edit.ad_group" aria-label="Reklam grubu" placeholder="Reklam grubu" class="{{ $input }} min-w-0 flex-1">@endif
                    </div>
                @endif
                @foreach (['edit.text', 'edit.campaign', 'edit.ad_group'] as $field)
                    @error($field)<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                @endforeach
            @else
                <div class="flex flex-wrap gap-2">
                    <input type="text" wire:model="edit.name" aria-label="Kampanya adı" class="{{ $input }} min-w-0 flex-1">
                    <input type="number" step="0.01" wire:model="edit.daily_budget" aria-label="Günlük bütçe" class="{{ $input }} w-32">
                </div>
                @foreach (['edit.name', 'edit.daily_budget'] as $field)
                    @error($field)<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                @endforeach
            @endif
            <div class="flex gap-2">
                <button type="button" wire:click="saveEdit" class="btn-primary h-7! px-2.5! text-xs!">Kaydet</button>
                <button type="button" wire:click="cancelEdit" class="{{ $quiet }}">Vazgeç</button>
            </div>
        </div>
    @elseif ($s->action_type === 'ads_campaign')
        <div class="mt-2.5 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <p>Hizmet: {{ $a['service'] ?? '—' }}, günlük bütçe: {{ $money($a['daily_budget'] ?? null) }} {{ $cur }}</p>
            @foreach ((array) ($a['ad_groups'] ?? []) as $group)
                <p><span class="font-medium text-gray-800 dark:text-gray-200">{{ $group['name'] }}</span>: {{ $group['landing_url'] ?: 'URL yok' }}<br>
                    {{ collect($group['keywords'])->map(fn ($k) => $k['text'].' ['.$matchLabel($k['match_type']).']')->implode(', ') }}</p>
            @endforeach
        </div>
    @elseif ($s->action_type === 'ads_rsa')
        <div class="mt-2.5 grid gap-3 text-xs md:grid-cols-2">
            <div>
                <p class="text-gray-500">Başlıklar</p>
                @foreach ((array) $a['headlines'] as $headline)
                    <p class="text-gray-800 dark:text-gray-200">{{ $headline }} <span class="text-gray-500">{{ mb_strlen($headline) }}/30</span></p>
                @endforeach
            </div>
            <div>
                <p class="text-gray-500">Açıklamalar</p>
                @foreach ((array) $a['descriptions'] as $description)
                    <p class="text-gray-800 dark:text-gray-200">{{ $description }} <span class="text-gray-500">{{ mb_strlen($description) }}/90</span></p>
                @endforeach
                <p class="mt-1 break-all text-gray-500">URL: {{ $a['final_url'] }}@if (filled($a['path1'] ?? null)), /{{ $a['path1'] }}@endif @if (filled($a['path2'] ?? null))/{{ $a['path2'] }}@endif</p>
            </div>
        </div>
    @endif

    @if (($s->evidence ?? []) !== [])
        <details class="mt-1.5 text-xs text-gray-500">
            <summary class="cursor-pointer hover:text-gray-900 dark:hover:text-white">Kanıt</summary>
            @foreach ($s->evidence as $row)
                <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last), @endif @endforeach</p>
            @endforeach
        </details>
    @endif
</div>
