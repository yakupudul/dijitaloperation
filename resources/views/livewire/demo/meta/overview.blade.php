@php
    $currency = $account['currency'] ?? '';
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $pct = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 1, ',', '.').'%');
    /** Sade panel: sections are flat (heading + content); partials still receive $card / $panel as plain wrappers. */
    $card = 'min-w-0';
    $panel = 'min-w-0';
    // Page title: the ad account's own name when Meta gave one; the account id is only a small gray secondary.
    $accountName = trim((string) ($settings['account_name'] ?? ''));
    $actId = $account !== null ? (string) ($account['act_id'] ?? '') : '';
    $pageTitle = $accountName !== '' ? $accountName : (string) ($asset['name'] ?? 'Meta');
    if ($actId !== '' && $accountName === '') {
        $bareId = preg_replace('/^act_/', '', $actId);
        $pageTitle = trim((string) preg_replace('/\s*[·\-|]\s*(act_)?'.preg_quote($bareId, '/').'\s*$/u', '', $pageTitle)) ?: $pageTitle;
    }
    /** Panel concept: white bordered secondary, near-black primary. */
    $btn = 'btn';
    $primary = 'btn-primary';
    $th = 'px-3 py-2 text-right font-normal';
    $td = 'px-3 py-1.5 text-right';
    $bound = $account !== null;
    $aiButton = function (string $op) use ($aiStates, $aiLabels, $operational, $bound, $btn): string {
        $running = ($aiStates[$op]['status'] ?? null) === 'running';

        return '<button type="button" wire:click="runAi(\''.$op.'\')" wire:loading.attr="disabled" '.(! $operational || ! $bound || $running ? 'disabled ' : '').'class="'.$btn.'">'.e($aiLabels[$op]).'</button>'.\Illuminate\Support\Facades\Blade::render('<x-operator.ai-prompt-info :operation="$operation" />', ['operation' => 'meta.'.$op]);
    };
    $aiLine = function (string $op) use ($aiStates, $aiLabels): string {
        $state = $aiStates[$op] ?? null;
        [$class, $text, $poll] = match ($state['status'] ?? null) {
            'running' => ['text-gray-500', 'Çalışıyor…', true],
            'failed' => ['text-error-700 dark:text-error-400', (string) ($state['message'] ?? 'Başarısız.'), false],
            'ready' => ['text-success-700 dark:text-success-400', (string) ($state['message'] ?? 'Hazır.'), false],
            default => [null, null, false],
        };

        return $class === null ? '' : '<p class="text-[13px] '.$class.'"'.($poll ? ' wire:poll.5s' : '').'>'.e($aiLabels[$op]).': '.e($text).'</p>';
    };
    $stateBadge = [
        'issue' => ['Sorun', 'critical'],
        'ok' => ['Tamam', 'ok'],
        'no_data' => ['veri yok', 'info'],
        'low_data' => ['veri az', 'look'],
    ];
    /** Checks: problems first. */
    $checkRows = fn (array $items) => collect($items)->sortBy(fn (array $c): int => ['issue' => 0, 'low_data' => 1, 'no_data' => 2, 'ok' => 3][$c['state']] ?? 4);
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="$pageTitle">
        <x-slot:mark><x-demo.digital-asset-mark type="meta_ads" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>{{ 'Meta reklamları'.($brand ? '' : ', marka yok') }}@if ($bound), {{ $currency }} <span class="break-all text-xs text-gray-500">{{ $actId }}</span>@else, hesap bağlı değil @endif</x-slot:meta>
        <x-slot:actions>
            @if ($bound && $brand)
                <a href="{{ route('operator.meta.assign', ['assetId' => $assetId]) }}" wire:navigate class="btn h-9! w-full sm:w-auto">Eşleşmeyenleri ata @if (($board['open'] ?? 0) > 0)<span class="text-xs font-normal tabular-nums text-gray-500">{{ $board['open'] }}</span>@endif</a>
            @endif
            @if ($ranged)
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Meta verisi her gün toplanır." class="w-full sm:w-auto" />
            @endif
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="btn h-9! w-full sm:w-auto">Verileri yenile</button>
        </x-slot:actions>
        <x-slot:tabs>
            <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Meta">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif @class(['tab', 'tab-active' => $tab === $key])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="text-[13px] font-normal tabular-nums text-gray-500">{{ $openCount }}</span>@endif</button>
                @endforeach
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @unless ($bound)
        <div class="card">
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Reklam hesabı bağlı değil</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">Hesap bağlanınca kampanyalar, sonuçlar ve kontroller burada görünür.</p>
                </div>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="{{ $primary }} w-full sm:w-auto">Bağla</a>
            </div>
        </div>
    @endunless

    @if ($tab === 'campaigns')
        @include('livewire.demo.meta.partials.campaigns')

    @elseif ($tab === 'todo')
        <section data-testid="meta-checks">
            <div class="section-head">
                <h2 class="section-title">Kontroller @if ($checks)<span class="section-count">{{ collect($checks['checks'])->where('state', 'issue')->count() }} sorun</span>@endif</h2>
                @if ($checks)<span class="text-[13px] tabular-nums text-gray-500">{{ \Carbon\CarbonImmutable::parse($checks['at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>@endif
                <button type="button" wire:click="recheck" @disabled(! $bound) class="btn-quiet sm:ml-auto">Yeniden kontrol et</button>
            </div>
            <div class="card">
                @if ($checks === null)
                    <x-ta.empty-state icon="none" size="sm" title="Henüz kontrol edilmedi." />
                @else
                    @foreach ($checkRows($checks['checks']) as $check)
                        <div class="card-row flex-wrap items-start gap-y-1 sm:flex-nowrap">
                            <span class="min-w-0 text-sm font-medium text-gray-900 sm:w-52 sm:shrink-0 dark:text-gray-200">{{ $check['label'] }}</span>
                            <x-ta.pill :tone="$stateBadge[$check['state']][1] ?? 'info'" class="shrink-0 sm:mt-0.5 sm:w-20">{{ $stateBadge[$check['state']][0] ?? $check['state'] }}</x-ta.pill>
                            <span class="min-w-0 basis-full break-words text-[13px] text-gray-500 sm:flex-1 sm:basis-auto dark:text-gray-400">{{ $check['detail'] }}</span>
                        </div>
                    @endforeach
                @endif
            </div>
        </section>

        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center">{!! $aiButton('creatives') !!}</span>
                <span class="inline-flex items-center">{!! $aiButton('structure') !!}</span>
                <span class="inline-flex items-center">{!! $aiButton('landing') !!}</span>
                @unless ($operational)<span class="text-xs text-gray-500">Marka operasyonel değil; AI kapalı.</span>@endunless
            </div>
            {!! $aiLine('creatives') !!}{!! $aiLine('structure') !!}{!! $aiLine('landing') !!}
            @include('livewire.demo.meta.partials.suggestions', ['items' => $suggestions])
        </div>

        <section data-testid="meta-approved">
            <div class="section-head">
                <h2 class="section-title">Uygulanacaklar <span class="section-count">{{ $approved->count() }}</span></h2>
                @if ($approved->isNotEmpty())<button type="button" wire:click="exportCsv" class="{{ $btn }} sm:ml-auto">CSV indir</button>@endif
            </div>
            <div class="card">
                @forelse ($approved as $s)
                    <div class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="approved-{{ $s->id }}" x-data="{ text: @js((string) ($s->action['text'] ?? $s->title)) }">
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}</p>
                            <p class="mt-0.5 whitespace-pre-line break-words text-[13px] text-gray-500 dark:text-gray-400">{{ $s->action['text'] ?? '' }}</p>
                        </div>
                        <span class="flex shrink-0 items-center gap-1">
                            <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="btn-quiet h-7! px-2! text-xs!">Kopyala</button>
                            <button type="button" wire:click="markApplied({{ $s->id }})" class="btn h-7! px-2.5! text-xs!">Uygulandı</button>
                        </span>
                    </div>
                @empty
                    <x-ta.empty-state icon="none" size="sm" title="Onaylanan öneri yok." />
                @endforelse
            </div>
        </section>

    @elseif ($tab === 'measurement')
        @php $m = $measurement; @endphp
        @if (! ($m['bound'] ?? false))
            <div class="card"><x-ta.empty-state icon="none" size="sm" title="Veri yok." /></div>
        @else
            <section data-testid="meta-results">
                <div class="section-head">
                    <h2 class="section-title">{{ $range->label() }}</h2>
                    <span class="text-[13px] text-gray-500">Üç kaynak ayrı sayar; toplanmaz.</span>
                </div>
                <div class="metric-strip grid-cols-1 sm:grid-cols-3">
                    <x-ta.metric-card label="Meta sonuçları" :value="$num($m['meta']['results'], 1)" :hint="'Lead '.$num($m['meta']['leads'], 1).', mesaj '.$num($m['meta']['messages'], 1).', satış '.$num($m['meta']['purchases'], 1)" />
                    <x-ta.metric-card label="GA4, Meta kaynaklı" :value="$m['ga4']['has_data'] ? $num($m['ga4']['key_events'], 1) : '—'" :muted="! $m['ga4']['has_data']" :hint="$m['ga4']['has_data'] ? 'Anahtar etkinlik, '.$num($m['ga4']['sessions']).' oturum' : 'veri yok'" />
                    <x-ta.metric-card label="CRM (lead işaretleri)" :value="$m['crm']['total'] > 0 ? $num($m['crm']['marks']['randevu'] + $m['crm']['marks']['satis']) : '—'" :muted="$m['crm']['total'] === 0" :hint="$m['crm']['total'] > 0 ? 'Randevu + satış; '.$m['crm']['total'].' lead, '.$m['crm']['marked'].' işaretli' : 'veri yok'" />
                </div>
            </section>

            <div class="grid gap-10 xl:grid-cols-2">
                <section class="min-w-0" data-testid="meta-attribution">
                    <div class="section-head"><h2 class="section-title">İlişkilendirme ayarı</h2></div>
                    <ul class="card">
                        @forelse ($m['attribution'] as $label => $row)
                            <li class="card-row justify-between text-sm"><span class="min-w-0 font-medium text-gray-900 dark:text-gray-200">{{ $label }}</span><span class="shrink-0 text-[13px] text-gray-500">{{ $row['count'] }} reklam seti</span></li>
                        @empty
                            <li class="card-row text-sm text-gray-500">veri yok</li>
                        @endforelse
                    </ul>
                </section>
                <section class="min-w-0" data-testid="meta-pixel">
                    <div class="section-head"><h2 class="section-title">Pixel / CAPI</h2></div>
                    <ul class="card">
                        <li class="card-row text-sm text-gray-700 dark:text-gray-300">Pixel: <span class="font-medium text-gray-900 dark:text-white">{{ $m['pixel']['label'] }}</span>, CAPI: veri yok</li>
                        @foreach ($m['pixel']['pixels'] as $pixel)
                            <li class="card-row text-[13px] text-gray-500">{{ $pixel['name'] }}, son olay {{ $pixel['last_fired'] ?? 'yok' }}</li>
                        @endforeach
                    </ul>
                </section>
            </div>
        @endif

        <section data-testid="meta-leads">
            <div class="section-head">
                <h2 class="section-title">Lead kalitesi</h2>
                <form wire:submit="uploadLeads" class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                    <input type="file" wire:model="leadFile" accept=".csv,.tsv,.txt" aria-label="Lead dosyası" class="min-w-0 max-w-full text-xs text-gray-600 file:mr-2 file:h-8 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-3 file:text-[13px] file:font-medium file:text-gray-900 dark:text-gray-400 dark:file:border-gray-700 dark:file:bg-white/[0.04] dark:file:text-gray-200">
                    <button type="submit" class="{{ $btn }}">Lead dosyası yükle</button>
                    @error('leadFile')<span class="text-xs text-error-700 dark:text-error-400">{{ $message }}</span>@enderror
                </form>
            </div>
            @if ($leadCampaigns !== [])
                <div class="card overflow-x-auto">
                    <table class="data-table min-w-[36rem]">
                        <thead><tr><th>Kampanya</th><th class="text-right!">Lead</th>@foreach (\App\Models\MetaLead::MARKS as $label)<th class="text-right!">{{ $label }}</th>@endforeach<th class="text-right!">İşaretsiz</th></tr></thead>
                        <tbody class="tabular-nums">
                            @foreach ($leadCampaigns as $row)
                                <tr><td class="text-gray-900 dark:text-white">{{ $row['campaign'] }}</td><td class="text-right">{{ $row['total'] }}</td>@foreach (array_keys(\App\Models\MetaLead::MARKS) as $mark)<td class="text-right text-gray-600 dark:text-gray-400">{{ $row[$mark] }}</td>@endforeach<td class="text-right text-gray-600 dark:text-gray-400">{{ $row['unmarked'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section>
            <div class="section-head">
                <h2 class="section-title">Son 100 lead</h2>
                <label class="inline-flex items-center gap-1.5 text-[13px] text-gray-600 sm:ml-auto dark:text-gray-300"><input type="checkbox" wire:model.live="unmarkedOnly" class="h-4 w-4 rounded border-gray-300"> İşaretsiz</label>
            </div>
            <div class="card">
                @forelse ($leadList as $lead)
                    <div class="card-row flex-wrap py-3! text-sm sm:flex-nowrap" wire:key="lead-{{ $lead->id }}">
                        <span class="w-28 shrink-0 text-xs tabular-nums text-gray-500">{{ $lead->received_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-200" title="{{ $lead->campaign_name }}">{{ $lead->campaign_name ?? '—' }}@if ($lead->form_name)<span class="text-gray-500">, {{ $lead->form_name }}</span>@endif</span>
                        <div class="inline-flex max-w-full flex-wrap gap-0.5 rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-800 dark:bg-white/[0.03]" role="group" aria-label="İşaret">
                            @foreach (\App\Models\MetaLead::MARKS as $mark => $label)
                                <button type="button" wire:click="markLead({{ $lead->id }}, '{{ $lead->mark === $mark ? '' : $mark }}')" @if ($lead->mark === $mark) aria-pressed="true" @endif @class([
                                    'h-6 rounded-md px-2 text-xs font-medium',
                                    'bg-white text-gray-900 shadow-card dark:bg-gray-800 dark:text-white' => $lead->mark === $mark,
                                    'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $lead->mark !== $mark,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <x-ta.empty-state icon="none" size="sm" title="Lead yok." message="Reklam Yöneticisi’nden lead dosyasını indirip yükleyin." />
                @endforelse
            </div>
        </section>

    @elseif ($tab === 'analysis')
        @include('livewire.demo.meta.partials.analysis')

    @elseif ($tab === 'settings')
        @php
            $rows = [
                'Marka' => $brand?->name ?? 'Bağlı değil',
                'Reklam hesabı' => $bound ? trim(($settings['account_name'] ?: '').', '.$account['act_id'], ' ,') : 'Bağlı değil',
                'Para birimi, saat dilimi' => $bound ? $currency.', '.$account['timezone'] : '—',
                'Hedef bölgeler' => $settings['areas'] === [] ? '—' : implode(', ', $settings['areas']),
                'Diller' => $settings['languages'] === [] ? '—' : implode(', ', $settings['languages']),
                'Pixel' => $settings['pixel']['label'],
            ];
        @endphp
        <section data-testid="meta-settings">
            <div class="section-head"><h2 class="section-title">Hesap ayarları</h2></div>
            <dl class="card">
                @foreach ($rows as $label => $value)
                    <div class="card-row flex-wrap items-start gap-y-0.5 text-sm sm:flex-nowrap">
                        <dt class="w-full text-[13px] text-gray-500 sm:w-44 sm:shrink-0">{{ $label }}</dt>
                        <dd class="min-w-0 break-words text-gray-900 dark:text-gray-100">
                            @if ($label === 'Marka' && $brand)
                                <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="{{ $btn }}">Meta entegrasyonu</a>
                @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="{{ $btn }}">Bölge ve dilleri düzenle</a>@endif
                <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="{{ $btn }}">Varlığı düzenle</a>
            </div>
        </section>
    @endif
</div>
