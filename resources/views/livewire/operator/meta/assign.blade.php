@php
    $btn = 'inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-500 disabled:opacity-50';
    $currency = $account['currency'] ?? '';
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $aiRunning = ($aiState['status'] ?? null) === 'running';
@endphp

<div class="space-y-8">
    @include('livewire.demo.partials.flash')

    <nav class="break-words text-sm text-gray-500" aria-label="Konum">
        @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">{{ $brand->name }}</a> <span class="text-gray-300">/</span>@endif
        <a href="{{ route('operator.meta.overview', ['assetId' => $assetId]) }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">{{ $asset['name'] ?? 'Meta' }}</a> <span class="text-gray-300">/</span>
        <span class="text-gray-700 dark:text-gray-300">Eşleşmeyenleri ata</span>
    </nav>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="max-w-3xl space-y-1">
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Sistem her kampanyaya hizmeti önce reklamın gittiği sayfadan, sonra reklam metninden, sonra kampanya adından önerir. Onayladığın ya da değiştirdiğin hizmet kalır; sonraki veri çekişinde ezilmez. Reklam hesabına hiçbir şey yazılmaz.">Eşleşmeyenleri ata</h1>
            <p class="text-sm text-gray-500"><span class="tabular-nums">{{ $open }}</span> kampanya karar bekliyor. Reklam hesabına hiçbir şey yazılmaz.</p>
        </div>
    </div>

    @if ($rows !== [])
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ count($selected) }} seçili</span>
            <span class="hidden flex-1 sm:block"></span>
            @if ($withoutSuggestion > 0)
                <button type="button" wire:click="askAi" @disabled($aiRunning) class="{{ $btn }}">Önerisi olmayanları AI ile eşleştir ({{ $withoutSuggestion }})</button>
            @endif
            <button type="button" wire:click="excludeSelected" @disabled($selected === []) wire:confirm="Seçili kampanyalar hizmet dışı işaretlensin mi?" class="{{ $btn }}">Seçilenler hizmet dışı</button>
            <button type="button" wire:click="approveSelected" @disabled($selected === []) class="{{ $primary }}">Seçilenlerde öneriyi onayla</button>
        </div>
        @if ($aiState)
            <p @class(['text-xs', 'text-gray-500' => $aiRunning, 'text-success-700' => ($aiState['status'] ?? '') === 'ready', 'text-error-700' => ($aiState['status'] ?? '') === 'failed']) @if ($aiRunning) wire:poll.10s @endif>AI eşleştirme: {{ $aiState['message'] ?? '' }}</p>
        @endif
    @endif

    <section data-testid="meta-assign">
        @if ($rows === [])
            <x-ta.empty-state title="Karar bekleyen kampanya yok." message="Her kampanyanın hizmeti belli." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[70rem] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800">
                            <th class="w-10 py-2 pr-4 font-normal"><span class="sr-only">Seç</span></th>
                            <th class="px-3 py-2 font-normal">Kampanya</th><th class="px-3 py-2 font-normal">Örnek reklam metni</th><th class="px-3 py-2 font-normal">Gidilen sayfa</th><th class="px-3 py-2 font-normal">Önerilen hizmet</th><th class="px-3 py-2 font-normal">Karar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @foreach ($rows as $row)
                            <tr wire:key="assign-{{ $row['id'] }}" @class(['align-top', 'text-gray-500' => $row['done'] !== null])>
                                <td class="py-4 pr-4">
                                    @if ($row['done'] === null)
                                        <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" aria-label="{{ $row['name'] }} seç" class="h-4 w-4 rounded border-gray-300 text-brand-500">
                                    @endif
                                </td>
                                <td class="min-w-[14rem] px-3 py-4">
                                    <a href="{{ route('operator.meta.campaign', ['assetId' => $assetId, 'campaignId' => $row['id']]) }}" wire:navigate class="line-clamp-2 break-words font-medium text-gray-900 hover:underline dark:text-white" title="{{ $row['name'] }}">{{ $row['name'] }}</a>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $row['objective'] }}, {{ $money($row['spend']) }}, {{ ['live' => 'yayında', 'paused' => 'durmuş', 'ended' => 'bitti'][$row['status']] }}</p>
                                </td>
                                <td class="max-w-xs px-3 py-4 text-xs leading-relaxed text-gray-700 dark:text-gray-300">@if ($row['sample'] !== '')“{{ \Illuminate\Support\Str::limit($row['sample'], 160) }}”@else<span class="text-gray-500">Metin yok</span>@endif</td>
                                <td class="max-w-[14rem] break-all px-3 py-4 text-xs text-gray-700 dark:text-gray-300">{{ $row['landing'] }}</td>
                                <td class="min-w-[13rem] px-3 py-4">
                                    @if ($row['done'] !== null)
                                        <span class="text-xs text-gray-500">—</span>
                                    @elseif ($row['services'] === [])
                                        <span class="text-xs text-gray-500">{{ $aiRunning ? 'AI’a soruldu' : 'Öneri yok' }}</span>
                                    @else
                                        <p class="text-sm text-gray-900 dark:text-white">{{ implode(', ', array_column($row['services'], 'name')) }}</p>
                                        <p class="mt-0.5 text-xs text-gray-500">{{ $row['services'][0]['reason'] }} <span>({{ \App\Models\AdCampaignService::SOURCES[$row['services'][0]['source']] ?? '' }})</span></p>
                                    @endif
                                </td>
                                <td class="min-w-[19rem] px-3 py-4">
                                    @if ($row['done'] !== null)
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ta.pill :tone="$row['service_state'] === 'excluded' ? 'info' : 'ok'" class="text-sm">{{ $row['done'] }}</x-ta.pill>
                                            <button type="button" wire:click="undo('{{ $row['id'] }}')" class="text-xs font-medium text-gray-500 hover:text-gray-800 hover:underline">Geri al</button>
                                        </div>
                                    @else
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($row['services'] !== [])<button type="button" wire:click="approve('{{ $row['id'] }}')" class="{{ $btn }}">Onayla</button>@endif
                                            <form wire:submit="choose('{{ $row['id'] }}')" class="flex items-center gap-1">
                                                <label class="sr-only" for="pick-{{ $row['id'] }}">Başka hizmet</label>
                                                <select id="pick-{{ $row['id'] }}" wire:model="pick.{{ $row['id'] }}" class="rounded-lg border-gray-200 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                                    <option value="">{{ $row['services'] === [] ? 'Hizmet seç…' : 'Başka hizmet…' }}</option>
                                                    @foreach ($offerings as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach
                                                </select>
                                                <button type="submit" class="{{ $btn }}">Ata</button>
                                            </form>
                                            <button type="button" wire:click="exclude('{{ $row['id'] }}')" class="text-xs font-medium text-gray-500 hover:text-gray-800 hover:underline">Hizmet dışı</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
