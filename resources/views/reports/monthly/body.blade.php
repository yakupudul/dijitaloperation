{{-- Monthly report v2 body (Faz 9). Shared by the operator page and the client link. Inputs: $payload, $commentary, $note. --}}
@php
    $fmt = fn ($v, $f) => \App\Services\MonthlyReport\MonthlyReportBuilder::format($v === null ? null : (float) $v, $f);
    $change = function (?float $pct, string $good): string {
        if ($pct === null) {
            return '<span class="mr-muted">—</span>';
        }
        $better = $good === 'neutral' ? null : ($good === 'up' ? $pct > 0 : $pct < 0);
        $class = $better === null ? 'mr-muted' : ($better ? 'mr-good' : 'mr-bad');

        return '<span class="'.$class.'">'.($pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '')).'%'.number_format(abs($pct), 1, ',', '.').'</span>';
    };
    $channelsAvailable = collect($payload['channels'] ?? [])->where('available', true);
    $annotations = (array) ($payload['annotations'] ?? []);
    $markers = \App\Services\MonthlyReport\ChartAnnotations::markers($annotations, (string) ($payload['period']['month'] ?? ''));
    $commentary = is_array($commentary ?? null) && ($commentary['summary'] ?? '') !== '' ? $commentary : null;
@endphp
<div class="mr">
    @if ($commentary !== null)
        <section class="mr-section">
            <h2>Ayın özeti</h2>
            <p>{{ $commentary['summary'] }}</p>
            <div class="mr-cols">
                @if (($commentary['wins'] ?? []) !== [])<div><h3>İyi gidenler</h3><ul>@foreach ($commentary['wins'] as $line)<li>{{ $line }}</li>@endforeach</ul></div>@endif
                @if (($commentary['watch'] ?? []) !== [])<div><h3>Takip edilecekler</h3><ul>@foreach ($commentary['watch'] as $line)<li>{{ $line }}</li>@endforeach</ul></div>@endif
            </div>
        </section>
    @elseif (($payload['highlights'] ?? []) !== [])
        <section class="mr-section">
            <h2>Öne çıkanlar</h2>
            <ul>@foreach ($payload['highlights'] as $line)<li>{{ $line }}</li>@endforeach</ul>
        </section>
    @endif
    @if (filled($note ?? null))
        <section class="mr-section"><h2>Ajansın notu</h2><p style="white-space: pre-line">{{ $note }}</p></section>
    @endif

    @if (($payload['conversions']['available'] ?? false))
        @php
            $k = $payload['conversions']['kpi'];
        @endphp
        <section class="mr-section">
            <h2>Dönüşümler</h2>
            <p class="mr-big">{{ $fmt($k['value'], 'decimal') }} <small>{!! $change($k['change_pct'], 'up') !!} önceki aya göre · yıllık {!! $change($k['yoy_pct'], 'up') !!}</small></p>
            @if (($payload['conversions']['by_type'] ?? []) !== [])
                <p class="mr-muted">@foreach ($payload['conversions']['by_type'] as $type => $value){{ $type }}: {{ $fmt($value, 'decimal') }}@if (! $loop->last) · @endif @endforeach</p>
            @endif
        </section>
    @endif

    @foreach ($channelsAvailable as $key => $channel)
        <section class="mr-section">
            <h2>{{ $channel['label'] }}</h2>
            <table class="mr-table">
                <thead><tr><th></th><th>{{ $payload['period']['label'] }}</th><th>{{ $payload['period']['previous_label'] }}</th><th>Değişim</th><th>{{ $payload['period']['last_year_label'] }}</th><th>Yıllık</th></tr></thead>
                <tbody>
                    @foreach ($channel['kpis'] as $kpi)
                        <tr><td>{{ $kpi['label'] }}</td><td><strong>{{ $fmt($kpi['value'], $kpi['format']) }}</strong></td><td>{{ $fmt($kpi['previous'], $kpi['format']) }}</td><td>{!! $change($kpi['change_pct'], $kpi['good']) !!}</td><td>{{ $fmt($kpi['last_year'], $kpi['format']) }}</td><td>{!! $change($kpi['yoy_pct'], $kpi['good']) !!}</td></tr>
                    @endforeach
                </tbody>
            </table>
            @if (is_array($channel['series'] ?? null))
                <div class="mr-chart">{!! \App\Services\MonthlyReport\ReportChart::line((array) $channel['series']['current'], (array) $channel['series']['previous'], $channel['series']['metric'].' (günlük)', 640, 170, $markers) !!}</div>
                <p class="mr-muted mr-legend"><span class="mr-dot mr-dot-now"></span>{{ $payload['period']['label'] }} <span class="mr-dot mr-dot-prev"></span>{{ $payload['period']['previous_label'] }} — {{ $channel['series']['metric'] }}, günlük</p>
            @endif
        </section>
    @endforeach
    @if ($channelsAvailable->isEmpty())
        <section class="mr-section"><p class="mr-muted">Bu ay için bağlı kanallardan veri yok.</p></section>
    @endif

    @if ($annotations !== [])
        <section class="mr-section">
            <h2>Bu ay dikkat edilecek olaylar</h2>
            <ul>
                @foreach ($annotations as $a)
                    <li><strong>{{ \Illuminate\Support\Carbon::parse($a['starts_on'])->format('d.m') }}@if ($a['ends_on'])–{{ \Illuminate\Support\Carbon::parse($a['ends_on'])->format('d.m') }}@endif</strong> · {{ $a['kind_label'] }}: {{ $a['title'] }}@if (filled($a['note'])) <span class="mr-muted">— {{ $a['note'] }}</span>@endif</li>
                @endforeach
            </ul>
            <p class="mr-muted">Grafiklerdeki turuncu kesikli çizgiler bu olayların günleridir.</p>
        </section>
    @endif

    @if (($payload['local']['grid'] ?? []) !== [] || ($payload['local']['reviews'] ?? null) !== null)
        <section class="mr-section">
            <h2>Yerel görünürlük</h2>
            @foreach ($payload['local']['grid'] as $row)
                <p>"{{ $row['keyword'] }}" aramasında haritada ilk 3'te görünme payı: <strong>%{{ $fmt($row['solv'], 'decimal') }}</strong>@if ($row['solv_before'] !== null) (önce %{{ $fmt($row['solv_before'], 'decimal') }})@endif</p>
            @endforeach
            @if ($payload['local']['reviews'] !== null)
                @php
                    $r = $payload['local']['reviews'];
                @endphp
                <p>Google puanı <strong>{{ number_format($r['rating'], 1, ',', '.') }}</strong> ({{ $r['count'] }} yorum)@if ($r['rating_before'] !== null) — ay başında {{ number_format($r['rating_before'], 1, ',', '.') }}@endif. Bu ay {{ $r['new_in_month'] }} yeni yorum.</p>
            @endif
        </section>
    @endif

    @if (($payload['measured_work'] ?? []) !== [] || ($payload['completed_work'] ?? []) !== [])
        <section class="mr-section">
            <h2>Bu ay yapılanlar</h2>
            <ul>
                @foreach ($payload['measured_work'] ?? [] as $row)
                    <li>{{ $row['text'] ?? '' }}@if (filled($row['result'] ?? null)) <span class="mr-muted">— {{ $row['result'] }}</span>@endif</li>
                @endforeach
                @foreach ($payload['completed_work'] ?? [] as $row)
                    @if (! collect($payload['measured_work'] ?? [])->contains('text', $row['text'] ?? $row['title'] ?? null))
                        <li>{{ $row['text'] ?? $row['title'] ?? '' }}</li>
                    @endif
                @endforeach
            </ul>
            <p class="mr-muted">Ölçülen değişimler aynı dönemde gözlenen farktır; tek nedenin yapılan iş olduğu anlamına gelmez.</p>
        </section>
    @endif

    @php
        $next = $commentary !== null && ($commentary['next_month'] ?? []) !== [] ? $commentary['next_month'] : array_column($payload['next'] ?? [], 'title');
    @endphp
    @if ($next !== [])
        <section class="mr-section"><h2>Gelecek ay</h2><ul>@foreach ($next as $line)<li>{{ $line }}</li>@endforeach</ul></section>
    @endif
</div>
