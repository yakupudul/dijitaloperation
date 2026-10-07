@php
    $currency = $account['currency'] ?? '';
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $pct = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 1, ',', '.').'%');
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
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
            'failed' => ['text-rose-600', (string) ($state['message'] ?? 'Başarısız.'), false],
            'ready' => ['text-emerald-600', (string) ($state['message'] ?? 'Hazır.'), false],
            default => [null, null, false],
        };

        return $class === null ? '' : '<p class="text-xs '.$class.'"'.($poll ? ' wire:poll.5s' : '').'>'.e($aiLabels[$op]).': '.e($text).'</p>';
    };
    $stateBadge = [
        'issue' => ['Sorun', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
        'ok' => ['Tamam', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        'no_data' => ['veri yok', 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'],
        'low_data' => ['veri az', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" />

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-center gap-3">
            <x-demo.digital-asset-mark type="meta_ads" size="lg" />
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ $asset['name'] ?? 'Meta' }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $brand?->name ?? 'Marka yok' }} · {{ $bound ? $account['act_id'].' · '.$currency : 'Hesap bağlı değil' }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($bound && $brand)
                <a href="{{ route('operator.meta.assign', ['assetId' => $assetId]) }}" wire:navigate class="{{ $btn }} inline-flex items-center gap-2">Eşleşmeyenleri ata @if (($board['open'] ?? 0) > 0)<span class="rounded-full bg-rose-50 px-1.5 text-xs font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $board['open'] }}</span>@endif</a>
            @endif
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $primary }}">Verileri yenile</button>
        </div>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Meta">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
            ])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="ml-1 rounded-full bg-brand-50 px-1.5 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $openCount }}</span>@endif</button>
        @endforeach
    </nav>

    @unless ($bound)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-950/20">
            <p class="font-semibold text-amber-900 dark:text-amber-200">Reklam hesabı bağlı değil</p>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="mt-2 inline-flex rounded-lg bg-amber-600 px-3 py-1.5 font-semibold text-white">Bağla</a>
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
                <p class="px-4 py-4 text-sm text-gray-500">Henüz kontrol edilmedi.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($checks['checks'] as $check)
                        <li class="flex flex-wrap items-center gap-2 px-4 py-2">
                            <span class="w-52 shrink-0 font-medium text-gray-800 dark:text-gray-200">{{ $check['label'] }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $stateBadge[$check['state']][1] ?? '' }}">{{ $stateBadge[$check['state']][0] ?? $check['state'] }}</span>
                            <span class="min-w-0 flex-1 text-gray-600 dark:text-gray-400">{{ $check['detail'] }}</span>
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
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}</p>
                        <p class="whitespace-pre-line text-xs text-gray-600 dark:text-gray-400">{{ $s->action['text'] ?? '' }}</p>
                    </div>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="text-xs font-semibold text-brand-600 hover:underline">Kopyala</button>
                    <button type="button" wire:click="markApplied({{ $s->id }})" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Uygulandı</button>
                </div>
            @empty
                <p class="px-4 py-4 text-sm text-gray-500">Onaylanan öneri yok.</p>
            @endforelse
        </section>

    @elseif ($tab === 'measurement')
        @php $m = $measurement; @endphp
        @if (! ($m['bound'] ?? false))
            <p class="{{ $card }} text-sm text-gray-500">Veri yok.</p>
        @else
            <div class="grid gap-3 md:grid-cols-3" data-testid="meta-results">
                <section class="{{ $card }}">
                    <p class="text-xs font-semibold text-gray-500">Meta sonuçları · 28 gün</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $num($m['meta']['results'], 1) }}</p>
                    <p class="text-xs text-gray-500">Lead {{ $num($m['meta']['leads'], 1) }} · Mesaj {{ $num($m['meta']['messages'], 1) }} · Satış {{ $num($m['meta']['purchases'], 1) }}</p>
                </section>
                <section class="{{ $card }}">
                    <p class="text-xs font-semibold text-gray-500">GA4 · Meta kaynaklı · 28 gün</p>
                    @if ($m['ga4']['has_data'])
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $num($m['ga4']['key_events'], 1) }}</p>
                        <p class="text-xs text-gray-500">anahtar etkinlik · {{ $num($m['ga4']['sessions']) }} oturum</p>
                    @else
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">—</p><p class="text-xs text-gray-400">veri yok</p>
                    @endif
                </section>
                <section class="{{ $card }}">
                    <p class="text-xs font-semibold text-gray-500">CRM (lead işaretleri) · 28 gün</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $m['crm']['total'] > 0 ? $num($m['crm']['marks']['randevu'] + $m['crm']['marks']['satis']) : '—' }}</p>
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
                    @error('leadFile')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                </form>
            </div>
            @if ($leadCampaigns !== [])
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Kampanya</th><th class="{{ $th }}">Lead</th>@foreach (\App\Models\MetaLead::MARKS as $label)<th class="{{ $th }}">{{ $label }}</th>@endforeach<th class="{{ $th }}">İşaretsiz</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                        @foreach ($leadCampaigns as $row)
                            <tr class="text-gray-700 dark:text-gray-300"><td class="px-4 py-1.5">{{ $row['campaign'] }}</td><td class="{{ $td }}">{{ $row['total'] }}</td>@foreach (array_keys(\App\Models\MetaLead::MARKS) as $mark)<td class="{{ $td }}">{{ $row[$mark] }}</td>@endforeach<td class="{{ $td }}">{{ $row['unmarked'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2 dark:border-gray-700">
                <p class="text-xs text-gray-500">Son 100 lead</p>
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="unmarkedOnly" class="rounded border-gray-300"> İşaretsiz</label>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($leadList as $lead)
                    <div class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm" wire:key="lead-{{ $lead->id }}">
                        <span class="w-28 shrink-0 text-xs text-gray-500">{{ $lead->received_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ $lead->campaign_name ?? '—' }}@if ($lead->form_name) · {{ $lead->form_name }}@endif</span>
                        <div class="flex gap-1">
                            @foreach (\App\Models\MetaLead::MARKS as $mark => $label)
                                <button type="button" wire:click="markLead({{ $lead->id }}, '{{ $lead->mark === $mark ? '' : $mark }}')" @class([
                                    'rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                    'bg-brand-500 text-white ring-brand-500' => $lead->mark === $mark,
                                    'text-gray-600 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $lead->mark !== $mark,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-500">Lead yok. Reklam Yöneticisi’nden lead dosyasını indirip yükleyin.</p>
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
                        <dd class="text-gray-900 dark:text-gray-100 sm:col-span-3">
                            @if ($label === 'Marka' && $brand)
                                <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="flex flex-wrap gap-3 border-t border-gray-100 px-4 py-3 text-sm dark:border-gray-700">
                <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="font-medium text-brand-600 hover:underline">Meta entegrasyonu</a>
                @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">Bölge ve dilleri düzenle</a>@endif
                <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="font-medium text-gray-600 hover:underline dark:text-gray-300">Varlığı düzenle</a>
            </div>
        </section>
    @endif
</div>
