@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $status = $proposal['status'] ?? null;
    $statusText = ['running' => 'AI çalışıyor…', 'no_provider' => 'AI bağlı değil.', 'error' => 'AI adımı başarısız.', 'nothing' => 'Boş alan yok.'][$status] ?? null;
    $total = (int) ($proposal['total'] ?? 0);
    $done = (int) ($proposal['done'] ?? 0);
    $progress = $running && $total > 0;
    if ($progress) {
        $statusText = null;
    }
    $pickedCount = count(array_filter($pick));
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-query-plan @if ($running) wire:poll.2s="syncProposal" @endif>
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">AI ile planla</h1>
        <nav class="flex flex-wrap gap-1" aria-label="Adımlar">
            @foreach ([1 => '1 · Hesaplar ve sektörler', 2 => '2 · Hizmetler ve eşleme kelimeleri', 3 => '3 · Sektör filtre sepeti'] as $number => $label)
                <button type="button" wire:click="goTo({{ $number }})" data-step="{{ $number }}" @class(['rounded-lg px-3 py-1.5 text-xs font-semibold', 'bg-brand-500 text-white' => $step === $number, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $step !== $number])>{{ $label }}</button>
            @endforeach
        </nav>
    </header>

    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    @if ($progress && $step !== 1)
        <div class="{{ $card }} space-y-2" data-plan-progress role="status" aria-live="polite">
            <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                <svg class="h-4 w-4 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>
                <span>{{ $done }} / {{ $total }} sektör tamamlandı · sektörler paralel çalışıyor</span>
                <button type="button" wire:click="stopAi" wire:confirm="AI adımı durdurulsun mu? Gelen sonuçlar silinir." class="ml-auto rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700" data-plan-stop>Durdur</button>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-2 rounded-full bg-brand-500 transition-all" style="width: {{ $total > 0 ? max(4, (int) round($done * 100 / $total)) : 0 }}%"></div></div>
        </div>
    @endif

    {{-- 1 · Hesaplar ve sektörler --}}
    @if ($step === 1)
        <section class="{{ $card }} space-y-3" data-section="sectors">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500">{{ $brands->count() }} marka · {{ $brands->sum(fn ($b) => $b->digitalAssets->count()) }} varlık</span>
                @if ($statusText)<span @class(['text-xs', 'text-gray-500' => in_array($status, ['running', 'nothing'], true), 'text-rose-600' => ! in_array($status, ['running', 'nothing'], true)])>{{ $statusText }}</span>@endif
                <button type="button" wire:click="runAi" @disabled($running) class="{{ $btn }} ml-auto">AI ile sektör ata</button>
                <x-operator.ai-prompt-info operation="queries.plan_sectors" />
            </div>
            <table class="w-full text-left text-xs">
                <thead class="text-gray-500"><tr><th class="py-1">Marka / varlık</th><th>Hesap</th><th>Sektör</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($brands as $brand)
                        @php $brandLabel = $sectors[(int) ($brandSectors[$brand->id] ?? 0)] ?? (str_starts_with((string) ($brandSectors[$brand->id] ?? ''), 'new:') ? substr($brandSectors[$brand->id], 4) : '—'); @endphp
                        <tr wire:key="b-{{ $brand->id }}" class="bg-gray-50 dark:bg-gray-800/40">
                            <td class="py-1.5 font-semibold">{{ $brand->name }} <span class="font-normal text-gray-500">· {{ $brand->customer?->name }}</span></td>
                            <td></td>
                            <td>@include('livewire.operator.library.partials.sector-select', ['model' => 'brandSectors.'.$brand->id, 'brandLabel' => null])</td>
                            <td class="text-gray-500">{{ $proposal['brands'][$brand->id]['reason'] ?? '' }}</td>
                        </tr>
                        @foreach ($brand->digitalAssets as $asset)
                            <tr wire:key="a-{{ $asset->id }}">
                                <td class="py-1 pl-4">{{ $asset->type }} · {{ $asset->name }}</td>
                                <td class="text-gray-500">{{ \App\Services\Queries\QueryPlanner::accountIds($asset) ?: '—' }}</td>
                                <td>@include('livewire.operator.library.partials.sector-select', ['model' => 'assetSectors.'.$asset->id, 'brandLabel' => $brandLabel])</td>
                                <td class="text-gray-500">{{ $proposal['assets'][$asset->id]['reason'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="4" class="py-3 text-gray-500">Marka yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($unbound !== [])
                <h2 class="pt-2 text-xs font-semibold uppercase text-gray-500">Markaya bağlı değil · {{ count($unbound) }}</h2>
                <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800" data-unbound>
                    @foreach ($unbound as $row)
                        <li class="flex gap-2 py-1"><span class="flex-1">{{ $row['type'] }} · {{ $row['name'] }}</span><span class="text-gray-500">{{ $row['account'] }}</span><span class="w-40 text-gray-500">{{ $row['sector'] ?? '—' }}</span></li>
                    @endforeach
                </ul>
            @endif
            <div class="flex justify-end"><button type="button" wire:click="approveSectors" class="{{ $btn }}">Onayla ve devam</button></div>
        </section>
    @endif

    {{-- 2 · Hizmetler ve eşleme kelimeleri --}}
    @if ($step === 2)
        <section class="{{ $card }} space-y-3" data-section="services">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500">{{ $used->count() }} sektör</span>
                @if ($statusText)<span class="text-xs text-gray-500">{{ $statusText }}</span>@endif
                <button type="button" wire:click="runAi" @disabled($running || $used->isEmpty()) class="{{ $btn }} ml-auto">AI ile hizmet keşfet</button>
                <x-operator.ai-prompt-info operation="queries.plan_services" />
            </div>
            @forelse ($used as $sector)
                <div wire:key="s-{{ $sector->id }}" class="space-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
                    <h2 class="font-semibold">{{ $sector->name }}</h2>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($services[$sector->id] ?? [] as $item)
                            <li wire:key="sv-{{ $item->id }}" class="flex flex-wrap items-center gap-2 py-1.5">
                                <span class="w-48 font-medium">{{ $item->primaryName->raw_label }}</span>
                                @foreach ($item->matchingKeywords as $keyword)
                                    <span wire:key="kw-{{ $keyword->id }}" class="{{ $chip }} inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-800">{{ $keyword->label }}<button type="button" wire:click="removeKeyword({{ $keyword->id }})" aria-label="Sil" class="text-gray-500">×</button></span>
                                @endforeach
                                <input type="text" wire:model.live.debounce.500ms="newKeyword.{{ $item->id }}" wire:keydown.enter="addKeyword({{ $item->id }})" placeholder="Kelime" aria-label="Yeni kelime" class="{{ $input }} w-36 py-1 text-xs">
                                <button type="button" wire:click="addKeyword({{ $item->id }})" class="{{ $ghost }}">Ekle</button>
                                <button type="button" wire:click="removeService({{ $item->id }})" wire:confirm="Hizmet silinsin mi?" class="{{ $ghost }} ml-auto">Hizmeti sil</button>
                                @error('newKeyword.'.$item->id)<p class="w-full text-xs text-rose-600">{{ $message }}</p>@enderror
                                @if (($impact['service'] ?? null) === $item->id && trim((string) ($newKeyword[$item->id] ?? '')) !== '')
                                    @include('livewire.operator.library.partials.keyword-impact', ['impact' => $impact, 'rescanNote' => false])
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="newService.{{ $sector->id }}" wire:keydown.enter="addService({{ $sector->id }})" placeholder="Yeni hizmet" aria-label="Yeni hizmet" class="{{ $input }} w-48 py-1 text-xs">
                        <button type="button" wire:click="addService({{ $sector->id }})" class="{{ $ghost }}">Hizmet ekle</button>
                        @error('newService.'.$sector->id)<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                    </div>
                    <details class="text-xs" data-bulk-services="{{ $sector->id }}">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Toplu hizmet ekle</summary>
                        <div class="mt-1 flex flex-wrap items-start gap-2">
                            <textarea wire:model="bulkServices.{{ $sector->id }}" rows="4" placeholder="Her satıra bir hizmet · isteğe bağlı eşleme kelimeleri: Diş Beyazlatma: beyazlatma, bleaching" aria-label="Toplu hizmet" class="{{ $input }} min-w-0 flex-1 text-xs"></textarea>
                            <button type="button" wire:click="addServicesBulk({{ $sector->id }})" class="{{ $btn }}">Hizmetleri ekle</button>
                        </div>
                        @error('bulkServices.'.$sector->id)<p class="text-rose-600">{{ $message }}</p>@enderror
                    </details>
                </div>
            @empty
                <p class="text-gray-500">Sektör yok · 1. adımda sektör seçin.</p>
            @endforelse

            @if ($status === 'ready')
                <div class="border-t border-gray-100 pt-2 dark:border-gray-800" data-proposal>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xs font-semibold uppercase text-gray-500">AI önerisi · {{ count($proposal['items']) }}</h2>
                        @if ($proposal['items'] !== [])
                            <button type="button" wire:click="pickAll(true)" class="{{ $ghost }}">Tümünü seç</button>
                            <button type="button" wire:click="pickAll(false)" class="{{ $ghost }}">Hiçbirini</button>
                            <button type="button" wire:click="applyPicked" @disabled($pickedCount === 0) data-apply-picked class="{{ $btn }} ml-auto">Seçilenleri ekle ({{ $pickedCount }})</button>
                        @endif
                    </div>
                    @if (($proposal['failed'] ?? []) !== [])<p class="text-xs text-rose-600">Yanıt alınamayan sektörler: {{ implode(', ', $proposal['failed']) }} · tekrar çalıştırın.</p>@endif
                    <ul class="mt-1 space-y-1">
                        @forelse ($proposal['items'] as $i => $row)
                            <li class="flex items-start gap-2 text-xs"><input type="checkbox" wire:model.live="pick.{{ $i }}" aria-label="Seç">
                                <span>
                                    @switch($row['type'])
                                        @case('new_service')<span class="font-medium">Yeni hizmet: {{ $row['name'] }}</span>@if ($row['keywords'] !== []) ({{ implode(', ', $row['keywords']) }})@endif @break
                                        @case('add')<span class="font-medium">+ {{ $row['keyword'] }}</span> → {{ $row['service'] }} @break
                                        @case('remove')<span class="font-medium">− {{ $row['keyword'] }}</span> ({{ $row['service'] }}) @break
                                        @case('move')<span class="font-medium">{{ $row['keyword'] }}</span>: {{ $row['service'] }} → {{ $row['to_service'] }} @break
                                    @endswitch
                                    <span class="text-gray-500">· {{ $sectors[$row['sector_id']] ?? '' }} · {{ $row['reason'] }}</span>
                                </span></li>
                        @empty
                            <li class="text-xs text-gray-500">Öneri yok.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
            <div class="flex justify-between">
                <button type="button" wire:click="goTo(1)" class="{{ $ghost }}">Geri</button>
                <button type="button" wire:click="approveServices" class="{{ $btn }}">Onayla ve devam</button>
            </div>
        </section>
    @endif

    {{-- 3 · Sektör filtre sepeti --}}
    @if ($step === 3)
        <section class="{{ $card }} space-y-3" data-section="filters">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500">İçeren sorgu silinir</span>
                @if ($statusText)<span class="text-xs text-gray-500">{{ $statusText }}</span>@endif
                <button type="button" wire:click="runAi" @disabled($running || $used->isEmpty()) class="{{ $btn }} ml-auto">AI ile oluştur</button>
                <x-operator.ai-prompt-info operation="queries.plan_filters" />
            </div>
            <textarea wire:model="filterInstruction" rows="2" maxlength="1000" placeholder="AI talimatınız (isteğe bağlı) · örn. iş ilanı ve eğitim içerikli kelimeler üret" aria-label="AI talimatı" data-filter-instruction class="{{ $input }} w-full text-xs"></textarea>
            @foreach ($used as $sector)
                <div wire:key="f-{{ $sector->id }}" class="space-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
                    <h2 class="font-semibold">{{ $sector->name }} <span class="font-normal text-gray-500">· {{ count($terms[$sector->id] ?? []) }} terim</span></h2>
                    <div class="flex flex-wrap items-center gap-1">
                        @foreach ($terms[$sector->id] ?? [] as $term)
                            <span wire:key="t-{{ $term->id }}" class="{{ $chip }} inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-800">{{ $term->term }}<button type="button" wire:click="deleteTerm({{ $term->id }})" aria-label="Sil" class="text-gray-500">×</button></span>
                        @endforeach
                        <input type="text" wire:model="newTerm.{{ $sector->id }}" wire:keydown.enter="addTerm({{ $sector->id }})" placeholder="Terim" aria-label="Yeni terim" class="{{ $input }} w-36 py-1 text-xs">
                        <button type="button" wire:click="addTerm({{ $sector->id }})" class="{{ $ghost }}">Ekle</button>
                        @error('newTerm.'.$sector->id)<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                    </div>
                </div>
            @endforeach

            @if ($status === 'ready')
                <div class="border-t border-gray-100 pt-2 dark:border-gray-800" data-proposal>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xs font-semibold uppercase text-gray-500">AI önerisi · {{ count($proposal['items']) }}</h2>
                        @if ($proposal['items'] !== [])
                            <button type="button" wire:click="pickAll(true)" class="{{ $ghost }}">Tümünü seç</button>
                            <button type="button" wire:click="pickAll(false)" class="{{ $ghost }}">Hiçbirini</button>
                        @endif
                    </div>
                    @if (($proposal['failed'] ?? []) !== [])<p class="text-xs text-rose-600">Yanıt alınamayan sektörler: {{ implode(', ', $proposal['failed']) }} · tekrar çalıştırın.</p>@endif
                    <ul class="mt-1 space-y-1">
                        @forelse ($proposal['items'] as $i => $row)
                            <li class="flex items-start gap-2 text-xs"><input type="checkbox" wire:model="pick.{{ $i }}" aria-label="Seç">
                                <span><span class="font-medium">{{ $row['term'] }}</span> <span class="text-gray-500">· {{ $row['sector'] }} · {{ $row['reason'] }}</span></span></li>
                        @empty
                            <li class="text-xs text-gray-500">Öneri yok.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
            <div class="flex justify-between">
                <button type="button" wire:click="goTo(2)" class="{{ $ghost }}">Geri</button>
                <button type="button" wire:click="approveFilters" class="{{ $btn }}">Onayla ve içe aktar</button>
            </div>
        </section>
    @endif
</div>
