@php
    $num = fn ($v): string => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 1, ',', '.');
    $name = fn (string $l): string => $l === 'Organik tık' ? 'Google aramasından siteye gelen ziyaret' : str_replace(' talebi', ' üzerinden gelen talep', $l);
    $area = 'w-full rounded-lg border border-line bg-white px-3 py-2 text-base text-gray-900 outline-none focus:border-primary sm:text-sm';
@endphp
<div class="space-y-8" data-panel-marketing="{{ $tab }}">
    @if ($tab === 'rapor')
        @if ($report === null)
            <x-ta.empty-state size="sm" icon="none" title="İlk raporunuz hazırlanıyor." message="Her ayın başında bir önceki ayın raporu burada yayınlanır: gelen talepler, yapılan işler ve gelecek ayın planı." />
        @else
            @php
                $numbers = (array) ($report->numbers ?? []);
                $lines = collect($numbers['lines'] ?? [])->filter(fn ($l) => ($l['start'] ?? 0) > 0 || ($l['now'] ?? 0) > 0)->values();
                $services = collect($numbers['services'] ?? [])->filter(fn ($s) => ($s['start'] ?? 0) > 0 || ($s['now'] ?? 0) > 0)->values();
                $done = collect($report->panelLines());
                $next = $report->isApproved() ? collect((array) $report->jobs) : collect();
            @endphp
            <div class="flex flex-wrap items-center gap-2 print:hidden">
                <div class="no-scrollbar -mx-4 flex min-w-0 flex-1 gap-1.5 overflow-x-auto px-4 lg:mx-0 lg:flex-wrap lg:px-0">
                    @foreach ($reports as $row)
                        <a href="{{ route('panel.report', ['ay' => $row->month]) }}" wire:navigate @class(['seg', 'seg-active' => $row->id === $report->id])>{{ \Illuminate\Support\Str::ucfirst($row->monthLabel()) }}</a>
                    @endforeach
                </div>
                <button type="button" class="btn" onclick="window.print()">Yazdır ya da PDF kaydet</button>
            </div>

            <article class="max-w-[820px] space-y-8" data-panel-report="{{ $report->month }}">
                <header>
                    <h2 class="text-[21px] font-semibold leading-7 tracking-[-0.02em] text-gray-900">{{ \Illuminate\Support\Str::ucfirst($report->monthLabel()) }} raporu</h2>
                    @if ($report->client_note)<p class="mt-2 max-w-[70ch] text-[15px] leading-6 text-gray-700">{{ $report->client_note }}</p>@endif
                </header>

                @if ($sales !== null && $sales['total'] > 0)
                    <section>
                        <div class="section-head"><h3 class="section-title">Bu ay size ulaşanlar</h3></div>
                        <div class="metric-strip grid-cols-3" data-panel-report-sales>
                            @foreach ([['Gelen aday', $sales['total']], ['Randevu verilen', $sales['appointments']], ['Müşteri olan', $sales['customers']]] as [$label, $value])
                                <div class="bg-white px-4 py-3.5 sm:px-5">
                                    <span class="block text-sm text-gray-500">{{ $label }}</span>
                                    <span class="mt-1 block text-[26px] leading-none font-semibold tracking-[-0.03em] tabular-nums text-gray-900">{{ $num($value) }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-sm text-gray-500">Adaylar bölümündeki kayıtlarınızdan sayılır.</p>
                    </section>
                @endif

                @if ($lines->isNotEmpty() || $services->isNotEmpty())
                    <section>
                        <div class="section-head"><h3 class="section-title">Sayılar</h3></div>
                        <div class="card overflow-x-auto" data-panel-report-numbers>
                            <table class="data-table">
                                <thead><tr><th></th><th class="text-right!">Başlangıç</th><th class="text-right!">Son 30 gün</th><th class="text-right!">Değişim</th></tr></thead>
                                <tbody>
                                    @foreach ($lines as $line)
                                        <tr>
                                            <td class="text-gray-900">{{ $name((string) $line['label']) }}</td>
                                            <td class="text-right tabular-nums text-gray-600">{{ $num($line['start']) }}</td>
                                            <td class="text-right font-semibold tabular-nums text-gray-900">{{ $num($line['now']) }}</td>
                                            <td @class(['text-right tabular-nums', 'font-medium text-primary' => ($line['change'] ?? 0) > 0, 'text-gray-500' => ($line['change'] ?? 0) <= 0])>{{ ($line['change'] ?? null) === null ? 'yeni' : (($line['change'] > 0 ? '+' : '').$num($line['change']).'%') }}</td>
                                        </tr>
                                    @endforeach
                                    @foreach ($services as $service)
                                        <tr>
                                            <td class="text-gray-900">{{ $service['name'] }} sayfalarına Google'dan gelen ziyaret</td>
                                            <td class="text-right tabular-nums text-gray-600">{{ $num($service['start']) }}</td>
                                            <td class="text-right font-semibold tabular-nums text-gray-900">{{ $num($service['now']) }}</td>
                                            <td @class(['text-right tabular-nums', 'font-medium text-primary' => $service['now'] > $service['start'], 'text-gray-500' => $service['now'] <= $service['start']])>{{ $service['start'] > 0 ? (($service['now'] >= $service['start'] ? '+' : '').$num(round(($service['now'] - $service['start']) / $service['start'] * 100)).'%') : 'yeni' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if (filled($numbers['from'] ?? null))
                            <p class="mt-2 text-sm text-gray-500">Başlangıç, çalışmaya başladığımız ilk 30 gündür ({{ \Carbon\CarbonImmutable::parse($numbers['from'])->locale('tr')->isoFormat('D MMMM YYYY') }} ile başlar). Kanallar birbirine eklenmez.</p>
                        @endif
                    </section>
                @endif

                @if ($done->isNotEmpty())
                    <section>
                        <div class="section-head"><h3 class="section-title">Bu ay yaptıklarımız</h3></div>
                        <div class="card" data-panel-report-done>
                            @foreach ($done as $line)
                                <div class="card-row items-start">
                                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                                    <div class="min-w-0">
                                        <p class="text-[15px] font-medium text-gray-900">{{ $line['what'] }}</p>
                                        @if (filled($line['result'] ?? null))<p class="text-sm text-gray-500">{{ $line['result'] }}</p>@endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($next->isNotEmpty())
                    <section>
                        <div class="section-head"><h3 class="section-title">Önümüzdeki ay</h3></div>
                        <div class="card" data-panel-report-next>
                            @foreach ($next as $job)
                                <div class="card-row"><p class="text-[15px] text-gray-900">{{ $job['title'] }}</p></div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </article>
        @endif
    @elseif ($tab === 'yaptiklarimiz')
        @if ($work['months'] === [])
            <x-ta.empty-state size="sm" icon="none" title="Son aylarda kayda geçen bir iş yok." message="Siteniz, İşletme Profiliniz ve reklamlarınız için yaptığımız her iş burada birikir." />
        @else
            <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                <section data-panel-work-months>
                    <div class="section-head"><h2 class="section-title">Ay ay yapılan işler</h2></div>
                    <div class="space-y-4">
                        @foreach ($work['months'] as $month)
                            <div class="card">
                                <div class="card-row bg-soft"><span class="flex-1 text-[15px] font-semibold text-gray-900">{{ $month['label'] }}</span><span class="text-sm tabular-nums text-gray-600">{{ $month['total'] }} iş</span></div>
                                @foreach ($month['kinds'] as $kind)
                                    <div class="card-row"><span class="min-w-0 flex-1 text-[15px] text-gray-800">{{ $kind['label'] }}</span><span class="text-[15px] font-medium tabular-nums text-gray-900">{{ $kind['count'] }}</span></div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </section>
                <section data-panel-work-recent>
                    <div class="section-head"><h2 class="section-title">Son yapılanlar</h2></div>
                    <div class="card">
                        @foreach ($work['recent'] as $row)
                            <div class="card-row">
                                <span class="w-[84px] shrink-0 text-sm tabular-nums text-gray-500">{{ $row['at'] }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[15px] font-medium text-gray-900">{{ $row['label'] }}</span>
                                    @if ($row['subject'] !== '')<span class="block truncate text-sm text-gray-500">{{ $row['subject'] }}</span>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        @endif
    @elseif ($tab === 'yayinlanacaklar')
        <section>
            <p class="mb-4 max-w-[75ch] text-[15px] text-gray-500">Sıradaki gönderileriniz. Onayınızı beklemeyiz; bir şeyin değişmesini isterseniz “Değişiklik iste” ile yazın, yayınlanmadan önce düzeltiriz.</p>
            <div class="card" data-panel-upcoming>
                @forelse ($upcoming as $item)
                    @php($already = $asked->contains(fn ($subject) => str_contains((string) $subject, $item['on']->locale('tr')->isoFormat('D MMMM').' günkü '.mb_strtolower($item['kind']))))
                    <div class="card-row flex-wrap items-start" wire:key="{{ $item['key'] }}">
                        <span class="w-[92px] shrink-0 text-sm tabular-nums text-gray-500">{{ $item['on']->locale('tr')->isoFormat('D MMMM') }}</span>
                        <div class="min-w-0 flex-1 basis-64">
                            <p class="text-[15px] font-medium text-gray-900">{{ $item['kind'] }}</p>
                            <p class="mt-0.5 line-clamp-3 whitespace-pre-line text-sm text-gray-600">{{ $item['text'] }}</p>
                        </div>
                        @if ($already)
                            <span class="shrink-0 text-sm text-gray-500">Değişiklik isteğiniz bizde</span>
                        @elseif ($canAsk)
                            <button type="button" wire:click="$set('changing', '{{ $item['key'] }}')" class="btn">Değişiklik iste</button>
                        @endif
                    </div>
                    @if ($changing === $item['key'])
                        <form wire:submit="askChange" class="space-y-2 border-t border-line bg-soft px-4 py-3" wire:key="change-{{ $item['key'] }}" data-panel-change-form>
                            <textarea wire:model="change" rows="3" class="{{ $area }}" placeholder="Neyin değişmesini istersiniz?" aria-label="Değişiklik isteği"></textarea>
                            @error('change')<span class="block text-sm text-error-600">{{ $message }}</span>@enderror
                            <div class="flex gap-2">
                                <button type="submit" class="btn-primary">Gönder</button>
                                <button type="button" wire:click="$set('changing', null)" class="btn-quiet">Vazgeç</button>
                            </div>
                        </form>
                    @endif
                @empty
                    <x-ta.empty-state size="sm" icon="none" title="Sırada bekleyen gönderi yok." message="Yeni gönderiler planlandığında burada görünür." />
                @endforelse
            </div>
        </section>
    @else
        <section>
            <p class="mb-4 max-w-[75ch] text-[15px] text-gray-500">Sizin adınıza takip ettiğimiz hesaplar. Hepsi sizindir; biz yalnızca yönetiriz.</p>
            <div class="card" data-panel-assets>
                @forelse ($assets as $asset)
                    <div class="card-row">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-medium text-gray-900">{{ $asset['label'] }}</span>
                            <span class="block truncate text-sm text-gray-500">{{ $asset['name'] }}</span>
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-soft px-2.5 py-0.5 text-[13px] font-medium text-gray-700"><i @class(['h-[7px] w-[7px] rounded-full', 'bg-success-600' => $asset['state'] === 'Takipte', 'bg-gray-400' => $asset['state'] !== 'Takipte'])></i>{{ $asset['state'] }}</span>
                    </div>
                @empty
                    <x-ta.empty-state size="sm" icon="none" title="Henüz bağlı bir hesap yok." message="Siteniz, İşletme Profiliniz ve reklam hesaplarınız bağlandıkça burada listelenir." />
                @endforelse
            </div>
        </section>
    @endif
</div>
