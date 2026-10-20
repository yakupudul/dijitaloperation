{{-- Ayarlar › Marka bilgileri: the one place where the brand's sector, places, services (★ = ana), İş bağlamı (goals and
     constraints included) and conversions are edited. Everything else on the brand page shows them read-only. --}}
@php
    $input = 'mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $brandItems = collect($checklist['items'])->where('fix', 'ayarlar')->where('required', true)->where('done', false)->values();
@endphp
<div class="space-y-10" data-brand-info>
    @if ($brandItems->isNotEmpty())
        <section data-brand-checklist>
            <div class="section-head">
                <h2 class="section-title">Marka bilgileri eksik <span class="section-count">{{ $brandItems->count() }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($brandItems as $item)
                    <li class="card-row" data-setup-item="{{ $item['key'] }}">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1 sm:flex sm:items-baseline sm:gap-4">
                            <p class="text-sm font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $item['label'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 sm:mt-0 dark:text-gray-400">{{ $item['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:operator.portfolio.brand-settings :brand-id="$brandModel->id" part="info" :key="'brand-settings-info-'.$brandModel->id" />

    <div class="grid gap-x-6 gap-y-10 lg:grid-cols-3">
        <section class="lg:col-span-2" data-section="context">
            <div class="section-head">
                <h2 class="section-title" title="Bilgi dosyasına girer; AI ajanları ve raporlar her işte okur. Hedefler ve kısıtlar yalnız burada düzenlenir.">İş bağlamı</h2>
                @unless ($editingContext)
                    <button type="button" wire:click="startEditingContext" class="btn-quiet ml-auto h-7! px-2!">Düzenle</button>
                @endunless
            </div>
            @if ($editingContext)
                <form wire:submit="saveBusinessContext" class="card grid gap-4 p-5 md:grid-cols-2">
                    @foreach (['context_business_summary' => 'İşletme özeti', 'context_business_model' => 'İş modeli', 'context_priority_offerings' => 'Öncelikli teklifler (satır başına bir)', 'context_target_audiences' => 'Hedef kitle', 'context_positioning' => 'Konumlandırma', 'context_differentiators' => 'Farklılaştırıcılar', 'context_business_goals' => 'İş hedefleri', 'context_conversion_goals' => 'Dönüşüm hedefleri', 'context_constraints' => 'Kısıtlar (asla önerilmez / yazılmaz)'] as $field => $label)
                        <label class="block">
                            <span class="text-[13px] text-gray-600 dark:text-gray-400">{{ $label }}</span>
                            <textarea wire:model="{{ $field }}" rows="2" class="{{ $input }}"></textarea>
                            @error($field)<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <div class="flex gap-2 md:col-span-2">
                        <button type="submit" class="btn-primary">Kaydet</button>
                        <button type="button" wire:click="cancelEditingContext" class="btn">Vazgeç</button>
                    </div>
                </form>
            @elseif (collect($context)->isEmpty())
                <div class="card"><div class="card-row"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">İş bağlamı girilmedi</p><p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Henüz girilmedi. AI analizleri ve raporlar bu bilgiyi kullanır.</p></div><button type="button" wire:click="startEditingContext" class="btn">Gir</button></div></div>
            @else
                <dl class="card grid gap-x-6 gap-y-4 p-5 text-sm md:grid-cols-2">
                    @foreach ($context as $row)
                        <div><dt class="text-[13px] text-gray-500">{{ $row['label'] }}</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900 dark:text-white/90">{{ $row['value'] !== '' ? $row['value'] : '—' }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </section>

        <section data-section="scope">
            <div class="section-head">
                <h2 class="section-title">Kapsam</h2>
                <a href="{{ route('operator.brand.edit', ['brandId' => $brandModel->id]) }}" wire:navigate class="btn-quiet ml-auto h-7! px-2!" title="Ad, müşteri, ekip ve diller">Düzenle</a>
            </div>
            <dl class="card text-sm">
                <div class="border-b border-gray-200 px-4 py-3.5 dark:border-gray-800"><dt class="text-[13px] text-gray-500">Sorumlu ekip</dt><dd class="mt-0.5 text-gray-900 dark:text-white/90">{{ $responsible !== [] ? implode(', ', $responsible) : '—' }}</dd></div>
                <div class="px-4 py-3.5">
                    <dt class="text-[13px] text-gray-500">Ajansın verdiği hizmetler</dt>
                    <dd class="mt-0.5 text-gray-900 dark:text-white/90">{{ collect($serviceScope)->where('status', 'active')->pluck('service_label')->implode(', ') ?: '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <livewire:operator.portfolio.brand-conversions :brand-id="(int) $brandModel->id" :key="'brand-conversions-'.$brandModel->id" />

    <livewire:operator.portfolio.brand-experts :brand-id="(int) $brandModel->id" :key="'brand-experts-'.$brandModel->id" />
</div>
