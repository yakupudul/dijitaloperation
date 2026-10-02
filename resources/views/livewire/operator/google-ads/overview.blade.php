@php
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $cur = $numbers['currency'] ?? ($settings['currency'] ?? '');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.');
    $pct = fn ($now, $before) => ($now === null || $before === null || (float) $before == 0.0) ? null : (int) round(((float) $now - (float) $before) / (float) $before * 100);
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $btn = 'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $small = 'rounded px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $stateLine = fn (?array $state): ?array => match ($state['status'] ?? null) {
        'running' => ['text-gray-500', 'Çalışıyor…', true],
        'failed' => ['text-rose-600', (string) ($state['message'] ?? 'Başarısız.'), false],
        'ready' => ['text-emerald-600', (string) ($state['message'] ?? 'Hazır.'), false],
        default => null,
    };
    $checkBadge = ['pass' => ['Geçti', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'], 'fail' => ['Sorun', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'], 'nodata' => ['Veri yok', 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400']];
    $matchLabel = fn (string $m) => \App\Services\GoogleAds\GoogleAdsAssistant::matchLabel($m);
    $scopeLabel = fn (array $a) => ['shared' => 'Paylaşılan liste', 'campaign' => 'Kampanya: '.($a['campaign'] ?? ''), 'ad_group' => 'Reklam grubu: '.($a['ad_group'] ?? '')][$a['scope'] ?? ''] ?? '';
    $fitLabel = ['uygun' => ['Uygun', 'text-emerald-700 dark:text-emerald-300'], 'kismen' => ['Kısmen', 'text-amber-700 dark:text-amber-300'], 'uygunsuz' => ['Uygunsuz', 'text-rose-700 dark:text-rose-300']];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" />

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-center gap-3">
            <x-demo.digital-asset-mark type="google_ads" size="lg" />
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ $asset['name'] ?? 'Google Ads' }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Google Ads{{ $assetModel->brand ? ' · '.$assetModel->brand->name : '' }}@if ($numbers && $numbers['last_date']) · Son veri: {{ $numbers['last_date'] }}@endif</p>
            </div>
        </div>
        <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $primary }}">Verileri yenile</button>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Google Ads">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
            ])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="ml-1 rounded-full bg-brand-50 px-1.5 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $openCount }}</span>@endif</button>
        @endforeach
    </nav>

    @if (! $bound)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-950/20">
            <p class="font-semibold text-amber-900 dark:text-amber-200">Google Ads hesabı bağlı değil</p>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="mt-2 inline-flex rounded-lg bg-amber-600 px-3 py-1.5 font-semibold text-white">Bağla</a>
        </section>
    @endif

    @if ($tab === 'overview')
        @if ($numbers)
            @php $c = $numbers['current']; $p = $numbers['previous']; @endphp
            <div class="flex items-center gap-2 text-sm">
                @foreach ($dayOptions as $option)
                    <button type="button" wire:click="setDays({{ $option }})" @class(['rounded-lg px-2.5 py-1', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $days === $option, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $days !== $option])>{{ $option }} gün</button>
                @endforeach
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="ads-numbers">
                @foreach ([['Maliyet', 'cost', true, false], ['Tıklama', 'clicks', false, false], ['Dönüşüm', 'conversions', false, false], ['Dönüşüm başı maliyet', 'cpa', true, true]] as [$label, $key, $isMoney, $lowerBetter])
                    @php $change = $pct($c[$key], $p[$key]); @endphp
                    <section class="{{ $card }}">
                        <p class="text-xs text-gray-500">{{ $label }} · {{ $days }} gün</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isMoney ? $money($c[$key]) : $num($c[$key], $key === 'conversions' ? 1 : 0) }}@if ($isMoney && $c[$key] !== null) <span class="text-sm font-medium text-gray-500">{{ $cur }}</span>@endif</p>
                        <p @class(['text-xs', 'text-gray-400' => $change === null, 'text-emerald-600' => $change !== null && (($change >= 0) xor $lowerBetter), 'text-rose-600' => $change !== null && ! (($change >= 0) xor $lowerBetter)])>{{ $c[$key] === null ? 'veri yok' : ($change === null ? 'önceki dönem yok' : (($change > 0 ? '+' : '').$change.'% önceki döneme göre')) }}</p>
                    </section>
                @endforeach
            </div>
        @endif
        <section class="{{ $panel }}" data-testid="ads-checks">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Sistem kontrolleri</h2>
                <button type="button" wire:click="recheck" @disabled(! $bound) class="{{ $small }}">Yeniden kontrol et</button>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($checks['items'] ?? [] as $check)
                    <div class="flex items-start gap-3 px-4 py-2.5 text-sm">
                        <span class="w-44 shrink-0 font-medium text-gray-900 dark:text-white">{{ $check['label'] }}</span>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $checkBadge[$check['state']][1] }}">{{ $checkBadge[$check['state']][0] }}</span>
                        <span class="min-w-0 text-gray-600 dark:text-gray-300">{{ $check['reason'] }}</span>
                    </div>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-500">Kontroller henüz çalışmadı.</p>
                @endforelse
            </div>
        </section>
        @if ($openCount > 0)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $openCount }} açık öneri · <button type="button" wire:click="setTab('todo')" class="font-semibold text-brand-600 hover:underline">Yapılacaklar</button></p>
        @endif

    @elseif ($tab === 'todo')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="recheck" @disabled(! $bound) class="{{ $btn }}">Kontrolleri çalıştır</button>
            <button type="button" wire:click="reviewTerms" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($termsState['status'] ?? null) === 'running') class="{{ $btn }}">Arama terimlerini incele</button>
            <x-operator.ai-prompt-info operation="google_ads.search_terms" />
            @if ($canWrite && $selectedNegatives !== [])
                <button type="button" x-on:click="if (confirm('Seçilen negatifler Google Ads paylaşılan listesine eklensin mi? Sonradan geri alınabilir.')) $wire.sendNegatives()" class="{{ $primary }}">Seçilenleri gönder ({{ count($selectedNegatives) }})</button>
            @endif
            @unless ($operational)<span class="text-xs text-gray-500">Marka operasyonel değil; AI kapalı.</span>@endunless
        </div>
        @if ($line = $stateLine($termsState))
            <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>Arama terimleri: {{ $line[1] }}</p>
        @endif

        <section class="{{ $panel }} divide-y divide-gray-100 dark:divide-gray-700" data-testid="ads-suggestions">
            @forelse ($suggestions as $s)
                @include('livewire.operator.google-ads.partials.suggestion', ['s' => $s])
            @empty
                <p class="px-4 py-5 text-sm text-gray-500">Açık öneri yok.</p>
            @endforelse
        </section>

        @include('livewire.operator.google-ads.partials.approved')

        @if ($writes->isNotEmpty())
            <section class="{{ $panel }}">
                <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Paylaşılan negatif listesi gönderimleri</h2>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($writes as $write)
                        <div class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm" wire:key="write-{{ $write->id }}">
                            <span class="text-gray-500">{{ $write->created_at?->format('d.m.Y H:i') }}</span>
                            <span class="text-gray-800 dark:text-gray-200">{{ count((array) ($write->request_payload['keywords'] ?? [])) }} terim</span>
                            <span @class(['text-xs font-semibold', 'text-emerald-600' => in_array($write->status, ['succeeded', 'partial'], true), 'text-rose-600' => in_array($write->status, ['failed', 'undo_failed'], true), 'text-gray-500' => ! in_array($write->status, ['succeeded', 'partial', 'failed', 'undo_failed'], true)])
                                @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>{{ ['queued' => 'Sırada', 'running' => 'Gönderiliyor', 'succeeded' => 'Gönderildi', 'partial' => 'Kısmen gönderildi', 'failed' => 'Başarısız', 'undoing' => 'Geri alınıyor', 'undone' => 'Geri alındı', 'undo_failed' => 'Geri alınamadı'][$write->status] ?? $write->status }}</span>
                            @if ($write->error)<span class="text-xs text-rose-600">{{ $write->error }}</span>@endif
                            @if ($canWrite && $write->isUndoable())
                                <button type="button" x-on:click="if (confirm('Bu gönderim geri alınsın mı?')) $wire.undoWrite({{ $write->id }})" class="ml-auto text-xs font-medium text-rose-600 hover:underline">Geri al</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

    @elseif ($tab === 'terms')
        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="termFilter" placeholder="Terim ara" aria-label="Terim ara" class="{{ $input }} w-56">
            <button type="button" wire:click="reviewTerms" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($termsState['status'] ?? null) === 'running') class="{{ $btn }}">Tümünü incele</button>
            <button type="button" wire:click="proposeNegatives" wire:loading.attr="disabled" @disabled(! $operational || $selectedTerms === [] || ($termsState['status'] ?? null) === 'running') class="{{ $primary }}">Negatif öner{{ $selectedTerms !== [] ? ' ('.count($selectedTerms).')' : '' }}</button>
            <x-operator.ai-prompt-info operation="google_ads.search_terms" />
        </div>
        @if ($line = $stateLine($termsState))
            <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>Arama terimleri: {{ $line[1] }}@if (($termsState['status'] ?? null) === 'ready') · <button type="button" wire:click="setTab('todo')" class="font-semibold text-brand-600 hover:underline">Yapılacaklar</button>@endif</p>
        @endif
        <section class="{{ $panel }} overflow-x-auto" data-testid="ads-terms">
            @if ($terms === [])
                <p class="px-4 py-5 text-sm text-gray-500">Arama terimi verisi yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500">
                        <th class="px-3 py-2"></th><th class="px-3 py-2 font-medium">Terim</th><th class="px-3 py-2 font-medium">Kampanya</th>
                        <th class="px-3 py-2 text-right font-medium">Maliyet</th><th class="px-3 py-2 text-right font-medium">Tık</th><th class="px-3 py-2 text-right font-medium">Dönüşüm</th>
                        <th class="px-3 py-2 font-medium">Hizmet</th><th class="px-3 py-2 font-medium">AI</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($terms as $t)
                            <tr wire:key="term-{{ md5($t['term']) }}">
                                <td class="px-3 py-1.5"><input type="checkbox" wire:model.live="selectedTerms" value="{{ $t['term'] }}" aria-label="Seç" class="rounded border-gray-300"></td>
                                <td class="px-3 py-1.5 text-gray-900 dark:text-white">{{ $t['term'] }}</td>
                                <td class="px-3 py-1.5 text-xs text-gray-500">{{ implode(', ', $t['campaigns']) ?: '—' }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $money($t['cost']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($t['clicks']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($t['conversions'], 1) }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ $t['service'] ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-xs">
                                    @if ($t['verdict'])
                                        <span class="font-semibold {{ $fitLabel[$t['verdict']['fit']][1] ?? '' }}" title="{{ $t['verdict']['reason'] }}">{{ $fitLabel[$t['verdict']['fit']][0] ?? $t['verdict']['fit'] }}</span>
                                        <span class="text-gray-500">· {{ $t['verdict']['intent'] }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

    @elseif ($tab === 'strategy')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="proposeStructure" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($structureState['status'] ?? null) === 'running') class="{{ $btn }}">Kampanya yapısı öner</button>
            <x-operator.ai-prompt-info operation="google_ads.structure" />
            <select wire:model="adGroupKey" aria-label="Reklam grubu" class="{{ $input }} max-w-xs">
                <option value="">Reklam grubu seçin</option>
                @foreach ($adGroups as $option)
                    <option value="{{ $option['key'] }}">{{ \Illuminate\Support\Str::limit($option['label'], 80) }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="writeAds" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($adsState['status'] ?? null) === 'running') class="{{ $btn }}">Reklam metni yaz</button>
            <x-operator.ai-prompt-info operation="google_ads.ad_texts" />
        </div>
        @foreach (['Kampanya yapısı' => $structureState, 'Reklam metni' => $adsState] as $label => $state)
            @if ($line = $stateLine($state))
                <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>{{ $label }}: {{ $line[1] }}</p>
            @endif
        @endforeach
        <section class="{{ $panel }} divide-y divide-gray-100 dark:divide-gray-700" data-testid="ads-strategy">
            @forelse ($strategy as $s)
                @include('livewire.operator.google-ads.partials.suggestion', ['s' => $s])
            @empty
                <p class="px-4 py-5 text-sm text-gray-500">Taslak yok.</p>
            @endforelse
        </section>
        @include('livewire.operator.google-ads.partials.approved')

    @elseif ($tab === 'measurement')
        <section class="{{ $panel }} overflow-x-auto" data-testid="ads-conversions">
            <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Dönüşüm işlemleri</h2>
            @if ($conversionActions === [])
                <p class="px-4 py-5 text-sm text-gray-500">Dönüşüm işlemi verisi yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Dönüşüm</th><th class="px-3 py-2 font-medium">Hedef</th><th class="px-3 py-2 font-medium">Durum</th><th class="px-3 py-2 text-right font-medium">30 gün</th><th class="px-3 py-2 font-medium">Son dönüşüm</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($conversionActions as $action)
                            <tr>
                                <td class="px-4 py-1.5 text-gray-900 dark:text-white">{{ $action['name'] }} <span class="text-xs text-gray-400">{{ $action['category'] }}</span></td>
                                <td class="px-3 py-1.5">{{ $action['primary'] ? 'Birincil' : 'İkincil' }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ ['ENABLED' => 'Etkin', 'REMOVED' => 'Kaldırıldı', 'HIDDEN' => 'Gizli'][$action['status']] ?? ($action['status'] ?? 'veri yok') }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($action['conversions'], 1) }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ $action['last_conversion'] ?? 'yok' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
        <section class="{{ $panel }} overflow-x-auto" data-testid="ads-lead-quality">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Lead kalitesi (elle)</h2>
                <select wire:change="setLeadMonth($event.target.value)" aria-label="Ay" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                    @foreach ($leadMonths as $month)
                        <option value="{{ $month }}" @selected($month === $leadMonthValue)>{{ $month }}</option>
                    @endforeach
                </select>
            </div>
            @if ($leadQuality === [])
                <p class="px-4 py-5 text-sm text-gray-500">Bu ay kampanya verisi yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Kampanya</th><th class="px-3 py-2 text-right font-medium">Google Ads dönüşüm</th>
                        @foreach ($leadFields as $label)<th class="px-3 py-2 font-medium">{{ $label }}</th>@endforeach<th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($leadQuality as $row)
                            <tr wire:key="lead-{{ $row['campaign_id'] }}">
                                <td class="px-4 py-1.5 text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $row['ads_conversions'] === null ? 'veri yok' : $num($row['ads_conversions'], 1) }}</td>
                                @foreach ($leadFields as $field => $label)
                                    <td class="px-3 py-1.5"><input type="number" min="0" wire:model="leadRows.{{ $row['campaign_id'] }}.{{ $field }}" aria-label="{{ $label }}" class="w-20 rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                @endforeach
                                <td class="px-3 py-1.5"><button type="button" wire:click="saveLeadQuality('{{ $row['campaign_id'] }}')" class="rounded bg-brand-500 px-2 py-1 text-xs font-semibold text-white hover:bg-brand-600">Kaydet</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
        <section class="{{ $panel }}">
            <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">İzleme kontrolleri</h2>
            @php $tracking = collect($checks['items'] ?? [])->whereIn('id', ['conversion_tracking', 'conversion_goals', 'landing']); @endphp
            @forelse ($tracking as $check)
                <div class="flex items-start gap-3 px-4 py-2.5 text-sm">
                    <span class="w-44 shrink-0 font-medium text-gray-900 dark:text-white">{{ $check['label'] }}</span>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $checkBadge[$check['state']][1] }}">{{ $checkBadge[$check['state']][0] }}</span>
                    <span class="text-gray-600 dark:text-gray-300">{{ $check['reason'] }}</span>
                </div>
            @empty
                <p class="px-4 py-4 text-sm text-gray-500">Kontroller henüz çalışmadı.</p>
            @endforelse
        </section>

    @elseif ($tab === 'analysis')
        <div class="flex flex-wrap items-center gap-2 text-sm">
            @foreach ($levels as $key => $label)
                <button type="button" wire:click="setLevel('{{ $key }}')" @class(['rounded-lg px-2.5 py-1', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $level === $key, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $level !== $key])>{{ $label }}</button>
            @endforeach
            <span class="mx-1 text-gray-300">|</span>
            @foreach ($dayOptions as $option)
                <button type="button" wire:click="setDays({{ $option }})" @class(['rounded-lg px-2.5 py-1', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $days === $option, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $days !== $option])>{{ $option }} gün</button>
            @endforeach
        </div>
        <section class="{{ $panel }} overflow-x-auto" data-testid="ads-analysis">
            @if ($analysis === [])
                <p class="px-4 py-5 text-sm text-gray-500">Veri yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">{{ $levels[$level] }}</th><th class="px-3 py-2 text-right font-medium">Maliyet</th><th class="px-3 py-2 text-right font-medium">Önceki</th><th class="px-3 py-2 text-right font-medium">Tık</th><th class="px-3 py-2 text-right font-medium">Dönüşüm</th><th class="px-3 py-2 text-right font-medium">Önceki</th><th class="px-3 py-2 text-right font-medium">Dönüşüm başı</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($analysis as $row)
                            <tr>
                                <td class="px-4 py-1.5 text-gray-900 dark:text-white">{{ $row['label'] }}@if ($row['sub']) <span class="block text-xs text-gray-400">{{ $row['sub'] }}</span>@endif</td>
                                <td class="px-3 py-1.5 text-right">{{ $money($row['cost']) }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-500">{{ $money($row['prev_cost']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($row['clicks']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($row['conversions'], 1) }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-500">{{ $num($row['prev_conversions'], 1) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $money($row['cpa']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

    @elseif ($tab === 'settings')
        <section class="{{ $panel }} divide-y divide-gray-100 text-sm dark:divide-gray-700" data-testid="ads-settings">
            @foreach ([
                'Bağlı hesap' => $settings['customer_id'] ?? 'bağlı değil',
                'Para birimi' => $settings['currency'] ?? 'veri yok',
                'Saat dilimi' => $settings['timezone'] ?? 'veri yok',
                'Son toplama' => $settings['last_collected'] ?? 'veri yok',
                'Hedef bölgeler' => collect($settings['areas'] ?? [])->map(fn ($a) => $a['name'].($a['physical_branch'] ? ' (şube)' : ''))->implode(', ') ?: 'veri yok',
                'Diller' => implode(', ', $settings['languages'] ?? []) ?: 'veri yok',
            ] as $label => $value)
                <div class="flex gap-3 px-4 py-2.5"><span class="w-40 shrink-0 text-gray-500">{{ $label }}</span><span class="text-gray-900 dark:text-white">{{ $value }}</span></div>
            @endforeach
            <div class="flex flex-wrap gap-3 px-4 py-3">
                <a href="{{ route('operator.integrations.google-ads.connector') }}" wire:navigate class="font-medium text-brand-600 hover:underline">Google Ads entegrasyonu</a>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="font-medium text-gray-600 hover:underline dark:text-gray-300">Hesap bağlantısı</a>
                @if ($assetModel->brand)
                    <a href="{{ route('operator.brand', ['brand' => $assetModel->brand->id]) }}" wire:navigate class="font-medium text-gray-600 hover:underline dark:text-gray-300">Marka (bölge, dil, hizmet)</a>
                @endif
            </div>
        </section>
    @endif
</div>
