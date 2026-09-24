{{-- Faz 14g: one Auction Insights upload. Expects $latest (AuctionInsightsImporter::latest). --}}
@php
    $pct = static function (array $row, string $metric): string {
        if ($row[$metric] === null) {
            return '—';
        }
        if (in_array($metric, $row['below_threshold'] ?? [], true)) {
            return '<%10';
        }

        return '%'.number_format($row[$metric] * 100, 1, ',', '.');
    };
    $metricLabels = ['impression_share' => 'Gösterim payı', 'overlap_rate' => 'Çakışma', 'position_above_rate' => 'Üstte çıkma', 'top_of_page_rate' => 'Sayfa üstü', 'abs_top_rate' => 'En üst', 'outranking_share' => 'Geride bırakma'];
@endphp
@if ($latest['upload'] !== null)
    <p class="text-xs text-gray-500">
        Dönem: {{ $latest['upload']['period_start'] && $latest['upload']['period_end'] ? $latest['upload']['period_start'].' – '.$latest['upload']['period_end'] : 'belirtilmedi' }} · yüklendi {{ substr($latest['upload']['uploaded_at'], 0, 16) }}
        @if ($latest['previous'] !== null) · değişim, {{ substr($latest['previous']['uploaded_at'], 0, 10) }} yüklemesine göre @endif
    </p>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Alan adı</th>@foreach ($metricLabels as $label)<th class="px-2 text-right">{{ $label }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($latest['rows'] as $row)
                    <tr @class(['font-semibold' => $row['is_own']])>
                        <td class="py-1.5">{{ $row['is_own'] ? 'Siz' : $row['domain'] }} @if ($row['is_new'])<span class="ml-1 rounded bg-amber-50 px-1.5 text-xs text-amber-700">yeni</span>@endif</td>
                        @foreach (array_keys($metricLabels) as $metric)
                            <td class="px-2 text-right tabular-nums">
                                {{ $pct($row, $metric) }}
                                @if ($metric === 'impression_share' && $row['impression_share_change'] !== null && abs($row['impression_share_change']) >= 0.01)
                                    <span @class(['text-xs', 'text-emerald-600' => $row['impression_share_change'] > 0, 'text-rose-600' => $row['impression_share_change'] < 0])>{{ $row['impression_share_change'] > 0 ? '+' : '' }}{{ number_format($row['impression_share_change'] * 100, 1, ',', '.') }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="text-sm text-gray-500">Henüz yükleme yok.</p>
@endif
