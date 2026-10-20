@php
    $input = 'w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700 dark:text-white';
    $kindLabels = ['forbidden_phrase' => 'Yasaklı ifade', 'required_phrase' => 'Zorunlu ifade', 'targeting' => 'Hedefleme'];
@endphp
<div class="space-y-10">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-900 dark:hover:text-white">Ayarlar</a>
        <h1 class="mt-1 text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Kurallar AI taslaklarında (anında), yayındaki Meta reklamlarında, site sayfalarında ve İşletme Profili içeriğinde her gün aranır.">Sektör paketleri</h1>
        <p class="mt-1 text-sm text-gray-500">Bir paket, sektörü eşleşen markalara uygulanır.</p>
    </div>

    @if ($message !== '')
        <p class="tone-ok rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif

    @foreach ($packs as $pack)
        <section wire:key="pack-{{ $pack['id'] }}" class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="section-title">{{ $pack['label'] }} paketi</h2>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $pack['description'] }}</p>
                    <p class="mt-1 text-xs text-gray-500">Sektörler: {{ implode(', ', $pack['sectors']) }}</p>
                </div>
                @if ($isAdmin)
                    <button type="button" wire:click="togglePack('{{ $pack['id'] }}')" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-200 dark:text-white dark:ring-gray-700"><span @class(['h-1.5 w-1.5 rounded-full', 'bg-success-500' => $pack['enabled'], 'bg-gray-300' => ! $pack['enabled']])></span>{{ $pack['enabled'] ? 'Açık' : 'Kapalı' }}</button>
                @else
                    <span class="text-sm">{{ $pack['enabled'] ? 'Açık' : 'Kapalı' }}</span>
                @endif
            </div>
            <div class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($pack['rules'] as $rule)
                    <div wire:key="rule-{{ $rule->id }}" @class(['py-3', 'opacity-50' => ! $rule->active])>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $rule->label }} <span class="text-xs font-normal text-gray-500">{{ $kindLabels[$rule->kind] ?? $rule->kind }}, {{ $rule->severity }} · {{ collect($rule->applies_to ?? [])->map(fn ($s) => $sources[$s] ?? $s)->implode(', ') ?: 'tüm kaynaklar' }}{{ $rule->origin === 'operator' ? ', elle düzenlendi' : '' }}</span></p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ implode(', ', (array) $rule->patterns) }}</p>
                                <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $rule->message }}</p>
                            </div>
                            @if ($isAdmin)
                                <div class="flex gap-2 text-xs">
                                    <button type="button" wire:click="edit({{ $rule->id }})" class="rounded px-2 py-1 ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Düzenle</button>
                                    <button type="button" wire:click="toggleRule({{ $rule->id }})" class="rounded px-2 py-1 ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ $rule->active ? 'Kapat' : 'Aç' }}</button>
                                </div>
                            @endif
                        </div>
                        @if ($editingId === $rule->id)
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <label class="text-xs text-gray-500">İfadeler (satır başına bir)<textarea wire:model="editPatterns" rows="4" class="{{ $input }} mt-1"></textarea></label>
                                <label class="text-xs text-gray-500">Öneri metni<textarea wire:model="editMessage" rows="4" class="{{ $input }} mt-1"></textarea></label>
                                <label class="text-xs text-gray-500">Önem
                                    <select wire:model="editSeverity" class="{{ $input }} mt-1"><option value="high">Yüksek</option><option value="medium">Orta</option><option value="low">Düşük</option></select>
                                </label>
                                <div class="flex items-end gap-2">
                                    <button type="button" wire:click="save" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Kaydet</button>
                                    <button type="button" wire:click="$set('editingId', null)" class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300">Vazgeç</button>
                                </div>
                                @error('editPatterns')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($isAdmin && $packs !== [])
        <section class="space-y-2">
            <h2 class="section-title">Yeni yasaklı ifade kuralı</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <select wire:model="newPack" class="{{ $input }}"><option value="">Paket seç…</option>@foreach ($packs as $pack)<option value="{{ $pack['id'] }}">{{ $pack['label'] }}</option>@endforeach</select>
                <input type="text" wire:model="newLabel" placeholder="Kural adı" class="{{ $input }}" />
                <textarea wire:model="newPatterns" rows="3" placeholder="İfadeler (satır başına bir)" class="{{ $input }}"></textarea>
                <textarea wire:model="newMessage" rows="3" placeholder="Operatöre öneri" class="{{ $input }}"></textarea>
            </div>
            @error('newPack')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
            <button type="button" wire:click="addRule" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Ekle</button>
        </section>
    @endif
</div>
