@php
    use App\Models\Agreement;
    use App\Models\FinanceDocument;

    $monthLabel = $monthDate->locale('tr')->isoFormat('MMMM YYYY');
    $day = fn ($d): string => $d !== null ? \Carbon\CarbonImmutable::parse($d)->locale('tr')->isoFormat('D MMM') : '—';
    $dayYear = fn ($d): string => $d !== null ? \Carbon\CarbonImmutable::parse($d)->locale('tr')->isoFormat('D MMM YYYY') : '—';
    $input = 'h-9 w-full rounded-lg border border-gray-300 bg-white px-2.5 text-sm text-gray-900 focus:border-gray-900 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $label = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400';
    $stateChip = ['paid' => ['chip-up', 'Ödendi'], 'late' => ['chip-down', 'Gecikti'], 'partial' => ['chip-accent', 'Kısmi'], 'due' => ['chip-accent', 'Günü geldi'], 'coming' => ['chip-flat', 'Bekleniyor']];
    $err = fn (string $key) => $errors->first($key);
@endphp
<div class="pb-24" data-finance>
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="page-title">Finans</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if ($tab === 'bu-ay')
                <div class="flex items-center gap-0.5" data-finance-period wire:key="finance-period-steps">
                    <button type="button" wire:click="shift(-1)" class="btn-quiet h-11 px-2" aria-label="Önceki dönem">‹</button>
                    <x-operator.date-range-picker :range="$dateRange" :last-day="\App\Livewire\Operator\Finance\FinancePage::dateFilterToday()" :max-day="$maxDay" :caption="$rangeCaption" :compare="false" :show-last-day="false" :calendar="true" data-finance-range />
                    <button type="button" wire:click="shift(1)" class="btn-quiet h-11 px-2" aria-label="Sonraki dönem">›</button>
                </div>
            @elseif ($tab !== 'anlasmalar')
                <x-operator.date-range-picker :range="$dateRange" :last-day="\App\Livewire\Operator\Finance\FinancePage::dateFilterToday()" :compare="false" :show-last-day="false" :calendar="true" all-label="Tüm zamanlar" class="w-full sm:w-auto" data-finance-range />
            @endif
            <button type="button" wire:click="newExpense" class="btn" data-finance-new-expense>Gider ekle</button>
            <button type="button" wire:click="newJob" class="btn-primary" data-finance-new-job>İş ekle</button>
        </div>
    </header>

    <nav class="mt-4 flex flex-wrap gap-2" role="tablist" aria-label="Finans bölümleri">
        @foreach ($tabs as $key => $name)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key]) role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-finance-tab="{{ $key }}">
                {{ $name }}
                @if ($key === 'faturalar' && $fresh > 0)<span class="tabular-nums text-accent">{{ $fresh }}</span>@endif
            </button>
        @endforeach
    </nav>

    {{-- ───────────── Özet ───────────── --}}
    @if ($tab === 'bu-ay')
        @php
            $t = $board['totals'];
            $next = $board['next'];
            $goal = $board['goal'];
            $income = $board['mrr'];
            $barFill = ['in' => 'bg-second', 'late' => 'bg-error-300', 'next' => 'bg-second/25', 'later' => 'bg-gray-200 dark:bg-white/10'];
            $nextWords = $next === null ? null : ($next['in_days'] === 0 ? 'bugün' : ($next['in_days'] === 1 ? 'yarın' : $next['in_days'].' gün sonra'));
        @endphp
        <section class="mt-6 grid gap-x-12 gap-y-8 lg:grid-cols-[minmax(0,1fr)_340px]" data-finance-hero>
            <div class="min-w-0">
                <p class="text-[15px] text-gray-600 dark:text-gray-400">{{ $periodName }} müşterilerinden alacağın</p>
                <div class="mt-2 flex flex-wrap items-baseline gap-x-6 gap-y-2">
                    <p class="text-[44px] leading-none font-medium tracking-[-0.035em] tabular-nums text-gray-900 sm:text-[56px] dark:text-white" data-finance-expected>{{ $tlf($t['expected']) }}</p>
                    <p class="text-[15px] text-gray-600 dark:text-gray-400">
                        <span class="font-semibold text-second">{{ $tlf($t['received']) }} geldi</span>{{ $t['open'] > 0 ? ', '.$tlf($t['open']).' yolda' : '' }}.
                        @if ($next)<span class="whitespace-nowrap">Sıradaki gün {{ $day($next['day']) }}, {{ $nextWords }}: {{ $tlf($next['amount']) }}.</span>@endif
                    </p>
                </div>
                @if ($board['payers']->isNotEmpty())
                    <div class="mt-6 flex h-11 gap-[3px]" role="img" aria-label="Alacağın müşterilere göre dağılımı" data-finance-bar>
                        @foreach ($board['payers'] as $p)
                            <div class="min-w-[6px] rounded-md {{ $barFill[$p['state']] }}" style="flex: {{ max(1, $p['amount']) }} 1 0" title="{{ $p['name'] }}: {{ $tlf($p['amount']) }}{{ $p['received'] > 0 && $p['open'] > 0 ? ' ('.$tlf($p['received']).' geldi)' : '' }}"></div>
                        @endforeach
                    </div>
                    <div class="mt-2 hidden gap-[3px] sm:flex">
                        @foreach ($board['payers'] as $p)
                            <div class="min-w-[6px] overflow-hidden text-xs leading-[17px]" style="flex: {{ max(1, $p['amount']) }} 1 0">
                                @if ($p['amount'] >= $t['expected'] * 0.05)
                                    <span class="block truncate font-medium text-gray-800 dark:text-gray-200">{{ $p['name'] }}</span>
                                    <span class="block truncate tabular-nums text-gray-500">{{ $tlf($p['amount']) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[13px] text-gray-600 dark:text-gray-400">
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-second"></span>Geldi</span>
                        @if ($board['payers']->contains('state', 'late'))<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-error-300"></span>Günü geçti</span>@endif
                        @if ($next)<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-second/25"></span>{{ $day($next['day']) }}'de gelecek</span>@endif
                        @if ($board['payers']->contains('state', 'later'))<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-200 dark:bg-white/10"></span>Daha sonra</span>@endif
                    </div>
                @else
                    <p class="mt-4 text-sm text-gray-500">Bu günlerde ödeme günü olan iş yok. "İş ekle" ile anlaşmaları girdiğinde burası dolar.</p>
                @endif
            </div>

            <aside class="min-w-0" data-finance-goal>
                <div class="flex items-baseline justify-between gap-3">
                    <p class="text-[15px] text-gray-600 dark:text-gray-400">{{ $goal['by'] }} hedefi</p>
                    <p class="text-[15px]"><span class="text-[28px] font-semibold tracking-[-0.02em] tabular-nums text-gray-900 dark:text-white">{{ $goal['count'] }}</span><span class="text-gray-500"> / {{ $goal['target'] }} müşteri</span></p>
                </div>
                @php $newCount = count($goal['new']); $oldCount = max(0, $goal['count'] - $newCount); @endphp
                <div class="mt-3 grid grid-cols-[repeat(20,minmax(0,1fr))] gap-[5px]" role="img" aria-label="{{ $goal['target'] }} müşteri hedefinin {{ $goal['count'] }} tanesi dolu, {{ $newCount }} tanesi bu dönemde geldi">
                    @for ($i = 0; $i < min(200, max($goal['target'], $goal['count'])); $i++)
                        <span @class(['aspect-square rounded-full', 'bg-second' => $i < $oldCount, 'bg-accent' => $i >= $oldCount && $i < $goal['count'], 'bg-gray-100 dark:bg-white/10' => $i >= $goal['count']])></span>
                    @endfor
                </div>
                <p class="mt-3 text-[13px] leading-5 text-gray-600 dark:text-gray-400">
                    @if ($newCount > 0)
                        <span class="mr-1 rounded-[5px] bg-accent px-1.5 py-px font-semibold text-gray-900">+{{ $newCount }} bu dönem</span>{{ implode(', ', array_slice($goal['new'], 0, 4)) }}{{ $newCount > 4 ? ' ve '.($newCount - 4).' müşteri daha' : '' }}.
                    @endif
                    Süren her anlaşma bir nokta; {{ max(0, $goal['target'] - $goal['count']) }} nokta kaldı.
                </p>
            </aside>
        </section>

        @if ($board['receipts']->isNotEmpty())
            <section class="card mt-4" data-finance-receipts>
                <div class="px-4 pt-4"><h2 class="section-title">Gelen dekontlar</h2><p class="page-sub">WhatsApp'tan gönderilen dekontlar. Doğruysa işle, ödeme satırı kendiliğinden kapanır.</p></div>
                <div class="mt-2">
                    @foreach ($board['receipts'] as $r)
                        <div class="card-row flex-wrap" wire:key="receipt-{{ $r->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $r->agreement?->name() ?? ($r->counterparty ?: 'Kimden olduğu bulunamadı') }}</p>
                                <p class="text-[13px] text-gray-500">{{ $r->amountLabel() }} · {{ $dayYear($r->doc_date) }}@if ($r->payment) · {{ $r->payment->title() }} satırına @endif</p>
                            </div>
                            <a href="{{ route('operator.finance.document', $r) }}" target="_blank" rel="noopener" class="btn-quiet">Dekontu aç</a>
                            <button type="button" wire:click="ignoreDoc({{ $r->id }})" class="btn-quiet">Dekont değil</button>
                            <button type="button" wire:click="applyReceipt({{ $r->id }})" class="btn-primary" data-finance-apply-receipt>Ödemeyi işle</button>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @foreach ([['Önce bunları topla', $board['late'], 'late', null], ['Dönemin ödemeleri', $board['rows'], 'rows', 'Bu günlerde ödeme satırı yok. İş eklediğinde ödeme günleri burada görünür.']] as [$heading, $list, $listKey, $empty])
            @continue($list->isEmpty() && $empty === null)
            <section class="card mt-4" data-finance-list="{{ $listKey }}">
                <div class="flex flex-wrap items-baseline gap-x-3 px-4 pt-4">
                    <h2 class="section-title">{{ $heading }}</h2>
                    <span class="section-count">{{ $list->count() }}</span>
                    @if ($listKey === 'late')
                        <span class="ml-auto text-[15px] font-semibold tabular-nums text-error-600" data-finance-late-total>{{ $tlf($list->sum('open')) }} gecikmede</span>
                        <p class="basis-full text-[13px] text-gray-500">Önceki aylardan açık kalanlar. Hatırlat, WhatsApp'ta hazır mesajı açar; göndermeyi sen yaparsın.</p>
                    @endif
                </div>
                @if ($list->isEmpty())
                    <p class="px-4 pb-5 pt-2 text-sm text-gray-500">{{ $empty }}</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="data-table table-stack">
                            <thead><tr><th>Kimden</th><th>Dönem</th><th class="text-right">Tutar</th><th>Ödeme günü</th><th>Durum</th><th class="text-right">İşlem</th></tr></thead>
                            <tbody>
                                @foreach ($list as $row)
                                    @php [$chipClass, $chipText] = $stateChip[$row['state']]; $pid = $row['id']; @endphp
                                    <tr wire:key="{{ $listKey }}-{{ $pid ?? 'v'.$loop->index }}" data-finance-row="{{ $pid }}">
                                        <td class="min-w-[14rem]">
                                            <p class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</p>
                                            <p class="text-xs text-gray-500">{{ $row['what'] }}@if ($row['invoices']->isNotEmpty()) · <a class="underline" href="{{ route('operator.finance.document', $row['invoices']->first()) }}" target="_blank" rel="noopener">faturası</a>@endif</p>
                                        </td>
                                        <td class="whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $row['title'] }}</td>
                                        <td class="whitespace-nowrap text-right tabular-nums">
                                            <span class="font-medium text-gray-900 dark:text-white">{{ $tlf($row['amount']) }}</span>
                                            @if ($row['state'] === 'partial' || ($row['received'] > 0 && $row['open'] > 0))<p class="text-xs text-gray-500">{{ $tlf($row['received']) }} geldi, {{ $tlf($row['open']) }} kaldı</p>@endif
                                        </td>
                                        <td class="whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $day($row['due']) }}@if ($row['days_late'] > 0)<span class="text-error-600"> · {{ $row['days_late'] }} gün</span>@endif</td>
                                        <td><span class="chip {{ $chipClass }} whitespace-nowrap">{{ $chipText }}</span></td>
                                        <td class="text-right">
                                            @if ($pid === null)
                                                <span class="text-xs text-gray-400">günü gelince açılır</span>
                                            @elseif ($row['state'] === 'paid')
                                                <div class="flex justify-end gap-1">
                                                    @if ($row['invoices']->isEmpty())<button type="button" wire:click="openPanel({{ $pid }}, 'invoice')" class="btn-quiet">Fatura ekle</button>@endif
                                                    <button type="button" wire:click="unpaid({{ $pid }})" wire:confirm="Ödeme geri alınsın mı?" class="btn-quiet">Geri al</button>
                                                </div>
                                            @else
                                                <div class="flex justify-end gap-1">
                                                    <button type="button" wire:click="openPanel({{ $pid }}, 'remind')" class="btn-quiet">Hatırlat</button>
                                                    <button type="button" wire:click="openPanel({{ $pid }}, 'pay')" class="btn-quiet">Farklı tutar</button>
                                                    <button type="button" wire:click="paid({{ $pid }})" class="btn-primary" data-finance-paid>Ödendi</button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                    @if ($pid !== null && $openRow === $pid && $openPayment !== null)
                                        <tr wire:key="{{ $listKey }}-panel-{{ $pid }}"><td colspan="6" class="!bg-gray-50 dark:!bg-white/[0.02]">
                                            @if ($rowPanel === 'pay')
                                                <form wire:submit="receive" class="flex flex-wrap items-end gap-3" data-finance-pay-form>
                                                    <div class="w-40"><label class="{{ $label }}" for="pay-amount">Gelen tutar (₺)</label><input id="pay-amount" type="text" inputmode="decimal" wire:model="pay.amount" class="{{ $input }}" placeholder="12.000" autofocus></div>
                                                    <div class="w-40"><label class="{{ $label }}" for="pay-on">Geldiği gün</label><input id="pay-on" type="date" wire:model="pay.on" class="{{ $input }}"></div>
                                                    <div class="w-44"><label class="{{ $label }}" for="pay-vat">KDV</label>
                                                        <select id="pay-vat" wire:model="pay.vat" class="{{ $input }}"><option value="auto">Kendin anla</option><option value="yes">KDV dahil yazdım</option><option value="no">KDV hariç yazdım</option></select></div>
                                                    <button type="submit" class="btn-primary">İşle</button>
                                                    <button type="button" wire:click="closeRow" class="btn-quiet">Vazgeç</button>
                                                    <p class="basis-full text-xs text-gray-500">Tutar en eski açık satırdan başlayarak yerleşir. Fazlası sonraki döneme, eksiği bu satırda "kısmi" olarak kalır.</p>
                                                    @if ($err('pay.amount'))<p class="basis-full text-xs text-error-600">{{ $err('pay.amount') }}</p>@endif
                                                </form>
                                            @elseif ($rowPanel === 'remind' && $reminder !== null)
                                                <div class="max-w-2xl" x-data="{ copied: false }" data-finance-reminder>
                                                    <p class="whitespace-pre-line rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-800 dark:border-gray-800 dark:bg-transparent dark:text-gray-200" x-ref="text">{{ $reminder['text'] }}</p>
                                                    <div class="mt-2 flex flex-wrap gap-2">
                                                        <button type="button" class="btn" @click="navigator.clipboard.writeText($refs.text.innerText); copied = true; setTimeout(() => copied = false, 1500)"><span x-show="!copied">Metni kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
                                                        @if ($reminder['phone'])<a class="btn" target="_blank" rel="noopener" href="https://wa.me/{{ $reminder['phone'] }}?text={{ rawurlencode($reminder['text']) }}">WhatsApp'ta aç</a>@endif
                                                        <button type="button" wire:click="closeRow" class="btn-quiet">Kapat</button>
                                                    </div>
                                                    <p class="mt-2 text-xs text-gray-500">Mesajı sen gönderirsin; sistem kendiliğinden kimseye yazmaz.</p>
                                                </div>
                                            @elseif ($rowPanel === 'invoice')
                                                <div class="flex flex-wrap items-center gap-3" data-finance-invoice-upload>
                                                    <label class="btn cursor-pointer">Fatura dosyasını seç<input type="file" wire:model="files" multiple accept=".pdf,.xml,.zip,.jpg,.jpeg,.png" class="sr-only"></label>
                                                    <span class="text-xs text-gray-500" wire:loading.remove wire:target="files">PDF, XML ya da ZIP. Bu satıra bağlanır.</span>
                                                    <span class="text-xs text-gray-500" wire:loading wire:target="files">Yükleniyor ve okunuyor…</span>
                                                    <button type="button" wire:click="closeRow" class="btn-quiet">Vazgeç</button>
                                                </div>
                                            @endif
                                        </td></tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="card p-4" data-finance-income>
                <p class="text-[15px] text-gray-600 dark:text-gray-400">Aylık düzenli gelir</p>
                <p class="mt-1 text-[34px] leading-none font-medium tracking-[-0.03em] tabular-nums text-gray-900 dark:text-white">{{ $tlf($income['monthly']) }}</p>
                <p class="mt-1.5 text-[13px] text-gray-500">Süren anlaşmaların aylık karşılığı; yıllık olanın on ikide biri sayılır.</p>
                @if ($income['raises'] !== [])
                    <p class="mt-4 text-[13px] font-semibold text-gray-900 dark:text-white">Sırada bekleyen artışlar</p>
                    @foreach ($income['raises'] as $r)
                        <div class="flex items-start justify-between gap-3 border-b border-gray-200 py-2.5 last:border-b-0 dark:border-gray-800">
                            <div class="min-w-0"><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $r['name'] }}</p>@if ($r['when'] !== '')<p class="text-[13px] text-gray-500">{{ $r['when'] }}</p>@endif</div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-second">+{{ $tlf($r['plus']) }}</span>
                        </div>
                    @endforeach
                    <p class="mt-2 text-[13px] text-gray-600 dark:text-gray-400">Hepsi açılınca <span class="font-semibold text-gray-900 dark:text-white">{{ $tlf($income['with_raises']) }} / ay</span>.</p>
                @else
                    <p class="mt-4 text-[13px] text-gray-500">Konuşulmuş bir artış yoksa burası boş kalır. İşin formundaki "Sonra çıkacağı ücret" alanına yazdığında burada görünür.</p>
                @endif
            </section>

            <section class="card p-4" data-finance-upcoming>
                <h2 class="section-title">Yaklaşanlar</h2>
                @forelse ($board['upcoming'] as $item)
                    <div class="mt-3 flex items-start gap-3 border-t border-gray-200 pt-3 first:mt-2 first:border-t-0 dark:border-gray-800">
                        <span @class(['chip mt-0.5', 'chip-down' => $item['kind'] === 'price', 'chip-accent' => in_array($item['kind'], ['ending', 'yearly'], true), 'chip-flat' => in_array($item['kind'], ['renewal', 'rebill'], true)])>{{ ['ending' => 'Bitiyor', 'yearly' => 'Yıllık', 'renewal' => 'Yenileme', 'price' => 'Zam', 'rebill' => 'Yansıt'][$item['kind']] }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                            <p class="text-[13px] text-gray-500">{{ $item['detail'] }}</p>
                        </div>
                        @if ($item['kind'] === 'rebill')<button type="button" wire:click="rebill({{ $item['doc_id'] }})" class="btn shrink-0">Alacak aç</button>@endif
                    </div>
                @empty
                    <p class="mt-2 text-sm text-gray-500">Önümüzdeki haftalarda biten dönem, yıllık yenileme ya da yenilenecek abonelik görünmüyor.</p>
                @endforelse
            </section>
        </div>

        @php $flow = $board['flow']; @endphp
        @if ($flow['cells'] !== [])
            <section class="card mt-4 p-4" data-finance-flow>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="section-title">Takvim</h2>
                    <span class="text-[13px] text-gray-500">Üstte gelen para ({{ $tlf($flow['came']) }}), altta beklenen ödeme</span>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <div class="grid min-w-[560px] gap-[2px]" style="grid-template-columns: repeat({{ count($flow['cells']) }}, minmax(0, 1fr))">
                        @foreach ($flow['cells'] as $c)
                            <div class="flex flex-col items-center gap-1.5">
                                <div class="flex h-14 w-[70%] items-end" title="{{ $c['in'] > 0 ? $tlf($c['in']).' geldi: '.implode(', ', array_unique($c['in_names'])) : '' }}">
                                    @if ($c['in'] > 0)<div class="w-full rounded-t bg-second" style="height: {{ max(3, (int) round($c['in'] * 56 / $flow['max'])) }}px"></div>@endif
                                </div>
                                <span @class(['w-6 rounded-md text-center text-xs leading-[22px] tabular-nums', 'bg-second font-semibold text-white' => $c['today'], 'text-gray-400' => ! $c['today'] && $c['past'], 'text-gray-600 dark:text-gray-300' => ! $c['today'] && ! $c['past']])>{{ $c['label'] }}</span>
                                <div class="flex h-16 w-[70%] items-start" title="{{ $c['due'] > 0 ? $tlf($c['due']).' bekleniyor: '.implode(', ', array_unique($c['due_names'])) : '' }}">
                                    @if ($c['due'] > 0)<div @class(['w-full rounded-b', 'bg-error-300' => $c['past'] && ! $c['today'], 'bg-second/25' => ! $c['past'] || $c['today']]) style="height: {{ max(3, (int) round($c['due'] * 64 / $flow['max'])) }}px"></div>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @php $notable = collect($flow['cells'])->filter(fn ($c) => $c['in'] > 0 || $c['due'] > 0)->sortByDesc(fn ($c) => max($c['in'], $c['due']))->take(4)->sortBy('key'); @endphp
                @if ($notable->isNotEmpty())
                    <div class="mt-4 grid gap-4 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($notable as $c)
                            @php $when = $flow['by'] === 'day' ? $day($c['date']) : $c['date']->locale('tr')->isoFormat('MMMM YYYY'); @endphp
                            <div>
                                @if ($c['in'] > 0)<p class="text-gray-500">{{ $when }} geldi</p><p class="font-medium text-gray-900 dark:text-white">{{ \Illuminate\Support\Str::limit(implode(', ', array_unique($c['in_names'])), 60) }} · {{ $tlf($c['in']) }}</p>@endif
                                @if ($c['due'] > 0)<p @class(['text-gray-500', 'mt-1' => $c['in'] > 0])>{{ $when }} bekleniyor</p><p class="font-medium text-gray-900 dark:text-white">{{ count(array_unique($c['due_names'])) > 1 ? count(array_unique($c['due_names'])).' müşteri' : ($c['due_names'][0] ?? '') }} · {{ $tlf($c['due']) }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @php $s = $board['spent']; @endphp
        <p class="mt-4 text-[13px] text-gray-500" data-finance-spend>
            Bu günlerin giderleri {{ $s['approx'] ? '≈ ' : '' }}{{ $tlf($s['total_try']) }}, {{ $s['count'] }} belge{{ $fresh > 0 ? '; '.$fresh.' belge bakılmayı bekliyor' : '' }}.
            <button type="button" wire:click="$set('tab', 'giderler')" class="font-medium text-second underline-offset-2 hover:underline">Giderleri aç</button>
        </p>

        @if ($board['checks']['no_invoice']->isNotEmpty() || $board['checks']['no_money']->isNotEmpty())
            <section class="card mt-4 p-4" data-finance-checks>
                <h2 class="section-title">Çapraz kontrol</h2>
                <div class="mt-2 grid gap-4 lg:grid-cols-2">
                    @if ($board['checks']['no_invoice']->isNotEmpty())
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Parası geldi, faturası yok</p>
                            @foreach ($board['checks']['no_invoice'] as $p)
                                <div class="mt-2 flex items-center justify-between gap-3 text-[13px]"><span class="text-gray-700 dark:text-gray-300">{{ $p->agreement->payer() }} · {{ $p->title() }} · {{ $tlf($p->received()) }}</span>
                                    <button type="button" wire:click="openPanel({{ $p->id }}, 'invoice')" class="btn-quiet">Fatura ekle</button></div>
                            @endforeach
                        </div>
                    @endif
                    @if ($board['checks']['no_money']->isNotEmpty())
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Faturası kesildi, parası gelmedi</p>
                            @foreach ($board['checks']['no_money'] as $d)
                                <div class="mt-2 flex items-center justify-between gap-3 text-[13px]"><span class="text-gray-700 dark:text-gray-300">{{ $d->agreement?->payer() ?? $d->counterparty }} · {{ $d->doc_no ?: $dayYear($d->doc_date) }} · {{ $d->amountLabel() }}</span>
                                    <button type="button" wire:click="openPanel({{ $d->payment_id }}, 'remind')" class="btn-quiet">Hatırlat</button></div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @endif
    @endif

    {{-- ───────────── Anlaşmalar ───────────── --}}
    @if ($tab === 'anlasmalar')
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('view', 'isler')" @class(['btn', '!border-gray-900' => $view === 'isler'])>İşler</button>
            <button type="button" wire:click="$set('view', 'zam')" @class(['btn', '!border-gray-900' => $view === 'zam']) data-finance-raises-tab>Zam görünümü</button>
            @if ($view === 'isler')
                <select wire:model.live="show" class="{{ $input }} !h-8 !w-auto" aria-label="Durum"><option value="active">Sürenler</option><option value="ended">Bitenler</option></select>
            @endif
        </div>

        @if ($jobId !== 0)
            @include('livewire.operator.finance.job-form')
        @endif

        @if ($view === 'isler')
            <section class="card mt-4" data-finance-jobs>
                @if ($jobs->isEmpty())
                    <p class="p-5 text-sm text-gray-500">{{ $show === 'ended' ? 'Biten iş yok.' : 'Henüz iş yok. "İş ekle" ile bir markanın anlaşmasını ya da panelde olmayan bir işi gir.' }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table table-stack">
                            <thead><tr><th>İş</th><th>Anlaşma</th><th class="text-right">Gelen</th><th class="text-right">Kalan</th><th>Sıradaki ödeme</th><th class="text-right">İşlem</th></tr></thead>
                            <tbody>
                                @foreach ($jobs as $j)
                                    @php $a = $j['agreement']; @endphp
                                    <tr wire:key="job-{{ $a->id }}" data-finance-job="{{ $a->id }}">
                                        <td class="min-w-[14rem]">
                                            <button type="button" wire:click="toggleJob({{ $a->id }})" class="text-left">
                                                <span class="font-medium text-gray-900 dark:text-white">{{ $j['name'] }}</span>
                                                @if ($a->brand_id === null)<span class="chip chip-flat ml-1">panel dışı</span>@endif
                                                @if ($a->no_invoice)<span class="chip chip-flat ml-1">Faturasız</span>@endif
                                                @if ($a->status === 'paused')<span class="chip chip-flat ml-1">durduruldu</span>@endif
                                            </button>
                                            <p class="text-xs text-gray-500">{{ $j['what'] }}</p>
                                        </td>
                                        <td class="whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $a->feeLabel() }}@if ($j['ends_in'] !== null && $j['ends_in'] <= 30 && $j['ends_in'] >= 0)<p class="text-xs text-error-600">{{ $j['ends_in'] }} gün sonra bitiyor</p>@endif</td>
                                        <td data-label="Gelen" class="whitespace-nowrap text-right tabular-nums text-gray-900 dark:text-white">{{ $tlf($j['received']) }}</td>
                                        <td data-label="Kalan" class="whitespace-nowrap text-right tabular-nums">
                                            @if ($j['remaining'] !== null)
                                                <span class="font-medium text-gray-900 dark:text-white">{{ $tlf($j['remaining']) }}</span>
                                                @if ($j['whole'])<div class="ml-auto mt-1 h-1 w-24 rounded-full bg-gray-100 dark:bg-white/10"><div class="h-1 rounded-full bg-second" style="width: {{ min(100, (int) round($j['received'] * 100 / max(1, $j['whole']))) }}%"></div></div>@endif
                                            @else
                                                <span class="text-gray-400">süresiz</span>
                                            @endif
                                        </td>
                                        <td data-label="Sıradaki" class="whitespace-nowrap">
                                            @if ($j['overdue'] > 0)<span class="chip chip-down">{{ $tlf($j['overdue']) }} gecikti</span>
                                            @elseif ($j['next'])<span class="text-gray-700 dark:text-gray-300">{{ $day($j['next']->due_on) }} · {{ $tlf($j['next']->open()) }}</span>
                                            @else<span class="text-gray-400">—</span>@endif
                                        </td>
                                        <td class="text-right"><div class="flex justify-end gap-1">
                                            <button type="button" wire:click="toggleJob({{ $a->id }})" class="btn-quiet">{{ $openJob === $a->id ? 'Kapat' : 'Ödemeler' }}</button>
                                            <button type="button" wire:click="editJob({{ $a->id }})" class="btn-quiet">Düzenle</button>
                                        </div></td>
                                    </tr>
                                    @if ($openJob === $a->id)
                                        <tr wire:key="job-rows-{{ $a->id }}"><td colspan="6" class="!bg-gray-50 dark:!bg-white/[0.02]">
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($a->payments as $p)
                                                    <div @class(['rounded-xl border bg-white px-3 py-2 text-[13px] dark:bg-transparent', 'border-success-200' => $p->isPaid(), 'border-error-200' => $p->isOverdue(), 'border-gray-200 dark:border-gray-800' => ! $p->isPaid() && ! $p->isOverdue()]) wire:key="jp-{{ $p->id }}">
                                                        <p class="font-medium text-gray-900 dark:text-white">{{ $p->title() }}</p>
                                                        <p class="tabular-nums text-gray-600 dark:text-gray-400">{{ $tlf($p->amount) }} · {{ $day($p->due_on) }}</p>
                                                        <p class="mt-1">
                                                            @if ($p->isPaid())<span class="text-success-700">{{ $day($p->paid_on) }} ödendi</span> <button type="button" wire:click="unpaid({{ $p->id }})" class="ml-1 underline text-gray-500">geri al</button>
                                                            @else @if ($p->isPartial())<span class="text-gray-600">{{ $tlf($p->received()) }} geldi · </span>@endif<button type="button" wire:click="paid({{ $p->id }})" class="font-medium underline text-gray-900 dark:text-white">ödendi işaretle</button>@endif
                                                        </p>
                                                    </div>
                                                @endforeach
                                                @if ($a->payments->isEmpty())<p class="text-sm text-gray-500">Ödeme satırı yok.</p>@endif
                                            </div>
                                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                                @if ($a->status === 'active' && $a->kind !== 'one_time')<button type="button" wire:click="setJobStatus({{ $a->id }}, 'paused')" class="btn-quiet">Durdur</button>@endif
                                                @if ($a->status !== 'active')<button type="button" wire:click="setJobStatus({{ $a->id }}, 'active')" class="btn-quiet">Sürdür</button>@endif
                                                @if ($a->status !== 'ended')<button type="button" wire:click="setJobStatus({{ $a->id }}, 'ended')" wire:confirm="Bu iş bitti olarak işaretlensin mi? Ödenmemiş ileri tarihli satırlar kalkar." class="btn-quiet">Bitir</button>@endif
                                                @if ($a->tax_no)<span class="text-xs text-gray-500">Vergi no {{ $a->tax_no }}</span>@endif
                                                @if ($a->note)<span class="text-xs text-gray-500">· {{ \Illuminate\Support\Str::limit($a->note, 140) }}</span>@endif
                                            </div>
                                        </td></tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @else
            <section class="card mt-4" data-finance-raises>
                <div class="px-4 pt-4">
                    <h2 class="section-title">Kiminle kaç liraya, ne zamandır</h2>
                    <p class="page-sub">Aylık toplam {{ $tlf($raises['monthly']) }} + KDV.
                        @if ($raises['inflation'] > 0) "Bugünkü değeri" ve "aynı alım gücü" sütunları yıllık %{{ rtrim(rtrim(number_format($raises['inflation'], 1, ',', ''), '0'), ',') }} enflasyonla hesaplanır.
                        @else Erime sütunları için Faturalar › Ayarlar'a yıllık enflasyon oranını yaz. @endif</p>
                </div>
                <div class="mt-2 overflow-x-auto">
                    <table class="data-table table-stack">
                        <thead><tr><th>Müşteri</th><th class="text-right">Ücret</th><th>Bu ücretle</th><th class="text-right">Bugünkü değeri</th><th class="text-right">Aynı alım gücü için</th><th class="text-right">Liste fiyatı</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($raises['rows'] as $r)
                                <tr wire:key="raise-{{ $r['agreement']->id }}">
                                    <td class="min-w-[13rem]"><p class="font-medium text-gray-900 dark:text-white">{{ $r['name'] }}</p><p class="text-xs text-gray-500">{{ $r['what'] }}</p></td>
                                    <td class="whitespace-nowrap text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ $tlf($r['fee']) }}<span class="font-normal text-gray-500">/{{ $r['unit'] }}</span></td>
                                    <td data-label="Bu ücretle" class="whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $r['months'] }} aydır<p class="text-xs text-gray-500">{{ $dayYear($r['since']) }}@if ($r['ends']) · {{ $dayYear($r['ends']) }} bitiyor @endif</p></td>
                                    <td data-label="Bugünkü değeri" class="whitespace-nowrap text-right tabular-nums">@if ($r['worth'])<span class="text-error-600">{{ $tlf($r['worth']) }}</span>@else<span class="text-gray-400">—</span>@endif</td>
                                    <td data-label="Aynı alım gücü için" class="whitespace-nowrap text-right tabular-nums">@if ($r['same'])<span class="text-gray-900 dark:text-white">{{ $tlf($r['same']) }}</span>@else<span class="text-gray-400">—</span>@endif</td>
                                    <td data-label="Liste fiyatı" class="whitespace-nowrap text-right tabular-nums">@if ($r['list'])<span class="text-gray-900 dark:text-white">{{ $tlf($r['list']) }}</span>@if ($r['under_list'] > 0)<p class="text-xs text-error-600">{{ $tlf($r['under_list']) }} altında</p>@endif @else<span class="text-gray-400">—</span>@endif</td>
                                    <td class="text-right">@if ($r['due'])<span class="chip chip-accent">Zam zamanı</span>@endif <button type="button" wire:click="editJob({{ $r['agreement']->id }})" class="btn-quiet">Ücreti değiştir</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-sm text-gray-500">Süren aylık, dönemlik ya da yıllık anlaşma yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    {{-- ───────────── Giderler / Faturalar ───────────── --}}
    @if ($tab === 'giderler' || $tab === 'faturalar')
        @if ($docId > 0 || $addingExpense)
            @include('livewire.operator.finance.doc-form')
        @endif

        @if ($tab === 'faturalar')
            <section class="card mt-4 p-4" data-finance-gmail>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="section-title">Gmail'den faturalar</h2>
                        @if ($gmail['linked'])
                            <p class="page-sub">Bağlı. Yalnız Moximu ya da Yakup Udül adına düzenlenmiş belgeler alınır. {{ $gmail['found'] }} belge alındı{{ ($gmail['not_mine'] ?? 0) > 0 ? ', adına olmayan '.$gmail['not_mine'].' belge alınmadı' : '' }}{{ ($gmail['waiting'] ?? 0) > 0 ? ', '.$gmail['waiting'].' taranmış belge Claude aboneliğinin okumasını bekliyor' : '' }}{{ $gmail['synced_at'] ? ' · son okuma '.\Carbon\CarbonImmutable::parse($gmail['synced_at'])->setTimezone('Europe/Istanbul')->locale('tr')->isoFormat('D MMM HH:mm') : '' }}{{ $gmail['backfill_done'] ? '' : ' · posta kutusunun geçmişi baştan okunuyor' }}. Her saat kendiliğinden okunur; e-arşiv ve e-fatura PDF'leri yapay zekâsız, kuralla okunur.</p>
                            @if ($gmail['last_error'])<p class="mt-1 text-[13px] text-error-600">{{ $gmail['last_error'] }}</p>@endif
                        @else
                            <p class="page-sub">Gmail'i bağladığında faturalı e-postaların ekleri (PDF, XML, ZIP) buraya kendiliğinden gelir. Posta kutusu yalnız okunur; hiçbir e-posta silinmez ya da gönderilmez.</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($gmail['linked'])
                            <button type="button" wire:click="rereadGmail" wire:confirm="Yılbaşından bugüne tüm posta yeniden taransın mı? Daha önce alınan belgeler tekrar alınmaz." class="btn-quiet">Baştan tara</button>
                            <button type="button" wire:click="syncGmail" class="btn" data-finance-gmail-sync>Şimdi çek</button>
                        @elseif ($gmail['connect'])
                            <a href="{{ $gmail['connect'] }}" class="btn-primary" data-finance-gmail-connect>Gmail'i bağla</a>
                        @else
                            <a href="{{ route('operator.integrations.google') }}" class="btn">Önce Google'ı bağla</a>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        <section class="card mt-4 p-4" data-finance-upload>
            <div class="flex flex-wrap items-center gap-3">
                <label class="btn-primary cursor-pointer">{{ $tab === 'giderler' ? 'Gider belgesi yükle' : 'Belge yükle' }}<input type="file" wire:model="files" multiple accept=".pdf,.xml,.zip,.jpg,.jpeg,.png,.webp" class="sr-only" data-finance-file></label>
                @if ($tab === 'faturalar')
                    <select wire:model="uploadAs" class="{{ $input }} !w-auto" aria-label="Belge türü"><option value="">Türünü sistem anlasın</option><option value="out">Kestiğim fatura</option><option value="in">Gider</option></select>
                @endif
                <span class="text-[13px] text-gray-500" wire:loading.remove wire:target="files">PDF, XML, fotoğraf ya da ZIP. Birden çok dosya seçebilirsin; tutar, tarih ve taraf dosyadan okunur.</span>
                <span class="text-[13px] text-gray-700" wire:loading wire:target="files">Yükleniyor ve okunuyor…</span>
                @if ($err('files.*') || $err('files'))<span class="text-[13px] text-error-600">{{ $err('files.*') ?: $err('files') }}</span>@endif
            </div>
        </section>

        @if ($waiting->isNotEmpty())
            <section class="card mt-4" data-finance-waiting>
                <div class="px-4 pt-4"><h2 class="section-title">Bakılacak belgeler <span class="section-count">{{ $waiting->count() }}</span></h2><p class="page-sub">Yeni gelen ve yeri tam bulunamayan belgeler. Doğruysa "Yerinde", değilse düzelt.</p></div>
                <div class="mt-2">
                    @foreach ($waiting as $d)
                        <div class="card-row flex-wrap" wire:key="wait-{{ $d->id }}">
                            <span @class(['chip', 'chip-up' => $d->direction === 'out', 'chip-flat' => $d->direction !== 'out'])>{{ $d->directionLabel() }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $d->counterparty ?: ($d->original_name ?: 'Okunamadı') }} <span class="font-normal tabular-nums text-gray-600 dark:text-gray-400">· {{ $d->amountLabel() }}</span></p>
                                <p class="truncate text-[13px] text-gray-500">{{ $dayYear($d->doc_date) }} · {{ FinanceDocument::SOURCES[$d->source] ?? $d->source }}
                                    @if ($d->direction === 'out') · {{ $d->agreement ? $d->agreement->name().' işine bağlandı' : 'hangi işe ait olduğu bulunamadı' }}
                                    @elseif ($d->direction === 'in') · {{ $d->categoryLabel() }} @endif
                                    @if ($d->amount === null) · tutar okunamadı @endif</p>
                            </div>
                            @if ($d->hasFile())<a href="{{ route('operator.finance.document', $d) }}" target="_blank" rel="noopener" class="btn-quiet">Aç</a>@endif
                            <button type="button" wire:click="ignoreDoc({{ $d->id }})" class="btn-quiet">Çıkar</button>
                            <button type="button" wire:click="editDoc({{ $d->id }})" class="btn">Düzelt</button>
                            @if ($d->amount !== null && ($d->direction !== 'out' || $d->agreement_id))<button type="button" wire:click="fileDoc({{ $d->id }})" class="btn-primary">Yerinde</button>@endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="card mt-4" data-finance-docs>
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4">
                <h2 class="section-title">{{ $tab === 'giderler' ? 'Giderler' : 'Belgeler' }} · {{ $rangeLabel }} <span class="section-count">{{ $docs->count() }}</span></h2>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($tab === 'faturalar')
                        <select wire:model.live="direction" class="{{ $input }} !h-8 !w-auto" aria-label="Tür"><option value="">Hepsi</option><option value="out">Kestiğim faturalar</option><option value="in">Giderler</option><option value="receipt">Dekontlar</option><option value="yok">Yok sayılanlar</option></select>
                    @endif
                    @if ($docs->isNotEmpty() && ! ($ignored ?? false))
                        <a href="{{ route('operator.finance.zip', $rangeQuery + array_filter(['tur' => $tab === 'giderler' ? 'in' : $direction])) }}" class="btn" data-finance-zip>ZIP indir</a>
                    @endif
                </div>
            </div>
            @if ($tab === 'giderler' && $docs->isNotEmpty())
                <p class="px-4 pt-1 text-[13px] text-gray-500">Toplam {{ $spend['approx'] ? '≈ ' : '' }}{{ $tlf($spend['total_try']) }} · {{ $spend['count'] }} belge</p>
            @endif
            @if ($docs->isEmpty())
                <p class="px-4 pb-5 pt-2 text-sm text-gray-500">{{ $dateRange === null ? 'Henüz belge yok.' : $rangeLabel.' arasında belge yok. Tarih seçiciden "Tüm zamanlar"ı seçebilirsin.' }}</p>
            @else
                <div class="mt-2 overflow-x-auto">
                    <table class="data-table table-stack">
                        <thead><tr><th>Tarih</th>@if ($tab === 'faturalar')<th>Tür</th>@endif<th>{{ $tab === 'giderler' ? 'Nereye' : 'Karşı taraf' }}</th><th>{{ $tab === 'giderler' ? 'Kategori' : 'Bağlı iş / kategori' }}</th><th class="text-right">Tutar</th><th>Kaynak</th><th class="text-right">İşlem</th></tr></thead>
                        <tbody>
                            @foreach ($docs as $d)
                                <tr wire:key="doc-{{ $d->id }}" data-finance-doc="{{ $d->id }}">
                                    <td class="whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $day($d->doc_date) }}</td>
                                    @if ($tab === 'faturalar')<td><span @class(['chip', 'chip-up' => $d->direction === 'out', 'chip-flat' => $d->direction !== 'out'])>{{ $d->directionLabel() }}</span></td>@endif
                                    <td class="min-w-[12rem]"><p class="font-medium text-gray-900 dark:text-white">{{ $d->counterparty ?: '—' }}</p>@if ($d->doc_no || $d->note)<p class="text-xs text-gray-500">{{ $d->doc_no }}{{ $d->doc_no && $d->note ? ' · ' : '' }}{{ $d->note }}</p>@endif</td>
                                    <td class="text-gray-700 dark:text-gray-300">
                                        @if ($d->direction === 'in'){{ $d->categoryLabel() }}@if ($d->brand)<p class="text-xs text-gray-500">{{ $d->brand->name }} adına{{ $d->rebill ? ($d->rebilled_agreement_id ? ' · yansıtıldı' : ' · yansıtılacak') : '' }}</p>@endif
                                        @else {{ $d->agreement?->name() ?? '—' }} @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ $d->amountLabel() }}</td>
                                    <td data-label="Kaynak" class="whitespace-nowrap text-gray-500">{{ FinanceDocument::SOURCES[$d->source] ?? $d->source }}</td>
                                    <td class="text-right"><div class="flex justify-end gap-1">
                                        @if ($d->hasFile())<a href="{{ route('operator.finance.document', $d) }}" target="_blank" rel="noopener" class="btn-quiet">Aç</a>@endif
                                        @if ($d->status === 'ignored')<button type="button" wire:click="restoreDoc({{ $d->id }})" class="btn-quiet">Geri al</button>
                                        @else
                                            @if ($d->direction === 'in' && $d->rebill && ! $d->rebilled_agreement_id)<button type="button" wire:click="rebill({{ $d->id }})" class="btn-quiet">Alacak aç</button>@endif
                                            <button type="button" wire:click="editDoc({{ $d->id }})" class="btn-quiet">Düzenle</button>
                                        @endif
                                    </div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($more ?? false)<p class="px-4 pb-4 pt-2 text-[13px] text-gray-500">İlk {{ \App\Livewire\Operator\Finance\FinancePage::LIST_LIMIT }} belge gösteriliyor. Daha eskileri için tarih aralığını daralt; ZIP seçili aralığın tamamını indirir.</p>@endif
            @endif
        </section>

        @if ($tab === 'giderler')
            <section class="card mt-4" data-finance-subscriptions>
                <div class="px-4 pt-4"><h2 class="section-title">Abonelikler <span class="section-count">{{ $subscriptions->where('stopped', false)->count() }}</span></h2>
                    <p class="page-sub">En az iki ayrı ayda belgesi gelen yerler. Liste belgelerden kendiliğinden çıkar; sen bir şey girmezsin.</p></div>
                @if ($subscriptions->isEmpty())
                    <p class="px-4 pb-5 pt-2 text-sm text-gray-500">Henüz tekrar eden bir ödeme görünmüyor. Aynı yerden ikinci ayın belgesi geldiğinde burada belirir.</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="data-table table-stack">
                            <thead><tr><th>Nereye</th><th>Sıklık</th><th class="text-right">Son tutar</th><th>Son belge</th><th>Sıradaki</th><th class="text-right">Aylık karşılığı</th></tr></thead>
                            <tbody>
                                @foreach ($subscriptions as $s)
                                    <tr wire:key="sub-{{ $s['key'] }}" @class(['opacity-60' => $s['stopped']])>
                                        <td class="min-w-[12rem]"><p class="font-medium text-gray-900 dark:text-white">{{ $s['name'] }}</p><p class="text-xs text-gray-500">{{ $s['category'] }} · {{ $s['count'] }} belge{{ $s['brand'] ? ' · '.$s['brand']->name.' adına' : '' }}</p></td>
                                        <td data-label="Sıklık" class="text-gray-700 dark:text-gray-300">{{ $s['every'] }}</td>
                                        <td class="whitespace-nowrap text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ $s['last']->amountLabel() }}@if ($s['up'])<p class="text-xs font-normal text-error-600">%{{ $s['up'] }} arttı ({{ $s['before']->amountLabel() }} idi)</p>@endif</td>
                                        <td data-label="Son belge" class="whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $dayYear($s['last']->doc_date) }}</td>
                                        <td data-label="Sıradaki" class="whitespace-nowrap">@if ($s['stopped'])<span class="text-gray-500">belge gelmiyor</span>@elseif ($s['soon'])<span class="chip chip-accent">{{ $day($s['next']) }}</span>@else<span class="text-gray-700 dark:text-gray-300">{{ $dayYear($s['next']) }}</span>@endif</td>
                                        <td data-label="Aylık karşılığı" class="whitespace-nowrap text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $s['monthly_try'] !== null ? '≈ '.$tlf($s['monthly_try']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if ($tab === 'faturalar')
            <section class="card mt-4 p-4" data-finance-settings>
                <h2 class="section-title">Ayarlar</h2>
                <form wire:submit="saveSettings" class="mt-3 flex flex-wrap items-end gap-3">
                    <div class="w-56"><label class="{{ $label }}" for="own-tax">Kendi vergi / TC kimlik no'n</label><input id="own-tax" type="text" inputmode="numeric" wire:model="settings.own_tax_no" class="{{ $input }}" placeholder="Faturalarında satıcı olarak geçen no"></div>
                    <div class="w-44"><label class="{{ $label }}" for="inflation">Yıllık enflasyon (%)</label><input id="inflation" type="text" inputmode="decimal" wire:model="settings.inflation" class="{{ $input }}" placeholder="örn. 33"></div>
                    <div class="w-28"><label class="{{ $label }}" for="vat">KDV (%)</label><input id="vat" type="text" inputmode="numeric" wire:model="settings.vat" class="{{ $input }}"></div>
                    <button type="submit" class="btn">Kaydet</button>
                </form>
                @if ($err('settings.own_tax_no'))<p class="mt-1 text-xs text-error-600">{{ $err('settings.own_tax_no') }}</p>@endif
                <p class="mt-2 max-w-[80ch] text-xs text-gray-500">Vergi no'n, bir faturayı senin kestiğini anlamak için kullanılır; boşsa ilk kestiğin faturadan kendiliğinden öğrenilir. Enflasyon oranı yalnız Zam görünümündeki hesap içindir.</p>
            </section>
        @endif
    @endif
</div>
