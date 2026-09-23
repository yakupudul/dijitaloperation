<section class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Rakipler</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Markanın tek rakip listesi. Rakip sayfaları, SEO karşılaştırması ve raporlar bu listeyi kullanır.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <button type="button" wire:click="importSerp" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Arama sonuçlarından bul</button>
            <a href="{{ $libraryUrl }}" wire:navigate class="rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Rakip sayfaları →</a>
        </div>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    @if ($competitors === [])
        <p class="text-sm text-gray-500">Henüz rakip yok. Aşağıdaki önerilerden ekleyebilir ya da alan adı yazabilirsin.</p>
    @else
        <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
            @foreach ($competitors as $competitor)
                <li wire:key="competitor-{{ $competitor['id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div>
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $competitor['name'] }}</span>
                        <span class="text-xs text-gray-500">{{ $competitor['domain'] }} · {{ $competitor['urls'] }} sayfa</span>
                        @if ($competitor['status'] !== 'approved')
                            <span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">onay bekliyor</span>
                        @endif
                    </div>
                    <div class="flex gap-2 text-xs">
                        @if ($competitor['status'] !== 'approved')
                            <button type="button" wire:click="review({{ $competitor['id'] }}, 'approved')" class="font-medium text-brand-600 hover:underline">Onayla</button>
                        @endif
                        <button type="button" wire:click="review({{ $competitor['id'] }}, 'rejected')" class="text-gray-500 hover:underline">Rakip değil</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($suggestions !== [])
        <div class="space-y-2">
            <p class="text-xs font-medium uppercase text-gray-400">Öneriler</p>
            <ul class="space-y-1 text-sm">
                @foreach ($suggestions as $suggestion)
                    <li wire:key="suggestion-{{ md5($suggestion['domain']) }}" class="flex flex-wrap items-center justify-between gap-2">
                        <span><span class="text-gray-800 dark:text-gray-200">{{ $suggestion['name'] }}</span> <span class="text-xs text-gray-500">{{ $suggestion['domain'] }} · {{ $suggestion['source'] }}</span></span>
                        <button type="button" wire:click="add(@js($suggestion['domain']), @js($suggestion['name']), @js($suggestion['source']))" class="text-xs font-medium text-brand-600 hover:underline">+ Ekle</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($name_only !== [])
        <p class="text-xs text-gray-500">Alan adı olmadan yazılmış rakipler: {{ implode(', ', $name_only) }} — eklemek için alan adını yaz.</p>
    @endif

    <form wire:submit="addManual" class="flex flex-wrap items-start gap-2 text-sm">
        <input type="text" wire:model="newDomain" placeholder="rakip.com" class="w-48 rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
        <input type="text" wire:model="newName" placeholder="Ad (isteğe bağlı)" class="w-48 rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
        <button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 font-medium text-white hover:bg-brand-600">Rakip ekle</button>
        @error('newDomain') <span class="w-full text-xs text-rose-600">{{ $message }}</span> @enderror
    </form>
</section>
