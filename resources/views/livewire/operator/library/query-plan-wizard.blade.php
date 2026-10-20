@php
    $input = 'rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $btn = 'btn-primary';
    $outline = 'btn';
    $ghost = 'btn h-7! px-2.5! text-xs!';
    $chip = 'inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-300';
    $status = $proposal['status'] ?? null;
    $statusText = ['running' => 'AI çalışıyor…', 'no_provider' => 'AI bağlı değil.', 'error' => 'AI adımı başarısız.', 'nothing' => 'Boş alan yok.'][$status] ?? null;
    $total = (int) ($proposal['total'] ?? 0);
    $done = (int) ($proposal['done'] ?? 0);
    $progress = $running && $total > 0;
    if ($progress) {
        $statusText = null;
    }
    if ($running && ! empty($proposal['waiting'])) {
        $statusText = 'Claude bekleniyor (kuyruk iş saatlerinde 2 saatte bir çalışır)…';
    }
    $pickedCount = count(array_filter($pick));
    $steps = [1 => 'Hesaplar ve sektörler', 2 => 'Hizmetler ve eşleme kelimeleri', 3 => 'Sektör filtre sepeti'];
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-query-plan @if ($running) wire:poll.2s="syncProposal" @endif>
    <header>
        <p class="text-[13px] text-gray-500"><a href="{{ route('operator.library.queries') }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">Sorgular</a></p>
        <h1 class="page-title mt-0.5">AI ile planla</h1>
        <p class="page-sub">Üç adım: hesapları sektöre bağla, hizmetleri ve eşleme kelimelerini kur, sektörün filtre sepetini doldur.</p>
    </header>

    <nav class="no-scrollbar -mt-4 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Adımlar">
        @foreach ($steps as $number => $label)
            <button type="button" wire:click="goTo({{ $number }})" data-step="{{ $number }}" @class(['tab', 'tab-active' => $step === $number])><span class="tabular-nums text-gray-400">{{ $number }}</span> {{ $label }}</button>
        @endforeach
    </nav>

    @if ($message !== '')
        <p role="status" class="tone-info flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    @if ($progress && $step !== 1)
        <div class="card space-y-2.5 px-4 py-3.5" data-plan-progress role="status" aria-live="polite">
            <div class="flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">
                <span class="live-dot" aria-hidden="true"></span>
                <span>{{ $done }} / {{ $total }} sektör tamamlandı, sektörler paralel çalışıyor</span>
                <button type="button" wire:click="stopAi" wire:confirm="AI adımı durdurulsun mu? Gelen sonuçlar silinir." class="btn ml-auto h-7! px-2.5! text-xs!" data-plan-stop>Durdur</button>
            </div>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-1.5 rounded-full bg-gray-900 transition-all dark:bg-white" style="width: {{ $total > 0 ? max(4, (int) round($done * 100 / $total)) : 0 }}%"></div></div>
        </div>
    @endif

    {{-- 1. Hesaplar ve sektörler --}}
    @if ($step === 1)
        <section class="space-y-4" data-section="sectors">
            <div class="section-head mb-0! items-center!">
                <h2 class="section-title">Hesaplar ve sektörler <span class="section-count">{{ $brands->count() }} marka, {{ $brands->sum(fn ($b) => $b->digitalAssets->count()) }} varlık</span></h2>
                <span class="ml-auto flex items-center gap-2">
                    @if ($statusText)<span @class(['text-[13px]', 'text-gray-500' => in_array($status, ['running', 'nothing'], true), 'text-error-600' => ! in_array($status, ['running', 'nothing'], true)])>{{ $statusText }}</span>@endif
                    <button type="button" wire:click="runAi" @disabled($running) class="{{ $outline }}">AI ile sektör ata</button>
                    <x-operator.ai-prompt-info operation="queries.plan_sectors" />
                </span>
            </div>
            <div class="card">
                <table class="data-table table-fixed md:table-auto">
                    <thead><tr><th>Marka ve varlık</th><th class="hidden md:table-cell">Hesap</th><th class="w-[42%] md:w-56">Sektör</th><th class="hidden md:table-cell">Neden</th></tr></thead>
                    <tbody>
                        @forelse ($brands as $brand)
                            @php $brandLabel = $sectors[(int) ($brandSectors[$brand->id] ?? 0)] ?? (str_starts_with((string) ($brandSectors[$brand->id] ?? ''), 'new:') ? substr($brandSectors[$brand->id], 4) : '—'); @endphp
                            <tr wire:key="b-{{ $brand->id }}" class="[&>td]:bg-gray-50/60 dark:[&>td]:bg-white/[0.02]">
                                <td><span class="flex min-w-0 items-center gap-2"><x-ta.brand-avatar :name="$brand->name" :logo="$brand->logo_url" size="sm" /><span class="truncate font-medium text-gray-900 dark:text-white">{{ $brand->name }}</span></span>@if ($brand->customer?->name)<span class="mt-0.5 block pl-8 text-xs text-gray-500">{{ $brand->customer?->name }}</span>@endif @if ($proposal['brands'][$brand->id]['reason'] ?? false)<span class="mt-0.5 block pl-8 text-xs text-gray-500 md:hidden">{{ $proposal['brands'][$brand->id]['reason'] }}</span>@endif</td>
                                <td class="hidden md:table-cell"></td>
                                <td>@include('livewire.operator.library.partials.sector-select', ['model' => 'brandSectors.'.$brand->id, 'brandLabel' => null])</td>
                                <td class="hidden text-xs text-gray-500 md:table-cell">{{ $proposal['brands'][$brand->id]['reason'] ?? '' }}</td>
                            </tr>
                            @foreach ($brand->digitalAssets as $asset)
                                <tr wire:key="a-{{ $asset->id }}">
                                    @php $accountIds = \App\Services\Queries\QueryPlanner::accountIds($asset); @endphp
                                    <td class="wrap-anywhere pl-8! md:pl-12!"><span class="text-[13px] text-gray-900 dark:text-white">{{ $asset->name }}</span> <span class="text-xs text-gray-500">{{ $asset->type }}</span>@if ($accountIds)<span class="mt-0.5 block text-xs text-gray-500 md:hidden">{{ $accountIds }}</span>@endif @if ($proposal['assets'][$asset->id]['reason'] ?? false)<span class="mt-0.5 block text-xs text-gray-500 md:hidden">{{ $proposal['assets'][$asset->id]['reason'] }}</span>@endif</td>
                                    <td class="hidden wrap-anywhere text-xs text-gray-500 md:table-cell">{{ $accountIds ?: '—' }}</td>
                                    <td>@include('livewire.operator.library.partials.sector-select', ['model' => 'assetSectors.'.$asset->id, 'brandLabel' => $brandLabel])</td>
                                    <td class="hidden text-xs text-gray-500 md:table-cell">{{ $proposal['assets'][$asset->id]['reason'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        @empty
                            <tr><td colspan="4" class="text-gray-500">Marka yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($unbound !== [])
                <div class="pt-6">
                    <div class="section-head"><h2 class="section-title">Markaya bağlı değil <span class="section-count">{{ count($unbound) }}</span></h2></div>
                    <ul class="card" data-unbound>
                        @foreach ($unbound as $row)
                            <li class="card-row py-2.5! text-[13px]"><span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $row['name'] }} <span class="text-xs text-gray-500">{{ $row['type'] }}</span></span><span class="text-xs text-gray-500">{{ $row['account'] }}</span><span class="w-40 text-xs text-gray-500">{{ $row['sector'] ?? '—' }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="flex justify-end"><button type="button" wire:click="approveSectors" class="{{ $btn }} w-full sm:w-auto">Onayla ve devam</button></div>
        </section>
    @endif

    {{-- 2. Hizmetler ve eşleme kelimeleri --}}
    @if ($step === 2)
        <section class="space-y-8" data-section="services">
            <div class="section-head mb-0! items-center!">
                <h2 class="section-title">Hizmetler ve eşleme kelimeleri <span class="section-count">{{ $used->count() }} sektör</span></h2>
                <span class="ml-auto flex items-center gap-2">
                    @if ($statusText)<span class="text-[13px] text-gray-500">{{ $statusText }}</span>@endif
                    <button type="button" wire:click="runAi" @disabled($running || $used->isEmpty()) class="{{ $outline }}">AI ile hizmet keşfet</button>
                    <x-operator.ai-prompt-info operation="queries.plan_services" />
                </span>
            </div>
            @forelse ($used as $sector)
                <div wire:key="s-{{ $sector->id }}">
                    <h3 class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $sector->name }} <span class="font-normal text-gray-500">{{ count($services[$sector->id] ?? []) }} hizmet</span></h3>
                    <div class="card">
                        <ul>
                            @foreach ($services[$sector->id] ?? [] as $item)
                                <li wire:key="sv-{{ $item->id }}" class="card-row flex-wrap gap-2!">
                                    <span class="w-full text-sm font-medium text-gray-900 sm:w-48 dark:text-white">{{ $item->primaryName->raw_label }}</span>
                                    @foreach ($item->matchingKeywords as $keyword)
                                        <span wire:key="kw-{{ $keyword->id }}" class="{{ $chip }}">{{ $keyword->label }}<button type="button" wire:click="removeKeyword({{ $keyword->id }})" aria-label="Sil" class="text-gray-400 hover:text-gray-900 dark:hover:text-white">×</button></span>
                                    @endforeach
                                    <input type="text" wire:model.live.debounce.500ms="newKeyword.{{ $item->id }}" wire:keydown.enter="addKeyword({{ $item->id }})" placeholder="Kelime" aria-label="Yeni kelime" class="{{ $input }} h-7 w-36">
                                    <button type="button" wire:click="addKeyword({{ $item->id }})" class="{{ $ghost }}">Ekle</button>
                                    <button type="button" wire:click="removeService({{ $item->id }})" wire:confirm="Hizmet silinsin mi?" class="btn-quiet ml-auto h-7! px-2! text-xs! hover:text-error-700">Hizmeti sil</button>
                                    @error('newKeyword.'.$item->id)<p class="w-full text-xs text-error-600">{{ $message }}</p>@enderror
                                    @if (($impact['service'] ?? null) === $item->id && trim((string) ($newKeyword[$item->id] ?? '')) !== '')
                                        @include('livewire.operator.library.partials.keyword-impact', ['impact' => $impact, 'rescanNote' => false])
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="card-row flex-wrap gap-2! bg-gray-50/60 dark:bg-white/[0.02]">
                            <input type="text" wire:model="newService.{{ $sector->id }}" wire:keydown.enter="addService({{ $sector->id }})" placeholder="Yeni hizmet" aria-label="Yeni hizmet" class="{{ $input }} h-8 w-48">
                            <button type="button" wire:click="addService({{ $sector->id }})" class="btn">Hizmet ekle</button>
                            @error('newService.'.$sector->id)<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                            <details class="w-full text-xs" data-bulk-services="{{ $sector->id }}">
                                <summary class="cursor-pointer text-[13px] text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Toplu hizmet ekle</summary>
                                <div class="mt-2 flex flex-wrap items-start gap-2">
                                    <textarea wire:model="bulkServices.{{ $sector->id }}" rows="4" placeholder="Her satıra bir hizmet, isteğe bağlı eşleme kelimeleri: Diş Beyazlatma: beyazlatma, bleaching" aria-label="Toplu hizmet" class="{{ $input }} min-w-0 flex-1 py-2"></textarea>
                                    <button type="button" wire:click="addServicesBulk({{ $sector->id }})" class="{{ $outline }}">Hizmetleri ekle</button>
                                </div>
                                @error('bulkServices.'.$sector->id)<p class="text-error-600">{{ $message }}</p>@enderror
                            </details>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card"><x-ta.empty-state icon="none" size="sm" title="Sektör yok." message="1. adımda sektör seçin." /></div>
            @endforelse

            @if ($status === 'ready')
                <div data-proposal>
                    <div class="section-head items-center!">
                        <h2 class="section-title">AI önerisi <span class="section-count">{{ count($proposal['items']) }}</span></h2>
                        @if ($proposal['items'] !== [])
                            <span class="ml-auto flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="pickAll(true)" class="btn-quiet">Tümünü seç</button>
                                <button type="button" wire:click="pickAll(false)" class="btn-quiet">Hiçbirini</button>
                                <button type="button" wire:click="applyPicked" @disabled($pickedCount === 0) data-apply-picked class="{{ $outline }}">Seçilenleri ekle ({{ $pickedCount }})</button>
                            </span>
                        @endif
                    </div>
                    @if (($proposal['failed'] ?? []) !== [])<p class="mb-2 text-[13px] text-error-600">Yanıt alınamayan sektörler: {{ implode(', ', $proposal['failed']) }}. Tekrar çalıştırın.</p>@endif
                    <ul class="card">
                        @forelse ($proposal['items'] as $i => $row)
                            <li class="card-row items-start py-2.5! text-[13px]"><input type="checkbox" wire:model.live="pick.{{ $i }}" aria-label="Seç" class="mt-0.5 rounded border-gray-300 dark:border-gray-700">
                                <span class="min-w-0 flex-1">
                                    @switch($row['type'])
                                        @case('new_service')<span class="font-medium text-gray-900 dark:text-white">Yeni hizmet: {{ $row['name'] }}</span>@if ($row['keywords'] !== []) ({{ implode(', ', $row['keywords']) }})@endif @break
                                        @case('add')<span class="font-medium text-gray-900 dark:text-white">+ {{ $row['keyword'] }}</span>, {{ $row['service'] }} hizmetine @break
                                        @case('remove')<span class="font-medium text-gray-900 dark:text-white">− {{ $row['keyword'] }}</span> ({{ $row['service'] }}) @break
                                        @case('move')<span class="font-medium text-gray-900 dark:text-white">{{ $row['keyword'] }}</span>, {{ $row['service'] }} hizmetinden {{ $row['to_service'] }} hizmetine @break
                                    @endswitch
                                    <span class="mt-0.5 block text-xs text-gray-500">{{ $sectors[$row['sector_id']] ?? '' }}, {{ $row['reason'] }}</span>
                                </span></li>
                        @empty
                            <li><x-ta.empty-state icon="none" size="sm" title="Öneri yok." /></li>
                        @endforelse
                    </ul>
                </div>
            @endif
            <div class="flex justify-between gap-2">
                <button type="button" wire:click="goTo(1)" class="btn">Geri</button>
                <button type="button" wire:click="approveServices" class="{{ $btn }}">Onayla ve devam</button>
            </div>
        </section>
    @endif

    {{-- 3. Sektör filtre sepeti --}}
    @if ($step === 3)
        <section class="space-y-8" data-section="filters">
            <div class="space-y-3">
                <div class="section-head mb-0! items-center!">
                    <h2 class="section-title">Sektör filtre sepeti <span class="section-count">içeren sorgu silinir</span></h2>
                    <span class="ml-auto flex items-center gap-2">
                        @if ($statusText)<span class="text-[13px] text-gray-500">{{ $statusText }}</span>@endif
                        <button type="button" wire:click="runAi" @disabled($running || $used->isEmpty()) class="{{ $outline }}">AI ile oluştur</button>
                        <x-operator.ai-prompt-info operation="queries.plan_filters" />
                    </span>
                </div>
                <textarea wire:model="filterInstruction" rows="2" maxlength="1000" placeholder="AI talimatınız (isteğe bağlı), örn. iş ilanı ve eğitim içerikli kelimeler üret" aria-label="AI talimatı" data-filter-instruction class="{{ $input }} block w-full py-2"></textarea>
            </div>
            @foreach ($used as $sector)
                <div wire:key="f-{{ $sector->id }}">
                    <h3 class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $sector->name }} <span class="font-normal text-gray-500">{{ count($terms[$sector->id] ?? []) }} terim</span></h3>
                    <div class="card flex flex-wrap items-center gap-1.5 px-4 py-3.5">
                        @foreach ($terms[$sector->id] ?? [] as $term)
                            <span wire:key="t-{{ $term->id }}" class="{{ $chip }}">{{ $term->term }}<button type="button" wire:click="deleteTerm({{ $term->id }})" aria-label="Sil" class="text-gray-400 hover:text-gray-900 dark:hover:text-white">×</button></span>
                        @endforeach
                        <input type="text" wire:model="newTerm.{{ $sector->id }}" wire:keydown.enter="addTerm({{ $sector->id }})" placeholder="Terim" aria-label="Yeni terim" class="{{ $input }} h-7 w-36">
                        <button type="button" wire:click="addTerm({{ $sector->id }})" class="{{ $ghost }}">Ekle</button>
                        @error('newTerm.'.$sector->id)<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                    </div>
                </div>
            @endforeach

            @if ($status === 'ready')
                <div data-proposal>
                    <div class="section-head items-center!">
                        <h2 class="section-title">AI önerisi <span class="section-count">{{ count($proposal['items']) }}</span></h2>
                        @if ($proposal['items'] !== [])
                            <span class="ml-auto flex items-center gap-2">
                                <button type="button" wire:click="pickAll(true)" class="btn-quiet">Tümünü seç</button>
                                <button type="button" wire:click="pickAll(false)" class="btn-quiet">Hiçbirini</button>
                            </span>
                        @endif
                    </div>
                    @if (($proposal['failed'] ?? []) !== [])<p class="mb-2 text-[13px] text-error-600">Yanıt alınamayan sektörler: {{ implode(', ', $proposal['failed']) }}. Tekrar çalıştırın.</p>@endif
                    <ul class="card">
                        @forelse ($proposal['items'] as $i => $row)
                            <li class="card-row items-start py-2.5! text-[13px]"><input type="checkbox" wire:model="pick.{{ $i }}" aria-label="Seç" class="mt-0.5 rounded border-gray-300 dark:border-gray-700">
                                <span class="min-w-0 flex-1"><span class="font-medium text-gray-900 dark:text-white">{{ $row['term'] }}</span> <span class="text-xs text-gray-500">{{ $row['sector'] }}, {{ $row['reason'] }}</span></span></li>
                        @empty
                            <li><x-ta.empty-state icon="none" size="sm" title="Öneri yok." /></li>
                        @endforelse
                    </ul>
                </div>
            @endif
            <div class="flex justify-between gap-2">
                <button type="button" wire:click="goTo(2)" class="btn">Geri</button>
                <button type="button" wire:click="approveFilters" class="{{ $btn }}">Onayla ve içe aktar</button>
            </div>
        </section>
    @endif
</div>
