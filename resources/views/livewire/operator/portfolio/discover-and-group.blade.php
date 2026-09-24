<div class="space-y-6">
    <div>
        <a href="{{ route('operator.customers') }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600 dark:text-gray-400">← Müşteriler</a>
        <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">Toplu ekle</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
            Entegrasyonlarda bulunan ama henüz hiçbir markaya bağlı olmayan hesaplar, alan adına ve isme göre gruplandı.
            Çalıştığın grupların <strong>müşteri adını</strong> yaz (istersen hizmet verdiği şehirleri de); müşteri adı boş kalan gruplar atlanır.
            Sonra "Doldurulanları oluştur"a bas. Site taraması bitince "Otomatik kur" markanın sektörünü ve hizmetlerini hizmet havuzundan kendisi önerir;
            havuzda olan hizmet yeniden oluşturulmaz. Hesap listesi eskiyse önce
            <a href="{{ route('operator.integrations') }}" wire:navigate class="font-medium text-brand-600 underline">Entegrasyonlar</a>'dan hesapları yenile.
        </p>
    </div>

    @if ($groups !== [])
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="createAll" wire:loading.attr="disabled"
                class="inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-60">
                <span wire:loading.remove wire:target="createAll">Doldurulanları oluştur</span>
                <span wire:loading wire:target="createAll">Oluşturuluyor…</span>
            </button>
            <span class="text-sm text-gray-500">{{ $total }} grup{{ $shown < $matching ? ' · '.$shown.' / '.$matching.' gösteriliyor' : '' }}</span>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Grup, alan adı ya da hesap ara…"
                class="h-10 w-64 rounded-lg border border-gray-200 bg-transparent px-3 text-sm dark:border-gray-700 dark:text-white">
            <select wire:model.live="filter" class="h-10 rounded-lg border border-gray-200 bg-transparent px-3 text-sm dark:border-gray-700 dark:text-white">
                <option value="all">Hepsi</option>
                <option value="web">Web adresi olanlar</option>
                <option value="noweb">Web adresi olmayanlar</option>
                <option value="existing">Mevcut markaya ait</option>
            </select>
            @if ($bulkMessage !== '')
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $bulkMessage }}</span>
            @endif
        </div>
    @endif

    @foreach ($created as $formKey => $brand)
        <div wire:key="created-{{ $formKey }}" class="rounded-xl bg-emerald-50 p-4 text-sm ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/20">
            <p class="font-medium text-emerald-800 dark:text-emerald-300">
                {{ $brand['name'] }} oluşturuldu —
                <a href="{{ $brand['url'] }}" wire:navigate class="underline">markayı aç</a>
                (site taraması bitince hizmet önerisi markada hazır olur).
            </p>
            <ul class="mt-2 space-y-1">
                @foreach ($results[$formKey] ?? [] as $row)
                    <li @class(['text-gray-700 dark:text-gray-300' => $row['ok'], 'text-rose-700 dark:text-rose-400' => ! $row['ok']])>
                        {{ $row['ok'] ? '✓' : '✕' }} {{ $row['label'] }} — {{ $row['message'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @if ($groups === [])
        <div class="rounded-xl bg-white p-6 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-400 dark:ring-gray-800">
            Bağlanmamış hesap yok. Yeni müşteri hesabı eklendiyse Entegrasyonlar'dan hesapları yenile.
        </div>
    @endif

    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ($visible as $group)
            @php
                $key = $group['form_key'];
                $existing = $group['existing_brand_id'] !== null;
            @endphp
            <section wire:key="group-{{ $key }}" class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="font-semibold text-gray-800 dark:text-white/90">{{ $group['suggested_brand'] }}</h2>
                        <p class="text-xs text-gray-500">{{ $group['host'] ?? 'Web adresi bulunamadı' }} · {{ count($group['resources']) }} hesap</p>
                    </div>
                    @if ($existing)
                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">Bu site zaten “{{ $group['existing_brand'] }}” markasında</span>
                    @endif
                </div>

                <ul class="space-y-2 text-sm">
                    @foreach ($group['resources'] as $resource)
                        <li wire:key="res-{{ $key }}-{{ $resource['id'] }}">
                            <label class="flex items-start gap-2">
                                <input type="checkbox" wire:model="forms.{{ $key }}.resources.{{ $resource['id'] }}" class="mt-0.5 rounded border-gray-300 text-brand-600" />
                                <span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $resource['type_label'] }}:</span>
                                    <span class="text-gray-700 dark:text-gray-300">{{ $resource['label'] }}</span>
                                    <span class="text-xs text-gray-400">{{ $resource['external_id'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $resource['reason'] }}</span>
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>

                @if (! $existing)
                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <label class="block">
                            <span class="text-gray-500 dark:text-gray-400">Müşteri</span>
                            <select wire:model.live="forms.{{ $key }}.customer_id" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white">
                                <option value="">+ Yeni müşteri</option>
                                @foreach ($customers as $customerId => $customerName)
                                    <option value="{{ $customerId }}">{{ $customerName }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if (($forms[$key]['customer_id'] ?? '') === '')
                            <label class="block">
                                <span class="text-gray-500 dark:text-gray-400">Yeni müşteri adı</span>
                                <input type="text" wire:model="forms.{{ $key }}.customer_name" placeholder="Boş bırakırsan bu grup atlanır" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                                @error('forms.'.$key.'.customer_name') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        <label class="block">
                            <span class="text-gray-500 dark:text-gray-400">Marka adı</span>
                            <input type="text" wire:model="forms.{{ $key }}.brand_name" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                            @error('forms.'.$key.'.brand_name') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-gray-500 dark:text-gray-400">Web sitesi (varsa)</span>
                            <input type="url" wire:model="forms.{{ $key }}.website_url" placeholder="https://" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-gray-500 dark:text-gray-400">Hizmet verdiği şehirler (isteğe bağlı)</span>
                            <input type="text" wire:model="forms.{{ $key }}.cities" placeholder="Manisa, İzmir" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                        </label>
                    </div>
                @endif
                @error('forms.'.$key.'.resources') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                <button type="button" wire:click="create('{{ $key }}')" wire:loading.attr="disabled"
                    class="inline-flex rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                    {{ $existing ? 'Seçili hesapları markaya bağla' : 'Müşteri ve markayı oluştur' }}
                </button>
            </section>
        @endforeach
    </div>
    @if ($shown < $matching)
        <div class="text-center">
            <button type="button" wire:click="showMore" class="rounded-lg px-4 py-2 text-sm font-medium text-brand-700 ring-1 ring-inset ring-brand-300">Daha fazla göster ({{ $matching - $shown }} grup kaldı)</button>
        </div>
    @endif
</div>
