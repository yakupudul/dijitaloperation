@php
    $chip = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium';
    $btn = 'inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $currency = $account['currency'] ?? '';
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $aiRunning = ($aiState['status'] ?? null) === 'running';
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <nav class="text-sm text-gray-500" aria-label="Konum">
        @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="hover:text-brand-600">{{ $brand->name }}</a> <span class="text-gray-300">/</span>@endif
        <a href="{{ route('operator.meta.overview', ['assetId' => $assetId]) }}" wire:navigate class="hover:text-brand-600">{{ $asset['name'] ?? 'Meta' }}</a> <span class="text-gray-300">/</span>
        <span class="text-gray-700 dark:text-gray-300">Eşleşmeyenleri ata</span>
    </nav>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="max-w-3xl space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Eşleşmeyenleri ata</h1>
            <p class="text-sm leading-relaxed text-gray-500">Sistem her kampanyaya hizmeti önce reklamın gittiği sayfadan, sonra reklam metninden, sonra kampanya adından önerir. Onayladığın ya da değiştirdiğin hizmet kalır; sonraki veri çekişinde ezilmez. Reklam hesabına hiçbir şey yazılmaz.</p>
        </div>
        <p class="text-sm text-gray-500"><span class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $open }}</span> kampanya karar bekliyor</p>
    </div>

    @if ($rows !== [])
        <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <span class="text-sm text-gray-600 dark:text-gray-300">{{ count($selected) }} seçili</span>
            <span class="flex-1"></span>
            @if ($withoutSuggestion > 0)
                <button type="button" wire:click="askAi" @disabled($aiRunning) class="{{ $btn }}">Önerisi olmayanları AI ile eşleştir ({{ $withoutSuggestion }})</button>
            @endif
            <button type="button" wire:click="excludeSelected" @disabled($selected === []) wire:confirm="Seçili kampanyalar hizmet dışı işaretlensin mi?" class="{{ $btn }}">Seçilenler hizmet dışı</button>
            <button type="button" wire:click="approveSelected" @disabled($selected === []) class="{{ $primary }}">Seçilenlerde öneriyi onayla</button>
        </div>
        @if ($aiState)
            <p @class(['text-xs', 'text-gray-500' => $aiRunning, 'text-emerald-600' => ($aiState['status'] ?? '') === 'ready', 'text-rose-600' => ($aiState['status'] ?? '') === 'failed']) @if ($aiRunning) wire:poll.10s @endif>AI eşleştirme: {{ $aiState['message'] ?? '' }}</p>
        @endif
    @endif

    <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" data-testid="meta-assign">
        @if ($rows === [])
            <p class="px-5 py-10 text-center text-sm text-gray-500">Her kampanyanın hizmeti belli. Karar bekleyen kampanya yok.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[70rem] text-sm">
                    <thead class="bg-gray-50 dark:bg-white/[0.02]">
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                            <th class="w-10 px-4 py-2.5"><span class="sr-only">Seç</span></th>
                            <th class="px-3 py-2.5">Kampanya</th><th class="px-3 py-2.5">Örnek reklam metni</th><th class="px-3 py-2.5">Gidilen sayfa</th><th class="px-3 py-2.5">Önerilen hizmet</th><th class="px-3 py-2.5">Karar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr wire:key="assign-{{ $row['id'] }}" @class(['align-top', 'bg-gray-50/60 dark:bg-white/[0.02]' => $row['done'] !== null])>
                                <td class="px-4 py-4">
                                    @if ($row['done'] === null)
                                        <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" aria-label="{{ $row['name'] }} seç" class="h-4 w-4 rounded border-gray-300 text-brand-500">
                                    @endif
                                </td>
                                <td class="min-w-[14rem] px-3 py-4">
                                    <a href="{{ route('operator.meta.campaign', ['assetId' => $assetId, 'campaignId' => $row['id']]) }}" wire:navigate class="font-semibold text-gray-900 hover:text-brand-600 dark:text-white">{{ $row['name'] }}</a>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $row['objective'] }} · {{ $money($row['spend']) }} · {{ ['live' => 'yayında', 'paused' => 'durmuş', 'ended' => 'bitti'][$row['status']] }}</p>
                                </td>
                                <td class="max-w-xs px-3 py-4 text-[13px] leading-relaxed text-gray-700 dark:text-gray-300">@if ($row['sample'] !== '')“{{ \Illuminate\Support\Str::limit($row['sample'], 160) }}”@else<span class="text-gray-400">Metin yok</span>@endif</td>
                                <td class="px-3 py-4 text-[13px] text-brand-700 dark:text-brand-300">{{ $row['landing'] }}</td>
                                <td class="min-w-[13rem] px-3 py-4">
                                    @if ($row['done'] !== null)
                                        <span class="text-xs text-gray-400">—</span>
                                    @elseif ($row['services'] === [])
                                        <span class="text-xs text-gray-500">{{ $aiRunning ? 'AI’a soruldu' : 'Öneri yok' }}</span>
                                    @else
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($row['services'] as $s)<span class="{{ $chip }} border border-dashed border-gray-400 text-gray-700 dark:text-gray-300">{{ $s['name'] }}</span>@endforeach
                                        </div>
                                        <p class="mt-1.5 text-xs text-gray-500">{{ $row['services'][0]['reason'] }} <span class="text-gray-400">({{ \App\Models\AdCampaignService::SOURCES[$row['services'][0]['source']] ?? '' }})</span></p>
                                    @endif
                                </td>
                                <td class="min-w-[19rem] px-3 py-4">
                                    @if ($row['done'] !== null)
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="{{ $chip }} {{ $row['service_state'] === 'excluded' ? 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300' : 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' }}">{{ $row['done'] }}</span>
                                            <button type="button" wire:click="undo('{{ $row['id'] }}')" class="text-xs font-semibold text-gray-500 hover:text-gray-800 hover:underline">Geri al</button>
                                        </div>
                                    @else
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($row['services'] !== [])<button type="button" wire:click="approve('{{ $row['id'] }}')" class="{{ $primary }}">Onayla</button>@endif
                                            <form wire:submit="choose('{{ $row['id'] }}')" class="flex items-center gap-1">
                                                <label class="sr-only" for="pick-{{ $row['id'] }}">Başka hizmet</label>
                                                <select id="pick-{{ $row['id'] }}" wire:model="pick.{{ $row['id'] }}" class="rounded-lg border-gray-300 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
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
