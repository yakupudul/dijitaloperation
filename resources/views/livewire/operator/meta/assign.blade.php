@php
    $currency = $account['currency'] ?? '';
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $aiRunning = ($aiState['status'] ?? null) === 'running';
    $select = 'h-8 min-w-0 rounded-lg border border-gray-200 bg-white pl-2.5 pr-8 text-[13px] text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <div class="min-w-0">
        <nav class="mb-3 flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[13px] text-gray-500" aria-label="Konum">
            @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="min-w-0 break-words hover:text-gray-900 dark:hover:text-white">{{ $brand->name }}</a><span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>@endif
            <a href="{{ route('operator.meta.overview', ['assetId' => $assetId]) }}" wire:navigate class="min-w-0 break-words hover:text-gray-900 dark:hover:text-white">{{ $asset['name'] ?? 'Meta' }}</a>
        </nav>
        <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0 flex-1">
                <h1 class="page-title" title="Sistem her kampanyaya hizmeti önce reklamın gittiği sayfadan, sonra reklam metninden, sonra kampanya adından önerir. Onayladığın ya da değiştirdiğin hizmet kalır; sonraki veri çekişinde ezilmez. Reklam hesabına hiçbir şey yazılmaz.">Eşleşmeyenleri ata</h1>
                <p class="page-sub"><span class="tabular-nums">{{ $open }}</span> kampanya karar bekliyor. Reklam hesabına hiçbir şey yazılmaz.</p>
            </div>
            @if ($rows !== [])
                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end">
                    <span class="text-[13px] tabular-nums text-gray-500">{{ count($selected) }} seçili</span>
                    <button type="button" wire:click="excludeSelected" @disabled($selected === []) wire:confirm="Seçili kampanyalar hizmet dışı işaretlensin mi?" class="btn h-9!">Seçilenler hizmet dışı</button>
                    <button type="button" wire:click="approveSelected" @disabled($selected === []) class="btn-primary h-9!">Seçilenlerde öneriyi onayla</button>
                </div>
            @endif
        </header>
    </div>

    <section data-testid="meta-assign">
        @if ($rows === [])
            <div class="card"><x-ta.empty-state title="Karar bekleyen kampanya yok." message="Her kampanyanın hizmeti belli." /></div>
        @else
            <div class="section-head">
                <h2 class="section-title">Kampanyalar <span class="section-count">{{ count($rows) }}</span></h2>
                @if ($withoutSuggestion > 0)
                    <button type="button" wire:click="askAi" @disabled($aiRunning) class="btn-quiet sm:ml-auto">Önerisi olmayanları AI ile eşleştir ({{ $withoutSuggestion }})</button>
                @endif
            </div>
            @if ($aiState)
                <p @class(['mb-3 flex items-center gap-1.5 text-[13px]', 'text-gray-500' => $aiRunning, 'text-success-700 dark:text-success-400' => ($aiState['status'] ?? '') === 'ready', 'text-error-700 dark:text-error-400' => ($aiState['status'] ?? '') === 'failed']) @if ($aiRunning) wire:poll.10s @endif>@if ($aiRunning)<span class="live-dot"></span>@endif AI eşleştirme: {{ $aiState['message'] ?? '' }}</p>
            @endif
            <ul class="card">
                @foreach ($rows as $row)
                    <li wire:key="assign-{{ $row['id'] }}" @class(['flex min-w-0 flex-wrap items-start gap-x-3.5 gap-y-3 border-t border-gray-200 px-4 py-4 first:border-t-0 lg:flex-nowrap dark:border-gray-800', 'bg-gray-50/70 dark:bg-white/[0.02]' => $row['done'] !== null])>
                        <span class="w-4 shrink-0 pt-0.5">
                            @if ($row['done'] === null)
                                <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" aria-label="{{ $row['name'] }} seç" class="h-4 w-4 rounded border-gray-300">
                            @endif
                        </span>
                        {{-- What the campaign is: name, one gray line, the ad text and where it sends people. --}}
                        <div class="min-w-0 flex-1 basis-60">
                            <a href="{{ route('operator.meta.campaign', ['assetId' => $assetId, 'campaignId' => $row['id']]) }}" wire:navigate @class(['line-clamp-2 break-words text-sm font-medium hover:underline', 'text-gray-900 dark:text-white' => $row['done'] === null, 'text-gray-500' => $row['done'] !== null]) title="{{ $row['name'] }}">{{ $row['name'] }}</a>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $row['objective'] }}, {{ $money($row['spend']) }}, {{ ['live' => 'yayında', 'paused' => 'durmuş', 'ended' => 'bitti'][$row['status']] }}</p>
                            <p class="mt-1.5 text-[13px] leading-relaxed text-gray-700 dark:text-gray-300">@if ($row['sample'] !== '')“{{ \Illuminate\Support\Str::limit($row['sample'], 160) }}”@else<span class="text-gray-500">Metin yok</span>@endif</p>
                            @if (filled($row['landing']))<p class="mt-0.5 break-all text-xs text-gray-500">{{ $row['landing'] }}</p>@endif
                        </div>
                        {{-- What the system suggests. --}}
                        <div class="min-w-0 basis-full pl-7 lg:w-56 lg:shrink-0 lg:basis-auto lg:pl-0">
                            <p class="text-xs text-gray-500">Önerilen hizmet</p>
                            @if ($row['done'] !== null)
                                <p class="text-sm text-gray-500">—</p>
                            @elseif ($row['services'] === [])
                                <p class="text-sm text-gray-500">{{ $aiRunning ? 'AI’a soruldu' : 'Öneri yok' }}</p>
                            @else
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ implode(', ', array_column($row['services'], 'name')) }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $row['services'][0]['reason'] }} <span>({{ \App\Models\AdCampaignService::SOURCES[$row['services'][0]['source']] ?? '' }})</span></p>
                            @endif
                        </div>
                        {{-- The decision. --}}
                        <div class="flex min-w-0 basis-full flex-wrap items-center gap-1.5 pl-7 lg:w-[24rem] lg:shrink-0 lg:basis-auto lg:justify-end lg:pl-0">
                            @if ($row['done'] !== null)
                                <x-ta.pill :tone="$row['service_state'] === 'excluded' ? 'info' : 'ok'">{{ $row['done'] }}</x-ta.pill>
                                <button type="button" wire:click="undo('{{ $row['id'] }}')" class="btn-quiet h-7! px-2! text-xs!">Geri al</button>
                            @else
                                @if ($row['services'] !== [])<button type="button" wire:click="approve('{{ $row['id'] }}')" class="btn">Onayla</button>@endif
                                <form wire:submit="choose('{{ $row['id'] }}')" class="flex min-w-0 items-center gap-1.5">
                                    <label class="sr-only" for="pick-{{ $row['id'] }}">Başka hizmet</label>
                                    <select id="pick-{{ $row['id'] }}" wire:model="pick.{{ $row['id'] }}" class="{{ $select }} w-40">
                                        <option value="">{{ $row['services'] === [] ? 'Hizmet seç…' : 'Başka hizmet…' }}</option>
                                        @foreach ($offerings as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach
                                    </select>
                                    <button type="submit" class="btn">Ata</button>
                                </form>
                                <button type="button" wire:click="exclude('{{ $row['id'] }}')" class="btn-quiet h-8 px-2!">Hizmet dışı</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
