@php
    $input = 'w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700 dark:text-white';
    $kindLabels = ['forbidden_phrase' => 'Yasaklı ifade', 'required_phrase' => 'Zorunlu ifade', 'targeting' => 'Hedefleme'];
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">Sektör paketleri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Bir paket, sektörü eşleşen markalara uygulanır. Kurallar AI taslaklarında (anında), yayındaki Meta reklamlarında, site sayfalarında ve İşletme Profili içeriğinde her gün aranır; bulgular <a href="{{ route('operator.compliance') }}" wire:navigate class="text-brand-600 hover:underline">Uyum</a> sayfasında.</p>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    @foreach ($packs as $pack)
        <section wire:key="pack-{{ $pack['id'] }}" class="space-y-3 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-semibold text-gray-800 dark:text-white/90">{{ $pack['label'] }} paketi</h2>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">{{ $pack['description'] }}</p>
                    <p class="mt-1 text-xs text-gray-500">Sektörler: {{ implode(', ', $pack['sectors']) }}</p>
                </div>
                @if ($isAdmin)
                    <button type="button" wire:click="togglePack('{{ $pack['id'] }}')" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset {{ $pack['enabled'] ? 'text-emerald-700 ring-emerald-300' : 'text-gray-500 ring-gray-300' }}">{{ $pack['enabled'] ? 'Açık' : 'Kapalı' }}</button>
                @else
                    <span class="text-sm">{{ $pack['enabled'] ? 'Açık' : 'Kapalı' }}</span>
                @endif
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($pack['rules'] as $rule)
                    <div wire:key="rule-{{ $rule->id }}" @class(['py-3', 'opacity-50' => ! $rule->active])>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $rule->label }} <span class="text-xs font-normal text-gray-400">{{ $kindLabels[$rule->kind] ?? $rule->kind }} · {{ $rule->severity }} · {{ collect($rule->applies_to ?? [])->map(fn ($s) => $sources[$s] ?? $s)->implode(', ') ?: 'tüm kaynaklar' }}{{ $rule->origin === 'operator' ? ' · elle düzenlendi' : '' }}</span></p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ implode(' · ', (array) $rule->patterns) }}</p>
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
                                @error('editPatterns')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="space-y-3 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="font-semibold text-gray-800 dark:text-white/90">Hizmet Beyni freni</h2>
        <p class="text-sm text-gray-500">Sektör kuralları Beyin'in öğrenmesine katılmaz, önerilerini süzer: yukarıdaki yasaklı ifadeleri içeren öneriler ve aşağıdaki öneri türleri bu sektörlerin markalarına gösterilmez ("Frenlendi" olarak kayıtta kalır).</p>
        <ul class="text-xs text-gray-600 dark:text-gray-400">
            @foreach ($blockedTypes as $packId => $types)
                <li><strong>{{ $packId }}</strong>: {{ implode(', ', $types) }}</li>
            @endforeach
        </ul>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
            <div class="text-sm font-medium text-gray-800 dark:text-gray-200">Yasal kapı — sağlıkta ücretli reklam: {{ $gateOn ? 'AÇIK' : 'kapalı' }}</div>
            <p class="mt-1 text-xs text-gray-500">RG 12.11.2025 / 33075 sayılı Sağlık Hizmetlerinde Tanıtım ve Bilgilendirme Faaliyetleri Hakkında Yönetmelik'in (ikincil kaynaklara göre) Türkiye'ye yönelik ücretli sağlık tanıtımını kısıtladığı bildiriliyor; hukuk görüşü alınmadan açmayın. Açıldığında (Ayarlar → Yöntem Kütüphanesi → Hizmet Beyni → legal.health_paid_ads_gate = 1) sağlık markalarına yeni reklam grubu / yeni Meta açısı gibi ücretli reklam büyütme önerileri yalnız burada uygunluğu kaydedilmiş markalara gösterilir.</p>
            @if ($healthBrands->isNotEmpty())
                <table class="mt-3 w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Sağlık markası</th><th class="py-1">Ücretli reklam uygunluğu</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($healthBrands as $brand)
                            @php $row = $eligibility->get($brand->id); $basis = $row && $row->paid_ads_allowed ? $row->basis : ''; @endphp
                            <tr>
                                <td class="py-1.5">{{ $brand->name }}</td>
                                <td class="py-1.5">
                                    @if ($isAdmin)
                                        <select wire:change="setEligibility({{ $brand->id }}, $event.target.value)" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                                            <option value="" @selected($basis === '')>Kayıtlı değil</option>
                                            <option value="first_month" @selected($basis === 'first_month')>Açılışın ilk ayı (1 ay geçerli)</option>
                                            <option value="health_tourism_abroad" @selected($basis === 'health_tourism_abroad')>Sağlık turizmi yetkisi, yurt dışına yabancı dilde</option>
                                            <option value="legal_opinion" @selected($basis === 'legal_opinion')>Hukuk görüşüyle uygun</option>
                                        </select>
                                        @if ($row && $row->valid_until)<span class="text-xs text-gray-500">({{ $row->valid_until }} tarihine kadar)</span>@endif
                                    @else
                                        <span class="text-xs">{{ $basis ?: 'Kayıtlı değil' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    @if ($isAdmin && $packs !== [])
        <section class="space-y-2 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Yeni yasaklı ifade kuralı</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <select wire:model="newPack" class="{{ $input }}"><option value="">Paket seç…</option>@foreach ($packs as $pack)<option value="{{ $pack['id'] }}">{{ $pack['label'] }}</option>@endforeach</select>
                <input type="text" wire:model="newLabel" placeholder="Kural adı" class="{{ $input }}" />
                <textarea wire:model="newPatterns" rows="3" placeholder="İfadeler (satır başına bir)" class="{{ $input }}"></textarea>
                <textarea wire:model="newMessage" rows="3" placeholder="Operatöre öneri" class="{{ $input }}"></textarea>
            </div>
            @error('newPack')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            <button type="button" wire:click="addRule" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Ekle</button>
        </section>
    @endif
</div>
