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
    $btn = 'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500 disabled:opacity-50';
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
            'failed' => ['text-error-700', (string) ($state['message'] ?? 'Başarısız.'), false],
            'ready' => ['text-success-700', (string) ($state['message'] ?? 'Hazır.'), false],
            default => [null, null, false],
        };

        return $class === null ? '' : '<p class="text-xs '.$class.'"'.($poll ? ' wire:poll.5s' : '').'>'.e($aiLabels[$op]).': '.e($text).'</p>';
    };
    $stateBadge = [
        'issue' => ['Sorun', 'critical'],
        'ok' => ['Tamam', 'ok'],
        'no_data' => ['veri yok', 'info'],
        'low_data' => ['veri az', 'look'],
    ];
    $h2 = 'section-title mb-2';
    $tr = 'border-b border-gray-200 dark:border-gray-800';
@endphp

<div class="space-y-8">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="$pageTitle">
        <x-slot:mark><x-demo.digital-asset-mark type="meta_ads" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>Meta reklamları · {{ $brand?->name ?? 'Marka yok' }}@if ($bound), {{ $currency }} <span class="break-all text-xs text-gray-500">{{ $actId }}</span>@else, hesap bağlı değil @endif</x-slot:meta>
        <x-slot:actions>
            @if ($bound && $brand)
                <a href="{{ route('operator.meta.assign', ['assetId' => $assetId]) }}" wire:navigate class="{{ $btn }} inline-flex items-center gap-2">Eşleşmeyenleri ata @if (($board['open'] ?? 0) > 0)<span class="text-xs tabular-nums text-gray-500">{{ $board['open'] }}</span>@endif</a>
            @endif
            @if ($ranged)
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Meta verisi her gün toplanır." class="w-full sm:w-auto" />
            @endif
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $btn }}">Verileri yenile</button>
        </x-slot:actions>
        <x-slot:tabs>
            <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Meta">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class([
                        'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium',
                        'border-gray-900 text-gray-900 dark:border-white dark:text-white' => $tab === $key,
                        'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
                    ])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="ml-1 text-xs font-normal tabular-nums text-gray-500">{{ $openCount }}</span>@endif</button>
                @endforeach
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @unless ($bound)
        <section class="flex flex-wrap items-center gap-3 text-sm">
            <x-ta.pill tone="look" class="text-sm">Reklam hesabı bağlı değil</x-ta.pill>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="{{ $primary }} inline-flex">Bağla</a>
        </section>
    @endunless

    @if ($tab === 'campaigns')
        @include('livewire.demo.meta.partials.campaigns')

    @elseif ($tab === 'todo')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="recheck" @disabled(! $bound) class="{{ $btn }}">Yeniden kontrol et</button>
            {!! $aiButton('creatives') !!}
            {!! $aiButton('structure') !!}
            {!! $aiButton('landing') !!}
            @unless ($operational)<span class="text-xs text-gray-500">Marka operasyonel değil; AI kapalı.</span>@endunless
        </div>
        {!! $aiLine('creatives') !!}{!! $aiLine('structure') !!}{!! $aiLine('landing') !!}

        <section data-testid="meta-checks">
            <h2 class="{{ $h2 }}">Kontroller @if ($checks)<span class="text-sm font-normal text-gray-500">{{ \Carbon\CarbonImmutable::parse($checks['at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>@endif</h2>
            @if ($checks === null)
                <x-ta.empty-state size="sm" title="Henüz kontrol edilmedi." />
            @else
                <ul class="divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($checks['checks'] as $check)
                        <li class="flex flex-wrap items-center gap-2 py-2.5">
                            <span class="min-w-0 font-medium text-gray-900 sm:w-52 sm:shrink-0 dark:text-gray-200">{{ $check['label'] }}</span>
                            <x-ta.pill :tone="$stateBadge[$check['state']][1] ?? 'info'" class="sm:w-20">{{ $stateBadge[$check['state']][0] ?? $check['state'] }}</x-ta.pill>
                            <span class="min-w-0 basis-full break-words text-gray-500 sm:flex-1 sm:basis-auto dark:text-gray-400">{{ $check['detail'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @include('livewire.demo.meta.partials.suggestions', ['items' => $suggestions])

        <section data-testid="meta-approved">
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 class="section-title">Uygulanacaklar · {{ $approved->count() }}</h2>
                @if ($approved->isNotEmpty())<button type="button" wire:click="exportCsv" class="{{ $btn }}">CSV indir</button>@endif
            </div>
            @forelse ($approved as $s)
                <div class="flex flex-wrap items-start gap-2 border-b border-gray-200 py-2.5 dark:border-gray-800" wire:key="approved-{{ $s->id }}" x-data="{ text: @js((string) ($s->action['text'] ?? $s->title)) }">
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}</p>
                        <p class="whitespace-pre-line break-words text-xs text-gray-500 dark:text-gray-400">{{ $s->action['text'] ?? '' }}</p>
                    </div>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="link-neutral text-xs">Kopyala</button>
                    <button type="button" wire:click="markApplied({{ $s->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700">Uygulandı</button>
                </div>
            @empty
                <x-ta.empty-state size="sm" title="Onaylanan öneri yok." />
            @endforelse
        </section>

    @elseif ($tab === 'measurement')
        @php $m = $measurement; @endphp
        @if (! ($m['bound'] ?? false))
            <x-ta.empty-state size="sm" title="Veri yok." />
        @else
            <section data-testid="meta-results">
                <div class="grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-3">
                    <x-ta.metric-card :label="'Meta sonuçları · '.$range->label()" :value="$num($m['meta']['results'], 1)" :hint="'Lead '.$num($m['meta']['leads'], 1).', mesaj '.$num($m['meta']['messages'], 1).', satış '.$num($m['meta']['purchases'], 1)" />
                    <x-ta.metric-card :label="'GA4, Meta kaynaklı · '.$range->label()" :value="$m['ga4']['has_data'] ? $num($m['ga4']['key_events'], 1) : '—'" :muted="! $m['ga4']['has_data']" :hint="$m['ga4']['has_data'] ? 'anahtar etkinlik, '.$num($m['ga4']['sessions']).' oturum' : 'veri yok'" />
                    <x-ta.metric-card :label="'CRM (lead işaretleri) · '.$range->label()" :value="$m['crm']['total'] > 0 ? $num($m['crm']['marks']['randevu'] + $m['crm']['marks']['satis']) : '—'" :muted="$m['crm']['total'] === 0" :hint="$m['crm']['total'] > 0 ? 'randevu + satış · '.$m['crm']['total'].' lead, '.$m['crm']['marked'].' işaretli' : 'veri yok'" />
                </div>
                <p class="mt-3 text-xs text-gray-500">Üç kaynak ayrı sayar; toplanmaz.</p>
            </section>

            <div class="grid gap-10 xl:grid-cols-2">
                <section class="min-w-0" data-testid="meta-attribution">
                    <h2 class="{{ $h2 }}">İlişkilendirme ayarı</h2>
                    @forelse ($m['attribution'] as $label => $row)
                        <p class="flex justify-between gap-3 border-b border-gray-200 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:text-gray-300"><span class="font-medium">{{ $label }}</span><span class="text-gray-500">{{ $row['count'] }} reklam seti</span></p>
                    @empty
                        <p class="py-4 text-sm text-gray-500">veri yok</p>
                    @endforelse
                </section>
                <section class="min-w-0" data-testid="meta-pixel">
                    <h2 class="{{ $h2 }}">Pixel / CAPI</h2>
                    <p class="text-sm text-gray-700 dark:text-gray-300">Pixel: <span class="font-medium">{{ $m['pixel']['label'] }}</span>, CAPI: veri yok</p>
                    @foreach ($m['pixel']['pixels'] as $pixel)
                        <p class="py-1 text-xs text-gray-500">{{ $pixel['name'] }}, son olay {{ $pixel['last_fired'] ?? 'yok' }}</p>
                    @endforeach
                </section>
            </div>
        @endif

        <section data-testid="meta-leads">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                <h2 class="section-title">Lead kalitesi</h2>
                <form wire:submit="uploadLeads" class="flex flex-wrap items-center gap-2">
                    <input type="file" wire:model="leadFile" accept=".csv,.tsv,.txt" aria-label="Lead dosyası" class="text-xs">
                    <button type="submit" class="{{ $btn }}">Lead dosyası yükle</button>
                    @error('leadFile')<span class="text-xs text-error-700">{{ $message }}</span>@enderror
                </form>
            </div>
            @if ($leadCampaigns !== [])
                <div class="overflow-x-auto"><table class="w-full min-w-[36rem] text-sm">
                    <thead><tr class="{{ $tr }} text-left text-xs text-gray-500"><th class="py-2 pr-3 font-normal">Kampanya</th><th class="{{ $th }}">Lead</th>@foreach (\App\Models\MetaLead::MARKS as $label)<th class="{{ $th }}">{{ $label }}</th>@endforeach<th class="{{ $th }}">İşaretsiz</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 border-b border-gray-200 tabular-nums dark:divide-gray-800 dark:border-gray-800">
                        @foreach ($leadCampaigns as $row)
                            <tr class="text-gray-700 dark:text-gray-300"><td class="py-1.5 pr-3">{{ $row['campaign'] }}</td><td class="{{ $td }}">{{ $row['total'] }}</td>@foreach (array_keys(\App\Models\MetaLead::MARKS) as $mark)<td class="{{ $td }}">{{ $row[$mark] }}</td>@endforeach<td class="{{ $td }}">{{ $row['unmarked'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
            <div class="mt-6 flex items-center justify-between border-b border-gray-200 py-2 dark:border-gray-800">
                <p class="text-sm text-gray-500">Son 100 lead</p>
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="unmarkedOnly" class="rounded border-gray-300"> İşaretsiz</label>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($leadList as $lead)
                    <div class="flex flex-wrap items-center gap-2 py-2.5 text-sm" wire:key="lead-{{ $lead->id }}">
                        <span class="w-28 shrink-0 text-xs text-gray-500">{{ $lead->received_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300" title="{{ $lead->campaign_name }}">{{ $lead->campaign_name ?? '—' }}@if ($lead->form_name), {{ $lead->form_name }}@endif</span>
                        <div class="flex flex-wrap gap-1">
                            @foreach (\App\Models\MetaLead::MARKS as $mark => $label)
                                <button type="button" wire:click="markLead({{ $lead->id }}, '{{ $lead->mark === $mark ? '' : $mark }}')" @class([
                                    'rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                    'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900 dark:ring-white' => $lead->mark === $mark,
                                    'text-gray-600 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $lead->mark !== $mark,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <x-ta.empty-state size="sm" title="Lead yok." message="Reklam Yöneticisi’nden lead dosyasını indirip yükleyin." />
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
            <dl class="divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                @foreach ($rows as $label => $value)
                    <div class="grid gap-1 py-2.5 sm:grid-cols-4">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="min-w-0 break-words text-gray-900 dark:text-gray-100 sm:col-span-3">
                            @if ($label === 'Marka' && $brand)
                                <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="flex flex-wrap gap-x-5 gap-y-2 py-3 text-sm">
                <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="link-neutral">Meta entegrasyonu</a>
                @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral">Bölge ve dilleri düzenle</a>@endif
                <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="link-neutral">Varlığı düzenle</a>
            </div>
        </section>
    @endif
</div>
