@php
    use App\Livewire\Panel\PerformancePage;
    use App\Panel\Services\PanelPerformance;
    $n = fn ($value) => number_format((int) $value, 0, ',', '.');
@endphp
<div class="space-y-6" data-panel-performance>
    <header>
        <h1 class="page-title">Performans</h1>
        <p class="page-sub">{{ $from->translatedFormat('j F') }} ile {{ $to->translatedFormat('j F Y') }} arasında gelen adaylara ne oldu.</p>
    </header>

    <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="Performans">
        @foreach (PerformancePage::TABS as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['tab', 'tab-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab !== 'satis')
        <x-ta.empty-state size="sm" icon="none" :title="PerformancePage::TABS[$tab].' sekmesi henüz açık değil.'" message="Bu sekme, sitenizin ve reklam hesaplarınızın verisi panele bağlandığında açılır. Açıldığında zil haber verir." />
    @else
        <div class="no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4 md:mx-0 md:px-0">
            @foreach (PerformancePage::PERIODS as $key => $label)
                <button type="button" wire:click="$set('period', '{{ $key }}')" @class(['seg', 'seg-active' => $period === (string) $key])>{{ $label }}</button>
            @endforeach
        </div>

        <section class="metric-strip grid-cols-2 lg:grid-cols-4" data-panel-performance-numbers>
            @foreach ([
                ['Gelen aday', $n($data['total']), null],
                ['Randevu verilen', $n($data['appointments']), $data['total'] ? 'Adayların %'.round($data['appointments'] * 100 / $data['total']).'\'i' : null],
                ['Müşteri olan', $n($data['customers']), $data['total'] ? 'Adayların %'.round($data['customers'] * 100 / $data['total']).'\'i' : null],
                ['İlk yanıt süresi', PanelPerformance::duration($data['response']), $data['unanswered'] ? $n($data['unanswered']).' aday hâlâ yanıt bekliyor' : 'Yarısı bu sürede arandı'],
            ] as [$label, $value, $note])
                <div class="bg-white px-5 py-4">
                    <span class="block text-[13px] text-gray-500">{{ $label }}</span>
                    <span class="mt-1.5 block text-[26px] leading-none font-semibold tracking-[-0.03em] tabular-nums text-gray-900">{{ $value }}</span>
                    @if ($note)<span class="mt-1.5 block text-[13px] text-gray-500">{{ $note }}</span>@endif
                </div>
            @endforeach
        </section>

        @if ($data['total'] === 0)
            <x-ta.empty-state size="sm" icon="none" title="Bu dönemde gelen aday yok." message="Daha uzun bir dönem seçin ya da Adaylar ekranından kayıt ekleyin." />
        @else
            <div class="grid gap-8 xl:grid-cols-2">
                <section data-panel-funnel>
                    <h2 class="mb-3 text-[15px] font-semibold text-gray-900">Adaydan müşteriye</h2>
                    <ol class="space-y-2.5">
                        @foreach ($data['funnel'] as $step)
                            <li>
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="text-gray-800">{{ $step['label'] }}</span>
                                    <span class="tabular-nums text-gray-900"><span class="font-semibold">{{ $n($step['value']) }}</span> <span class="text-gray-500">%{{ $step['share'] }}</span></span>
                                </div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-brand-500" style="width: {{ max($step['value'] > 0 ? 2 : 0, $step['share']) }}%"></div></div>
                            </li>
                        @endforeach
                    </ol>
                </section>

                <section data-panel-lost>
                    <h2 class="mb-3 text-[15px] font-semibold text-gray-900">Olumsuz kapananlar neden gitti</h2>
                    @forelse ($data['lost'] as $row)
                        <div class="flex items-baseline justify-between gap-3 border-b border-gray-100 py-2 text-sm last:border-b-0">
                            <span class="text-gray-800">{{ $row['label'] }}</span><span class="font-semibold tabular-nums text-gray-900">{{ $n($row['value']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Bu dönemde olumsuz kapanan aday yok.</p>
                    @endforelse
                </section>
            </div>

            @foreach ([['Kaynağa göre', 'Kaynak', $data['sources']], ['Konuya göre', 'İlgilendiği konu', $data['interests']]] as [$title, $column, $rows])
                <section>
                    <h2 class="mb-3 text-[15px] font-semibold text-gray-900">{{ $title }}</h2>
                    <div class="card overflow-x-auto">
                        <table class="w-full min-w-[520px] text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-[13px] text-gray-500">
                                    <th class="px-4 py-2.5 font-medium">{{ $column }}</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Aday</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Randevu</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Müşteri</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Müşteriye dönüş</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr class="border-b border-gray-100 last:border-b-0">
                                        <td class="px-4 py-2.5 text-gray-900">{{ $row['label'] }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $n($row['leads']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $n($row['appointments']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $n($row['customers']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">%{{ $row['rate'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            @if ($showTeam)
                <section data-panel-team>
                    <h2 class="mb-3 text-[15px] font-semibold text-gray-900">Kim ne kadar ilgilendi</h2>
                    <div class="card overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-[13px] text-gray-500">
                                    <th class="px-4 py-2.5 font-medium">İlgilenen</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Aday</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Bekleyen</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Randevu</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Müşteri</th>
                                    <th class="px-4 py-2.5 text-right font-medium">İlk yanıt</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($data['team'] as $row)
                                    <tr class="border-b border-gray-100 last:border-b-0">
                                        <td class="px-4 py-2.5 text-gray-900">{{ $row['user'] ? ($names[$row['user']] ?? 'Ayrılan kullanıcı') : 'Kimseye atanmamış' }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $n($row['leads']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $n($row['waiting']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $n($row['appointments']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $n($row['customers']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ PanelPerformance::duration($row['response']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endif
    @endif
</div>
