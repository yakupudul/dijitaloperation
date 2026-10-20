@php
    $input = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $kindLabels = ['forbidden_phrase' => 'Yasaklı ifade', 'required_phrase' => 'Zorunlu ifade', 'targeting' => 'Hedefleme'];
    $severityLabels = ['high' => 'yüksek önem', 'medium' => 'orta önem', 'low' => 'düşük önem'];
    $rowBtn = 'btn h-7! px-2.5! text-xs!';
@endphp
<div class="space-y-10">
    <header>
        <h1 class="page-title" title="Kurallar AI taslaklarında (anında), yayındaki Meta reklamlarında, site sayfalarında ve İşletme Profili içeriğinde her gün aranır.">Sektör paketleri</h1>
        <p class="page-sub">Bir paket, sektörü eşleşen markalara uygulanır.</p>
    </header>

    @if ($message !== '')
        <p class="tone-ok -mt-4 rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif

    @foreach ($packs as $pack)
        <section wire:key="pack-{{ $pack['id'] }}">
            <div class="section-head items-center">
                <h2 class="section-title">{{ $pack['label'] }} paketi <span class="section-count">{{ count($pack['rules']) }}</span></h2>
                @if ($isAdmin)
                    <button type="button" wire:click="togglePack('{{ $pack['id'] }}')" aria-pressed="{{ $pack['enabled'] ? 'true' : 'false' }}" class="btn ml-auto"><span @class(['h-1.5 w-1.5 rounded-full', 'bg-success-500' => $pack['enabled'], 'bg-gray-300 dark:bg-gray-600' => ! $pack['enabled']]) aria-hidden="true"></span>{{ $pack['enabled'] ? 'Açık' : 'Kapalı' }}</button>
                @else
                    <x-ta.pill :tone="$pack['enabled'] ? 'ok' : 'info'" class="ml-auto">{{ $pack['enabled'] ? 'Açık' : 'Kapalı' }}</x-ta.pill>
                @endif
            </div>
            <p class="-mt-1 mb-3 text-[13px] text-gray-600 dark:text-gray-400">{{ $pack['description'] }} <span class="text-gray-500">Sektörler: {{ implode(', ', $pack['sectors']) }}</span></p>
            <ul class="card">
                @foreach ($pack['rules'] as $rule)
                    <li wire:key="rule-{{ $rule->id }}" class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800">
                        <div @class(['flex flex-wrap items-start gap-3 sm:flex-nowrap', 'opacity-50' => ! $rule->active])>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $rule->label }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $kindLabels[$rule->kind] ?? $rule->kind }}, {{ $severityLabels[$rule->severity] ?? $rule->severity }}, {{ collect($rule->applies_to ?? [])->map(fn ($s) => $sources[$s] ?? $s)->implode(', ') ?: 'tüm kaynaklar' }}{{ $rule->origin === 'operator' ? ', elle düzenlendi' : '' }}</p>
                                <p class="mt-1 text-[13px] text-gray-700 dark:text-gray-300">{{ implode(', ', (array) $rule->patterns) }}</p>
                                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $rule->message }}</p>
                            </div>
                            @if ($isAdmin)
                                <div class="flex shrink-0 gap-2">
                                    <button type="button" wire:click="edit({{ $rule->id }})" class="{{ $rowBtn }}">Düzenle</button>
                                    <button type="button" wire:click="toggleRule({{ $rule->id }})" class="{{ $rowBtn }}">{{ $rule->active ? 'Kapat' : 'Aç' }}</button>
                                </div>
                            @endif
                        </div>
                        @if ($editingId === $rule->id)
                            <div class="mt-3 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 dark:bg-white/[0.03]">
                                <label class="text-[13px] text-gray-600 dark:text-gray-400">İfadeler (satır başına bir)<textarea wire:model="editPatterns" rows="4" class="{{ $input }} mt-1"></textarea></label>
                                <label class="text-[13px] text-gray-600 dark:text-gray-400">Öneri metni<textarea wire:model="editMessage" rows="4" class="{{ $input }} mt-1"></textarea></label>
                                <label class="text-[13px] text-gray-600 dark:text-gray-400">Önem
                                    <select wire:model="editSeverity" class="{{ $input }} mt-1 h-9 py-0"><option value="high">Yüksek</option><option value="medium">Orta</option><option value="low">Düşük</option></select>
                                </label>
                                <div class="flex items-end justify-end gap-2">
                                    <button type="button" wire:click="$set('editingId', null)" class="btn">Vazgeç</button>
                                    <button type="button" wire:click="save" class="btn-primary">Kaydet</button>
                                </div>
                                @error('editPatterns')<p class="text-xs text-error-600 sm:col-span-2">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($isAdmin && $packs !== [])
        <section>
            <div class="section-head"><h2 class="section-title">Yeni yasaklı ifade kuralı</h2></div>
            <div class="card">
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    <select wire:model="newPack" aria-label="Paket" class="{{ $input }} h-9 py-0"><option value="">Paket seç…</option>@foreach ($packs as $pack)<option value="{{ $pack['id'] }}">{{ $pack['label'] }}</option>@endforeach</select>
                    <input type="text" wire:model="newLabel" placeholder="Kural adı" aria-label="Kural adı" class="{{ $input }} h-9 py-0" />
                    <textarea wire:model="newPatterns" rows="3" placeholder="İfadeler (satır başına bir)" aria-label="İfadeler" class="{{ $input }}"></textarea>
                    <textarea wire:model="newMessage" rows="3" placeholder="Operatöre öneri" aria-label="Operatöre öneri" class="{{ $input }}"></textarea>
                    @error('newPack')<p class="text-xs text-error-600 sm:col-span-2">{{ $message }}</p>@enderror
                </div>
                <div class="flex justify-end border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                    <button type="button" wire:click="addRule" class="btn-primary w-full sm:w-auto">Ekle</button>
                </div>
            </div>
        </section>
    @endif

    <p class="text-[13px]"><a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="link-neutral">Ayarlar, Operasyon</a></p>
</div>
