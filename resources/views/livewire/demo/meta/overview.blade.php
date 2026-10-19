@php
    $currency = $account['currency'] ?? '';
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $pct = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 1, ',', '.').'%');
    $card = 'surface p-4';
    $panel = 'surface';
    // Page title: the ad account's own name when Meta gave one; the account id is only a small gray secondary.
    $accountName = trim((string) ($settings['account_name'] ?? ''));
    $actId = $account !== null ? (string) ($account['act_id'] ?? '') : '';
    $pageTitle = $accountName !== '' ? $accountName : (string) ($asset['name'] ?? 'Meta');
    if ($actId !== '' && $accountName === '') {
        $bareId = preg_replace('/^act_/', '', $actId);
        $pageTitle = trim((string) preg_replace('/\s*[·\-|]\s*(act_)?'.preg_quote($bareId, '/').'\s*$/u', '', $pageTitle)) ?: $pageTitle;
    }
    $btn = 'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $th = 'px-3 py-2 text-right font-medium';
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
            'failed' => ['text-error-600', (string) ($state['message'] ?? 'Başarısız.'), false],
            'ready' => ['text-success-600', (string) ($state['message'] ?? 'Hazır.'), false],
            default => [null, null, false],
        };

        return $class === null ? '' : '<p class="text-xs '.$class.'"'.($poll ? ' wire:poll.5s' : '').'>'.e($aiLabels[$op]).': '.e($text).'</p>';
    };
    $stateBadge = [
        'issue' => ['Sorun', 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300'],
        'ok' => ['Tamam', 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300'],
        'no_data' => ['veri yok', 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'],
        'low_data' => ['veri az', 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300'],
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="$pageTitle">
        <x-slot:mark><x-demo.digital-asset-mark type="meta_ads" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>Meta reklamları · {{ $brand?->name ?? 'Marka yok' }} · @if ($bound){{ $currency }} <span class="break-all text-xs text-gray-400">· {{ $actId }}</span>@else Hesap bağlı değil @endif</x-slot:meta>
        <x-slot:actions>
            @if ($bound && $brand)
                <a href="{{ route('operator.meta.assign', ['assetId' => $assetId]) }}" wire:navigate class="{{ $btn }} inline-flex items-center gap-2">Eşleşmeyenleri ata @if (($board['open'] ?? 0) > 0)<span class="rounded-full bg-gray-100 px-1.5 text-xs font-semibold tabular-nums text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $board['open'] }}</span>@endif</a>
            @endif
            @if ($ranged)
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Meta verisi her gün toplanır." class="w-full sm:w-auto" />
            @endif
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $primary }}">Verileri yenile</button>
        </x-slot:actions>
        <x-slot:tabs>
            <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Meta">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class([
                        'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium',
                        'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                        'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
                    ])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="ml-1 rounded-full bg-gray-100 px-1.5 text-xs tabular-nums text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $openCount }}</span>@endif</button>
                @endforeach
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @unless ($bound)
        <section class="tone-look rounded-xl p-4 text-sm">
            <p class="font-semibold">Reklam hesabı bağlı değil</p>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="{{ $primary }} mt-2 inline-flex">Bağla</a>
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

        <section class="{{ $panel }}" data-testid="meta-checks">
            <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Kontroller @if ($checks)<span class="text-xs font-normal text-gray-400">· {{ \Carbon\CarbonImmutable::parse($checks['at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>@endif</h2>
            @if ($checks === null)
                <x-ta.empty-state size="sm" title="Henüz kontrol edilmedi." class="m-4" />
            @else
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                    @foreach ($checks['checks'] as $check)
                        <li class="flex flex-wrap items-center gap-2 px-4 py-2">
                            <span class="min-w-0 font-medium text-gray-800 sm:w-52 sm:shrink-0 dark:text-gray-200">{{ $check['label'] }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $stateBadge[$check['state']][1] ?? '' }}">{{ $stateBadge[$check['state']][0] ?? $check['state'] }}</span>
                            <span class="min-w-0 basis-full break-words text-gray-600 sm:flex-1 sm:basis-auto dark:text-gray-400">{{ $check['detail'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @include('livewire.demo.meta.partials.suggestions', ['items' => $suggestions])

        <section class="{{ $panel }}" data-testid="meta-approved">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Uygulanacaklar · {{ $approved->count() }}</h2>
                @if ($approved->isNotEmpty())<button type="button" wire:click="exportCsv" class="{{ $btn }}">CSV indir</button>@endif
            </div>
            @forelse ($approved as $s)
                <div class="flex flex-wrap items-start gap-2 border-b border-gray-100 px-4 py-2 last:border-0 dark:border-gray-700" wire:key="approved-{{ $s->id }}" x-data="{ text: @js((string) ($s->action['text'] ?? $s->title)) }">
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}</p>
                        <p class="whitespace-pre-line break-words text-xs text-gray-600 dark:text-gray-400">{{ $s->action['text'] ?? '' }}</p>
                    </div>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="link-neutral text-xs font-medium">Kopyala</button>
                    <button type="button" wire:click="markApplied({{ $s->id }})" class="rounded-lg bg-white px-2 py-1 text-xs font-semibold text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-transparent dark:text-gray-200 dark:ring-gray-700">Uygulandı</button>
                </div>
            @empty
                <x-ta.empty-state size="sm" title="Onaylanan öneri yok." class="m-4" />
            @endforelse
        </section>

    @elseif ($tab === 'measurement')
        @php $m = $measurement; @endphp
        @if (! ($m['bound'] ?? false))
            <x-ta.empty-state size="sm" title="Veri yok." />
        @else
            <div class="grid gap-3 md:grid-cols-3" data-testid="meta-results">
                <section class="{{ $card }}">
                    <p class="text-xs font-semibold text-gray-500">Meta sonuçları · {{ $range->label() }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $num($m['meta']['results'], 1) }}</p>
                    <p class="text-xs text-gray-500">Lead {{ $num($m['meta']['leads'], 1) }} · Mesaj {{ $num($m['meta']['messages'], 1) }} · Satış {{ $num($m['meta']['purchases'], 1) }}</p>
                </section>
                <section class="{{ $card }}">
                    <p class="text-xs font-semibold text-gray-500">GA4 · Meta kaynaklı · {{ $range->label() }}</p>
                    @if ($m['ga4']['has_data'])
                        <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $num($m['ga4']['key_events'], 1) }}</p>
                        <p class="text-xs text-gray-500">anahtar etkinlik · {{ $num($m['ga4']['sessions']) }} oturum</p>
                    @else
                        <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">—</p><p class="text-xs text-gray-400">veri yok</p>
                    @endif
                </section>
                <section class="{{ $card }}">
                    <p class="text-xs font-semibold text-gray-500">CRM (lead işaretleri) · {{ $range->label() }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $m['crm']['total'] > 0 ? $num($m['crm']['marks']['randevu'] + $m['crm']['marks']['satis']) : '—' }}</p>
                    <p class="text-xs text-gray-500">{{ $m['crm']['total'] > 0 ? 'randevu + satış · '.$m['crm']['total'].' lead, '.$m['crm']['marked'].' işaretli' : 'veri yok' }}</p>
                </section>
            </div>
            <p class="text-xs text-gray-500">Üç kaynak ayrı sayar; toplanmaz.</p>

            <div class="grid gap-4 xl:grid-cols-2">
                <section class="{{ $panel }}" data-testid="meta-attribution">
                    <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">İlişkilendirme ayarı</h2>
                    @forelse ($m['attribution'] as $label => $row)
                        <p class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300"><span class="font-medium">{{ $label }}</span> · {{ $row['count'] }} reklam seti</p>
                    @empty
                        <p class="px-4 py-4 text-sm text-gray-500">veri yok</p>
                    @endforelse
                </section>
                <section class="{{ $panel }}" data-testid="meta-pixel">
                    <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Pixel / CAPI</h2>
                    <p class="px-4 pt-2 text-sm text-gray-700 dark:text-gray-300">Pixel: <span class="font-medium">{{ $m['pixel']['label'] }}</span> · CAPI: veri yok</p>
                    @foreach ($m['pixel']['pixels'] as $pixel)
                        <p class="px-4 py-1 text-xs text-gray-500">{{ $pixel['name'] }} · son olay {{ $pixel['last_fired'] ?? 'yok' }}</p>
                    @endforeach
                    <div class="pb-2"></div>
                </section>
            </div>
        @endif

        <section class="{{ $panel }}" data-testid="meta-leads">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Lead kalitesi</h2>
                <form wire:submit="uploadLeads" class="flex flex-wrap items-center gap-2">
                    <input type="file" wire:model="leadFile" accept=".csv,.tsv,.txt" aria-label="Lead dosyası" class="text-xs">
                    <button type="submit" class="{{ $btn }}">Lead dosyası yükle</button>
                    @error('leadFile')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                </form>
            </div>
            @if ($leadCampaigns !== [])
                <div class="overflow-x-auto"><table class="w-full min-w-[36rem] text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Kampanya</th><th class="{{ $th }}">Lead</th>@foreach (\App\Models\MetaLead::MARKS as $label)<th class="{{ $th }}">{{ $label }}</th>@endforeach<th class="{{ $th }}">İşaretsiz</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                        @foreach ($leadCampaigns as $row)
                            <tr class="text-gray-700 dark:text-gray-300"><td class="px-4 py-1.5">{{ $row['campaign'] }}</td><td class="{{ $td }}">{{ $row['total'] }}</td>@foreach (array_keys(\App\Models\MetaLead::MARKS) as $mark)<td class="{{ $td }}">{{ $row[$mark] }}</td>@endforeach<td class="{{ $td }}">{{ $row['unmarked'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2 dark:border-gray-700">
                <p class="text-xs text-gray-500">Son 100 lead</p>
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="unmarkedOnly" class="rounded border-gray-300"> İşaretsiz</label>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($leadList as $lead)
                    <div class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm" wire:key="lead-{{ $lead->id }}">
                        <span class="w-28 shrink-0 text-xs text-gray-500">{{ $lead->received_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300" title="{{ $lead->campaign_name }}">{{ $lead->campaign_name ?? '—' }}@if ($lead->form_name) · {{ $lead->form_name }}@endif</span>
                        <div class="flex flex-wrap gap-1">
                            @foreach (\App\Models\MetaLead::MARKS as $mark => $label)
                                <button type="button" wire:click="markLead({{ $lead->id }}, '{{ $lead->mark === $mark ? '' : $mark }}')" @class([
                                    'rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                    'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900 dark:ring-white' => $lead->mark === $mark,
                                    'text-gray-600 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $lead->mark !== $mark,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <x-ta.empty-state size="sm" title="Lead yok." message="Reklam Yöneticisi’nden lead dosyasını indirip yükleyin." class="m-4" />
                @endforelse
            </div>
        </section>

    @elseif ($tab === 'analysis')
        @include('livewire.demo.meta.partials.analysis')

    @elseif ($tab === 'settings')
        @php
            $rows = [
                'Marka' => $brand?->name ?? 'Bağlı değil',
                'Reklam hesabı' => $bound ? trim(($settings['account_name'] ?: '').' · '.$account['act_id'], ' ·') : 'Bağlı değil',
                'Para birimi · saat dilimi' => $bound ? $currency.' · '.$account['timezone'] : '—',
                'Hedef bölgeler' => $settings['areas'] === [] ? '—' : implode(', ', $settings['areas']),
                'Diller' => $settings['languages'] === [] ? '—' : implode(', ', $settings['languages']),
                'Pixel' => $settings['pixel']['label'],
            ];
        @endphp
        <section class="{{ $panel }}" data-testid="meta-settings">
            <dl class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($rows as $label => $value)
                    <div class="grid gap-1 px-4 py-2 sm:grid-cols-4">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="min-w-0 break-words text-gray-900 dark:text-gray-100 sm:col-span-3">
                            @if ($label === 'Marka' && $brand)
                                <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral font-medium">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="flex flex-wrap gap-3 border-t border-gray-100 px-4 py-3 text-sm dark:border-gray-700">
                <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="link-neutral font-medium">Meta entegrasyonu</a>
                @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral font-medium">Bölge ve dilleri düzenle</a>@endif
                <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="font-medium text-gray-600 hover:underline dark:text-gray-300">Varlığı düzenle</a>
            </div>
        </section>
    @endif
</div>
