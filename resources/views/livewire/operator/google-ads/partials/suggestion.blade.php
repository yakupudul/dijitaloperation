@php
    $a = (array) $s->action;
    $sharedNegative = $s->action_type === 'ads_negative' && ($a['scope'] ?? '') === 'shared';
    $editable = in_array($s->action_type, ['ads_rsa', 'ads_negative', 'ads_campaign'], true);
@endphp
<div class="px-4 py-3" wire:key="sug-{{ $s->id }}">
    <div class="flex flex-wrap items-start gap-2">
        @if ($sharedNegative && $canWrite)
            <input type="checkbox" wire:model.live="selectedNegatives" value="{{ $s->id }}" aria-label="Seç" class="mt-1 rounded border-gray-300">
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $s->title }}@if ($a['locked'] ?? false) <span class="ml-1 rounded-full bg-gray-100 px-1.5 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">Düzenlendi</span>@endif</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $s->reason }}</p>
            @if ($s->action_type === 'ads_check' && filled($a['todo'] ?? null))
                <p class="mt-0.5 text-xs text-gray-500">Yapılacak: {{ $a['todo'] }}</p>
            @elseif ($s->action_type === 'ads_negative')
                <p class="mt-0.5 text-xs text-gray-500">{{ $matchLabel((string) $a['match_type']) }} eşleme · {{ $scopeLabel($a) }} · {{ $money($a['cost'] ?? null) }} {{ $cur }}
                    · Engelleyebileceği faydalı sorgu: {{ ($a['blocks'] ?? []) === [] ? 'yok' : implode(', ', array_slice($a['blocks'], 0, 5)) }}</p>
            @endif
        </div>
        <div class="flex shrink-0 gap-1.5">
            @if ($sharedNegative && $canWrite)
                <button type="button" x-on:click="if (confirm('Negatif Google Ads paylaşılan listesine eklensin mi? Sonradan geri alınabilir.')) $wire.approveSuggestion({{ $s->id }})" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Gönder</button>
            @else
                <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Onayla</button>
            @endif
            @if ($editable)
                <button type="button" wire:click="startEdit({{ $s->id }})" class="{{ $small }}">Düzenle</button>
            @endif
            <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="{{ $small }}">Reddet</button>
            <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="{{ $small }}">Ertele</button>
        </div>
    </div>

    @if (is_array($a['proposal'] ?? null))
        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">AI değişiklik önerisi var · <button type="button" wire:click="acceptProposal({{ $s->id }})" class="font-semibold hover:underline">AI önerisini uygula</button></p>
    @endif

    @if ($editingId === $s->id)
        <div class="mt-2 space-y-2 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">
            @if ($s->action_type === 'ads_rsa')
                <label class="block text-xs text-gray-500">Başlıklar (satır başına bir, en çok 30 karakter)<textarea wire:model="edit.headlines" rows="6" class="{{ $input }} mt-1 w-full"></textarea></label>
                @error('edit.headlines')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <label class="block text-xs text-gray-500">Açıklamalar (satır başına bir, en çok 90 karakter)<textarea wire:model="edit.descriptions" rows="4" class="{{ $input }} mt-1 w-full"></textarea></label>
                @error('edit.descriptions')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-2">
                    <input type="url" wire:model="edit.final_url" aria-label="Son URL" placeholder="Son URL" class="{{ $input }} min-w-0 flex-1">
                    <input type="text" wire:model="edit.path1" aria-label="Yol 1" placeholder="yol 1" class="{{ $input }} w-28">
                    <input type="text" wire:model="edit.path2" aria-label="Yol 2" placeholder="yol 2" class="{{ $input }} w-28">
                </div>
                @error('edit.final_url')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
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
                    @error($field)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                @endforeach
            @else
                <div class="flex flex-wrap gap-2">
                    <input type="text" wire:model="edit.name" aria-label="Kampanya adı" class="{{ $input }} min-w-0 flex-1">
                    <input type="number" step="0.01" wire:model="edit.daily_budget" aria-label="Günlük bütçe" class="{{ $input }} w-32">
                </div>
                @foreach (['edit.name', 'edit.daily_budget'] as $field)
                    @error($field)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                @endforeach
            @endif
            <div class="flex gap-2">
                <button type="button" wire:click="saveEdit" class="rounded bg-brand-500 px-2 py-1 text-xs font-semibold text-white hover:bg-brand-600">Kaydet</button>
                <button type="button" wire:click="cancelEdit" class="{{ $small }}">Vazgeç</button>
            </div>
        </div>
    @elseif ($s->action_type === 'ads_campaign')
        <div class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <p>Hizmet: {{ $a['service'] ?? '—' }} · Günlük bütçe: {{ $money($a['daily_budget'] ?? null) }} {{ $cur }}</p>
            @foreach ((array) ($a['ad_groups'] ?? []) as $group)
                <p><span class="font-semibold text-gray-800 dark:text-gray-200">{{ $group['name'] }}</span> → {{ $group['landing_url'] ?: 'URL yok' }}<br>
                    {{ collect($group['keywords'])->map(fn ($k) => $k['text'].' ['.$matchLabel($k['match_type']).']')->implode(', ') }}</p>
            @endforeach
        </div>
    @elseif ($s->action_type === 'ads_rsa')
        <div class="mt-2 grid gap-2 text-xs md:grid-cols-2">
            <div>
                <p class="font-semibold text-gray-500">Başlıklar</p>
                @foreach ((array) $a['headlines'] as $headline)
                    <p class="text-gray-800 dark:text-gray-200">{{ $headline }} <span class="text-gray-400">{{ mb_strlen($headline) }}/30</span></p>
                @endforeach
            </div>
            <div>
                <p class="font-semibold text-gray-500">Açıklamalar</p>
                @foreach ((array) $a['descriptions'] as $description)
                    <p class="text-gray-800 dark:text-gray-200">{{ $description }} <span class="text-gray-400">{{ mb_strlen($description) }}/90</span></p>
                @endforeach
                <p class="mt-1 text-gray-500">URL: {{ $a['final_url'] }}@if (filled($a['path1'] ?? null)) · /{{ $a['path1'] }}@endif @if (filled($a['path2'] ?? null))/{{ $a['path2'] }}@endif</p>
            </div>
        </div>
    @endif

    @if (($s->evidence ?? []) !== [])
        <details class="mt-1 text-xs text-gray-500">
            <summary class="cursor-pointer">Kanıt</summary>
            @foreach ($s->evidence as $row)
                <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last) · @endif @endforeach</p>
            @endforeach
        </details>
    @endif
</div>
