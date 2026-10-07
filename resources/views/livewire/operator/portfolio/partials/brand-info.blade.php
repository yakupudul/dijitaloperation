{{-- Ayarlar › Marka bilgileri: the one place where the brand's sector, places, services (★ = ana), İş bağlamı (goals and
     constraints included) and conversions are edited. Everything else on the brand page shows them read-only. --}}
@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $brandItems = collect($checklist['items'])->where('fix', 'ayarlar')->where('required', true)->where('done', false)->values();
@endphp
<div class="space-y-4" data-brand-info>
    @if ($brandItems->isNotEmpty())
        <section class="rounded-xl bg-amber-50 p-3 text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30" data-brand-checklist>
            <p class="font-semibold">Marka bilgileri eksik</p>
            <ul class="mt-1 space-y-0.5 text-xs">
                @foreach ($brandItems as $item)
                    <li data-setup-item="{{ $item['key'] }}"><span class="font-semibold">{{ $item['label'] }}</span> · {{ $item['detail'] }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:operator.portfolio.brand-settings :brand-id="$brandModel->id" part="info" :key="'brand-settings-info-'.$brandModel->id" />

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="{{ $card }} lg:col-span-2" data-section="context">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2 class="font-semibold text-gray-900 dark:text-white">İş bağlamı</h2>
                    <p class="text-xs text-gray-500">Bilgi dosyasına girer; AI ajanları ve raporlar her işte okur. Hedefler ve kısıtlar yalnız burada düzenlenir.</p>
                </div>
                @unless ($editingContext)
                    <button type="button" wire:click="startEditingContext" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Düzenle</button>
                @endunless
            </div>
            @if ($editingContext)
                <form wire:submit="saveBusinessContext" class="mt-3 grid gap-3 md:grid-cols-2">
                    @foreach (['context_business_summary' => 'İşletme özeti', 'context_business_model' => 'İş modeli', 'context_priority_offerings' => 'Öncelikli teklifler (satır başına bir)', 'context_target_audiences' => 'Hedef kitle', 'context_positioning' => 'Konumlandırma', 'context_differentiators' => 'Farklılaştırıcılar', 'context_business_goals' => 'İş hedefleri', 'context_conversion_goals' => 'Dönüşüm hedefleri', 'context_constraints' => 'Kısıtlar (asla önerilmez / yazılmaz)'] as $field => $label)
                        <label class="block">
                            <span class="text-xs text-gray-500">{{ $label }}</span>
                            <textarea wire:model="{{ $field }}" rows="2" class="{{ $input }}"></textarea>
                            @error($field)<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <div class="flex gap-2 md:col-span-2">
                        <x-ta.button type="submit" size="sm">Kaydet</x-ta.button>
                        <x-ta.button type="button" wire:click="cancelEditingContext" size="sm" variant="outline">Vazgeç</x-ta.button>
                    </div>
                </form>
            @else
                <dl class="mt-3 grid gap-3 md:grid-cols-2">
                    @forelse ($context as $row)
                        <div><dt class="text-xs text-gray-500">{{ $row['label'] }}</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900 dark:text-white/90">{{ $row['value'] !== '' ? $row['value'] : '—' }}</dd></div>
                    @empty
                        <p class="text-gray-500">Henüz girilmedi. AI analizleri ve raporlar bu bilgiyi kullanır.</p>
                    @endforelse
                </dl>
            @endif
        </section>

        <section class="{{ $card }}" data-section="scope">
            <h2 class="font-semibold text-gray-900 dark:text-white">Kapsam</h2>
            <dl class="mt-3 space-y-3">
                <div><dt class="text-xs text-gray-500">Sorumlu ekip</dt><dd class="mt-0.5 text-gray-900 dark:text-white/90">{{ $responsible !== [] ? implode(', ', $responsible) : '—' }}</dd></div>
                <div>
                    <dt class="text-xs text-gray-500">Ajansın verdiği hizmetler</dt>
                    <dd class="mt-0.5 text-gray-900 dark:text-white/90">{{ collect($serviceScope)->where('status', 'active')->pluck('service_label')->implode(', ') ?: '—' }}</dd>
                </div>
            </dl>
            <a href="{{ route('operator.brand.edit', ['brandId' => $brandModel->id]) }}" wire:navigate class="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Ad, müşteri, ekip ve diller → Düzenle</a>
        </section>
    </div>

    <livewire:operator.portfolio.brand-conversions :brand-id="(int) $brandModel->id" :key="'brand-conversions-'.$brandModel->id" />

    <livewire:operator.portfolio.brand-experts :brand-id="(int) $brandModel->id" :key="'brand-experts-'.$brandModel->id" />
</div>
