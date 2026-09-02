<div class="space-y-6">
    <div>
        <a href="{{ $backUrl }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600 dark:text-gray-400">← Geri</a>
        <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $pageTitle }}</h1>
        <p class="mt-1 text-sm text-gray-500">Markanın hizmetlerini ve hizmet verdiği bölgeleri tanımlayın. Arama talebi ve görünürlük analizi bu bağlamı kullanır.</p>
    </div>

    <form wire:submit.prevent="save" class="space-y-5 pb-24">
        <x-ta.form.section title="Marka">
            @if ($customerLocked && $customerName)
                <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">Müşteri: <span class="font-medium text-gray-900 dark:text-white">{{ $customerName }}</span></div>
            @else
                <x-ta.form.field label="Müşteri" :required="true" :error="$errors->first('customer_id')">
                    <x-ta.form.select wire:model="customer_id" :options="$customerOptions" placeholder="Müşteri seçin" :nullable="false" />
                </x-ta.form.field>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Marka adı" :required="true" :error="$errors->first('name')">
                    <input wire:model="name" type="text" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                </x-ta.form.field>
                <x-ta.form.field label="Sektör" :error="$errors->first('sector')">
                    <x-ta.form.select wire:model="sector" :options="$industryOptions" placeholder="Sektör seçin" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section title="Hizmetler">
            <div class="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-200">
                Öncelikli işaretlediğiniz hizmetler arama talebi çalışmalarında önce değerlendirilir. Ticari değer uydurulmaz; sıra sizin seçiminizdir.
            </div>
            <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($serviceOptions as $id => $service)
                    <div class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <span class="min-w-0 flex-1">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-white"><input wire:model="selected_service_catalog_ids" value="{{ $id }}" type="checkbox" class="rounded border-gray-300 text-brand-500" /> {{ $service['label'] }}</label>
                            @if ($service['sector'])<span class="block text-xs text-gray-400">{{ $industryOptions[$service['sector']] ?? $service['sector'] }}</span>@endif
                            <label class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                                <input wire:model="priority_service_catalog_ids" value="{{ $id }}" type="checkbox" class="rounded border-gray-300 text-brand-500" /> Öncelikli hizmet
                            </label>
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 md:col-span-2">Henüz hizmet kütüphanesi boş. Aşağıdan ilk hizmeti ekleyebilirsiniz.</p>
                @endforelse
            </div>
            @error('selected_service_catalog_ids') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            <div class="mt-4 grid gap-2 sm:grid-cols-[1fr_auto]">
                <div class="space-y-2"><input wire:model="new_service_name" type="text" placeholder="Listede yoksa yeni hizmet adı" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /><label class="flex items-center gap-2 text-xs text-gray-500"><input wire:model="new_service_is_priority" type="checkbox" class="rounded border-gray-300 text-brand-500" /> Yeni hizmet öncelikli</label></div>
                <a href="{{ route('operator.library.services') }}" wire:navigate class="rounded-lg px-4 py-2.5 text-center text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Hizmet kütüphanesini yönet</a>
            </div>
            @error('new_service_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </x-ta.form.section>

        <x-ta.form.section title="Hizmet bölgeleri">
            <p class="mb-4 text-sm text-gray-500">Birden fazla ülke, şehir veya ilçe ekleyebilirsiniz. İlçe boşsa kapsam şehir; şehir de boşsa kapsam ülke kabul edilir.</p>
            <div class="space-y-3">
                @foreach ($service_areas as $index => $area)
                    <div wire:key="service-area-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 md:grid-cols-[1fr_1fr_1fr_auto] dark:border-gray-700">
                        <select wire:model="service_areas.{{ $index }}.country_code" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                            @foreach ($countryOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                        <input wire:model="service_areas.{{ $index }}.city_name" type="text" placeholder="Şehir (örn. İzmir)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                        <input wire:model="service_areas.{{ $index }}.district_name" type="text" placeholder="İlçe (örn. Bornova)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                        <button type="button" wire:click="removeServiceArea({{ $index }})" class="rounded-lg px-3 py-2 text-sm text-red-600 ring-1 ring-inset ring-red-200">Kaldır</button>
                        @error("service_areas.$index.country_code") <p class="text-xs text-red-600 md:col-span-4">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="addServiceArea" class="mt-3 rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">+ Bölge ekle</button>
        </x-ta.form.section>

        <details class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-white">İsteğe bağlı yönetim bilgileri</summary>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Diller" :error="$errors->first('languages')"><x-ta.form.multi-select wire:model="languages" :options="$languageOptions" placeholder="Dil seçin" /></x-ta.form.field>
                <x-ta.form.field label="Sorumlu ekip" :error="$errors->first('responsible_user_ids')"><x-ta.form.multi-select wire:model="responsible_user_ids" :options="$teamOptions" placeholder="Ekip üyesi seçin" /></x-ta.form.field>
                <x-ta.form.field label="Logo URL" :error="$errors->first('logo_url')"><input wire:model="logo_url" type="url" placeholder="https://…" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></x-ta.form.field>
            </div>
        </details>

        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 lg:left-[290px]">
            <div class="mx-auto flex max-w-screen-2xl justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate class="rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Vazgeç</a>
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"><span wire:loading.remove>{{ $primaryAction }}</span><span wire:loading>Kaydediliyor…</span></button>
            </div>
        </div>
    </form>
</div>
