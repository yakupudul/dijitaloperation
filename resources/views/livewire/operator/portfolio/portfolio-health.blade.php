@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $cellTone = ['ok' => 'bg-success-50 text-success-700', 'warn' => 'bg-warning-50 text-warning-700', 'bad' => 'bg-error-50 text-error-700', 'missing' => 'bg-gray-50 text-gray-400 dark:bg-white/5'];
    $dot = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-500', 'bad' => 'bg-error-500'];
    $gapLabels = ['nothing_connected' => 'Hiç hesabı bağlı olmayan marka', 'no_website' => 'Web sitesi olmayan marka', 'no_search_console' => 'Search Console bağlı değil', 'no_ga4' => 'GA4 bağlı değil', 'no_wordpress' => 'WordPress eklentisi bağlı değil'];
    $paceLabel = ['over' => 'Bütçeyi aşacak', 'under' => 'Bütçenin altında kalacak', 'on_track' => 'Yolunda', 'no_budget' => 'Bütçe girilmemiş', 'mixed_currency' => 'Hesaplar farklı para biriminde'];
    $paceTone = ['over' => 'text-error-600', 'under' => 'text-warning-600', 'on_track' => 'text-success-600', 'no_budget' => 'text-gray-500', 'mixed_currency' => 'text-warning-600'];
    $money = fn (?float $n): string => $n === null ? '—' : number_format($n, 0, ',', '.').' TL';
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Portföy sağlığı</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Her marka için her kanal tek satırda: bağlı mı, veri güncel mi, açık uyarı var mı. Gri hücre eksik kurulumdur. Altta tüm müşterilerin bu ayki reklam bütçesi temposu var.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-4">
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Marka</div><div class="text-xl font-semibold">{{ $totals['brands'] }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Sorunlu</div><div class="text-xl font-semibold text-error-600">{{ $totals['bad'] }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Dikkat</div><div class="text-xl font-semibold text-warning-600">{{ $totals['warn'] }}</div></div>
        <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="{{ $card }} block p-4 hover:ring-brand-300"><div class="text-xs text-gray-500">Hiçbir markaya bağlanmamış hesap</div><div class="text-xl font-semibold">{{ $unbound }}</div></a>
    </div>

    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="onlyProblems" class="size-4 rounded border-gray-300"> Yalnızca sorunlu ve dikkat isteyen markalar</label>

    <section class="{{ $card }} overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs text-gray-500">
                <tr>
                    <th class="px-4 py-3">Marka</th>
                    @foreach ($channels as $label)<th class="px-2 py-3">{{ $label }}</th>@endforeach
                    <th class="px-4 py-3 text-right">Açık iş</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($rows as $row)
                    <tr wire:key="ph-{{ $row['brand_id'] }}">
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <span class="size-2 shrink-0 rounded-full {{ $dot[$row['status']] ?? '' }}"></span>
                                <a href="{{ route('operator.brand', ['brand' => $row['brand_id']]) }}" wire:navigate class="font-medium text-gray-800 hover:text-brand-600 dark:text-gray-200">{{ $row['brand'] }}</a>
                            </div>
                            <div class="pl-4 text-xs text-gray-500">{{ $row['customer'] }}</div>
                        </td>
                        @foreach ($channels as $key => $label)
                            @php $cell = $row['cells'][$key]; @endphp
                            <td class="px-2 py-2">
                                @if ($cell['url'])
                                    <a href="{{ $cell['url'] }}" @if (($cell['data_state'] ?? null) !== 'access_problem') wire:navigate @endif title="{{ implode(' · ', $cell['notes'] ?? []) }}" class="block max-w-40 truncate rounded-md px-2 py-1 text-xs {{ $cellTone[$cell['state']] ?? '' }}" @isset($cell['data_state']) data-data-state="{{ $cell['data_state'] }}" @endisset>{{ $cell['label'] }}@if (filled($cell['alert'] ?? null))<span class="block truncate text-[11px] opacity-80">{{ $cell['alert'] }}</span>@endif</a>
                                @else
                                    <span class="block rounded-md px-2 py-1 text-xs {{ $cellTone[$cell['state']] ?? '' }}">{{ $cell['label'] }}</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('operator.command-center', ['brand' => $row['brand_id']]) }}" wire:navigate class="hover:text-brand-600">
                                @if ($row['urgent'] > 0)<span class="font-semibold text-error-600">{{ $row['urgent'] }} acil</span> · @endif{{ $row['open'] }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($channels) + 2 }}" class="p-5 text-sm text-gray-500">Gösterilecek marka yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="{{ $card }} p-5">
            <h2 class="font-semibold text-gray-800 dark:text-white">Kurulum eksikleri</h2>
            <div class="mt-3 space-y-3 text-sm">
                @php $anyGap = false; @endphp
                @foreach ($gapLabels as $key => $label)
                    @if (($gaps[$key] ?? []) !== [])
                        @php $anyGap = true; @endphp
                        <div>
                            <div class="text-xs font-medium text-gray-500">{{ $label }} ({{ count($gaps[$key]) }})</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($gaps[$key] as $gap)
                                    <a href="{{ route('operator.brand', ['brand' => $gap['brand_id']]) }}" wire:navigate class="rounded-full bg-gray-100 px-2 py-0.5 text-xs hover:bg-brand-50 dark:bg-white/5">{{ $gap['brand'] }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
                @unless ($anyGap)<p class="text-gray-500">Eksik kurulum yok.</p>@endunless
            </div>
        </section>

        <section class="{{ $card }} p-5">
            <h2 class="font-semibold text-gray-800 dark:text-white">Bu ayki reklam bütçesi temposu</h2>
            <p class="mt-1 text-xs text-gray-500">Harcama bugüne kadarki hızla ay sonuna taşınır; ±%10–15 dışı işaretlenir. Bütçeler müşteri kartında girilir.</p>
            @if ($pacing === [])
                <p class="mt-3 text-sm text-gray-500">Bu ay reklam harcaması ya da girilmiş bütçe yok.</p>
            @else
                <table class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Müşteri</th><th class="py-1">Kanal</th><th class="py-1 text-right">Harcanan</th><th class="py-1 text-right">Ay sonu</th><th class="py-1 text-right">Bütçe</th><th class="py-1"></th></tr></thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                        @foreach ($pacing as $p)
                            <tr>
                                <td class="py-1.5"><a href="{{ route('operator.customer', ['customerId' => $p['customer_id']]) }}" wire:navigate class="hover:text-brand-600">{{ $p['customer'] }}</a></td>
                                <td class="py-1.5 text-gray-500" @if (($p['accounts'] ?? []) !== []) title="{{ collect($p['accounts'])->map(fn ($a) => $a['name'].': '.number_format($a['spent'], 0, ',', '.').' '.($a['currency'] ?? ''))->implode(' · ') }}" @endif>{{ $p['label'] }}@if (($p['accounts'] ?? []) !== []) <span class="text-xs text-gray-400">· {{ count($p['accounts']) }} hesap</span>@endif</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $money($p['spent']) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $money($p['projected']) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $money($p['budget']) }}</td>
                                <td class="py-1.5 pl-2 text-xs {{ $paceTone[$p['state']] ?? '' }}">{{ $paceLabel[$p['state']] ?? $p['state'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
</div>
