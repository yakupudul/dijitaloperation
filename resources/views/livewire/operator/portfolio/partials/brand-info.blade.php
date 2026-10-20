{{-- Ayarlar › Marka bilgileri: the one place where the brand's sector, places, services (★ = ana), İş bağlamı (goals and
     constraints included) and conversions are edited. Everything else on the brand page shows them read-only. --}}
@php
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $brandItems = collect($checklist['items'])->where('fix', 'ayarlar')->where('required', true)->where('done', false)->values();
@endphp
<div class="space-y-10" data-brand-info>
    @if ($brandItems->isNotEmpty())
        <section data-brand-checklist>
            <h2 class="section-title">Marka bilgileri eksik</h2>
            <ul class="mt-2 divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($brandItems as $item)
                    <li class="flex flex-wrap items-center gap-x-3 py-2.5" data-setup-item="{{ $item['key'] }}"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><span class="font-medium text-gray-900 dark:text-white">{{ $item['label'] }}</span><span class="text-xs text-warning-800 dark:text-warning-400">{{ $item['detail'] }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:operator.portfolio.brand-settings :brand-id="$brandModel->id" part="info" :key="'brand-settings-info-'.$brandModel->id" />

    <div class="grid gap-10 lg:grid-cols-3">
        <section class="lg:col-span-2" data-section="context">
            <div class="flex items-start justify-between gap-2">
                <h2 class="section-title" title="Bilgi dosyasına girer; AI ajanları ve raporlar her işte okur. Hedefler ve kısıtlar yalnız burada düzenlenir.">İş bağlamı</h2>
                @unless ($editingContext)
                    <button type="button" wire:click="startEditingContext" class="link-neutral shrink-0 text-xs">Düzenle</button>
                @endunless
            </div>
            @if ($editingContext)
                <form wire:submit="saveBusinessContext" class="surface mt-3 grid gap-3 p-4 md:grid-cols-2">
                    @foreach (['context_business_summary' => 'İşletme özeti', 'context_business_model' => 'İş modeli', 'context_priority_offerings' => 'Öncelikli teklifler (satır başına bir)', 'context_target_audiences' => 'Hedef kitle', 'context_positioning' => 'Konumlandırma', 'context_differentiators' => 'Farklılaştırıcılar', 'context_business_goals' => 'İş hedefleri', 'context_conversion_goals' => 'Dönüşüm hedefleri', 'context_constraints' => 'Kısıtlar (asla önerilmez / yazılmaz)'] as $field => $label)
                        <label class="block">
                            <span class="text-xs text-gray-500">{{ $label }}</span>
                            <textarea wire:model="{{ $field }}" rows="2" class="{{ $input }}"></textarea>
                            @error($field)<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
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

        <section data-section="scope">
            <h2 class="section-title">Kapsam</h2>
            <dl class="mt-3 space-y-3">
                <div><dt class="text-xs text-gray-500">Sorumlu ekip</dt><dd class="mt-0.5 text-gray-900 dark:text-white/90">{{ $responsible !== [] ? implode(', ', $responsible) : '—' }}</dd></div>
                <div>
                    <dt class="text-xs text-gray-500">Ajansın verdiği hizmetler</dt>
                    <dd class="mt-0.5 text-gray-900 dark:text-white/90">{{ collect($serviceScope)->where('status', 'active')->pluck('service_label')->implode(', ') ?: '—' }}</dd>
                </div>
            </dl>
            <a href="{{ route('operator.brand.edit', ['brandId' => $brandModel->id]) }}" wire:navigate class="link-neutral mt-3 inline-block text-xs" title="Ad, müşteri, ekip ve diller">Düzenle</a>
        </section>
    </div>

    <livewire:operator.portfolio.brand-conversions :brand-id="(int) $brandModel->id" :key="'brand-conversions-'.$brandModel->id" />

    <livewire:operator.portfolio.brand-experts :brand-id="(int) $brandModel->id" :key="'brand-experts-'.$brandModel->id" />
</div>
