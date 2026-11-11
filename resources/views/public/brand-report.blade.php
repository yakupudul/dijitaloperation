@php
    $numbers = (array) ($session->numbers ?? []);
    $lines = collect($numbers['lines'] ?? [])->filter(fn ($l) => ($l['start'] ?? 0) > 0 || ($l['now'] ?? 0) > 0)->values();
    $services = collect($numbers['services'] ?? [])->filter(fn ($s) => ($s['start'] ?? 0) > 0 || ($s['now'] ?? 0) > 0)->values();
    $done = collect((array) $session->report_lines);
    $next = $session->isApproved() ? collect((array) $session->jobs) : collect();
    $num = fn ($v): string => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 1, ',', '.');
    $day = fn (?string $d): string => $d ? \Carbon\CarbonImmutable::parse($d)->locale('tr')->isoFormat('D MMMM') : '';
    $label = fn (string $l): string => $l === 'Organik tık' ? 'Google aramasından siteye gelen ziyaret' : str_replace(' talebi', ' üzerinden gelen talep', $l);
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $session->brand?->name }} · {{ $session->monthLabel() }} raporu</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap">
    <style>
        :root { --ink: #14201f; --soft: #5b6665; --line: #e3e7e6; --teal: #0e5a57; --mark: #FDCF3C; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--ink); font: 400 17px/1.65 Inter, system-ui, -apple-system, "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        main { max-width: 760px; margin: 0 auto; padding: 56px 20px 72px; }
        .month { margin: 0; font-size: 15px; color: var(--soft); }
        h1 { margin: 6px 0 0; font-size: clamp(30px, 6vw, 44px); line-height: 1.15; font-weight: 300; letter-spacing: -0.02em; text-wrap: balance; }
        h1 span { box-shadow: inset 0 -0.38em 0 var(--mark); }
        .note { margin: 22px 0 0; font-size: 18px; max-width: 62ch; }
        h2 { margin: 52px 0 0; padding-bottom: 12px; border-bottom: 1px solid var(--ink); font-size: 20px; font-weight: 500; letter-spacing: -0.01em; }
        .hint { margin: 10px 0 0; font-size: 14px; color: var(--soft); }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th { padding: 12px 0 8px 18px; text-align: right; font-size: 13px; font-weight: 500; color: var(--soft); white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; padding-left: 0; }
        td { padding: 13px 0 13px 18px; border-top: 1px solid var(--line); text-align: right; white-space: nowrap; }
        td:first-child { white-space: normal; }
        td.now { font-weight: 600; }
        td.chg { width: 5.5rem; color: var(--soft); font-size: 15px; }
        td.up { color: var(--teal); font-weight: 500; }
        ol, ul { margin: 0; padding: 0; list-style: none; }
        li { padding: 16px 0; border-top: 1px solid var(--line); }
        li:first-child { border-top: 0; }
        li strong { display: block; font-weight: 500; }
        li p { margin: 3px 0 0; color: var(--soft); font-size: 16px; }
        footer { margin-top: 64px; padding-top: 18px; border-top: 1px solid var(--line); font-size: 14px; color: var(--soft); display: flex; flex-wrap: wrap; gap: 6px 24px; justify-content: space-between; }
        @media (max-width: 520px) { main { padding-top: 36px; } td.chg { width: auto; } th, td { padding-left: 12px; } td { font-size: 15px; } th { font-size: 12px; } }
        @media print { main { padding: 0; max-width: none; } h2 { margin-top: 32px; } }
    </style>
</head>
<body>
<main>
    <p class="month">{{ $session->monthLabel() }} raporu</p>
    <h1><span>{{ $session->brand?->name }}</span> için bu ay yapılanlar ve sonuçları</h1>
    @if ($session->client_note)<p class="note">{{ $session->client_note }}</p>@endif

    @if ($lines->isNotEmpty())
        <section data-report-numbers>
            <h2>Sayılar</h2>
            <table>
                <thead><tr><th></th><th>Başlangıç</th><th>Son 30 gün</th><th>Değişim</th></tr></thead>
                <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $label((string) $line['label']) }}</td>
                        <td>{{ $num($line['start']) }}</td>
                        <td class="now">{{ $num($line['now']) }}</td>
                        <td @class(['chg', 'up' => ($line['change'] ?? 0) > 0])>{{ $line['change'] === null ? 'yeni' : (($line['change'] > 0 ? '+' : '').$num($line['change']).'%') }}</td>
                    </tr>
                @endforeach
                @foreach ($services as $service)
                    <tr>
                        <td>{{ $service['name'] }} sayfalarına Google'dan gelen ziyaret</td>
                        <td>{{ $num($service['start']) }}</td>
                        <td class="now">{{ $num($service['now']) }}</td>
                        <td @class(['chg', 'up' => $service['now'] > $service['start']])>{{ $service['start'] > 0 ? (($service['now'] >= $service['start'] ? '+' : '').$num(round(($service['now'] - $service['start']) / $service['start'] * 100)).'%') : 'yeni' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="hint">Başlangıç: çalışmaya başladığımız 30 gün ({{ $day($numbers['from'] ?? null) }} ile başlayan dönem). Son 30 gün {{ $day($numbers['to'] ?? null) }} tarihinde biter. Kanallar birbirine eklenmez.</p>
        </section>
    @endif

    @if ($done->isNotEmpty())
        <section data-report-done>
            <h2>Bu ay yapılanlar</h2>
            <ul>
                @foreach ($done as $line)
                    <li><strong>{{ $line['what'] }}</strong>@if (filled($line['result'] ?? null))<p>{{ $line['result'] }}</p>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($next->isNotEmpty())
        <section data-report-next>
            <h2>Önümüzdeki ay</h2>
            <ul>
                @foreach ($next as $job)
                    <li><strong>{{ $job['title'] }}</strong></li>
                @endforeach
            </ul>
        </section>
    @endif

    <footer><span>{{ $agency }}</span><span>Bu sayfa yalnız size gönderilen bağlantıyla açılır.</span></footer>
</main>
</body>
</html>
