<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dijital ön denetim — {{ $prospect->company_name }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; max-width: 820px; margin: 32px auto; padding: 0 20px; line-height: 1.5; }
        h1 { font-size: 24px; margin: 0; } h2 { font-size: 16px; margin: 28px 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .muted { color: #6b7280; font-size: 13px; } .ok { color: #047857; } .bad { color: #b91c1c; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; } td, th { text-align: left; padding: 6px 4px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .score { font-size: 40px; font-weight: 700; } .print { margin: 16px 0; }
        @media print { .print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="print"><button onclick="window.print()">Yazdır / PDF kaydet</button></p>
    <h1>Dijital ön denetim: {{ $prospect->company_name }}</h1>
    <p class="muted">{{ $agency }} · {{ \Illuminate\Support\Carbon::parse($audit->created_at)->format('d.m.Y') }} · Hesaplara erişmeden, herkese açık veriyle hazırlanmıştır.</p>

    @if ($website !== [])
        <h2>Web sitesi {{ $audit->website_url ? '— '.$audit->website_url : '' }}</h2>
        @if (! ($website['reachable'] ?? false))
            <p class="bad">Site açılamadı ({{ $website['error'] ?? '?' }}). Ziyaretçiler ve Google siteye ulaşamıyor olabilir.</p>
        @else
            <p><span class="score">%{{ $website['score'] }}</span> <span class="muted">temel kontrollerin geçtiği oran</span></p>
            <table>
                @foreach (\App\Services\Intel\ProspectAuditService::CHECKS as $key => [$label, $advice])
                    @php($pass = (bool) ($website['checks'][$key] ?? false))
                    <tr><td class="{{ $pass ? 'ok' : 'bad' }}">{{ $pass ? '✓' : '✗' }}</td><td><strong>{{ $label }}</strong>@unless ($pass)<br><span class="muted">{{ $advice }}</span>@endunless</td></tr>
                @endforeach
            </table>
        @endif
    @endif

    @if ($audit->maps_keyword)
        <h2>Google Haritalar — "{{ $audit->maps_keyword }}"</h2>
        @if ($audit->status === 'running')
            <p class="muted">Sonuç henüz gelmedi.</p>
        @elseif (isset($maps['error']))
            <p class="muted">Harita sonucu okunamadı.</p>
        @else
            <p>@if ($maps['found'] ?? false)İşletme bu aramada <strong>{{ $maps['ours']['rank'] }}. sırada</strong> ({{ $maps['ours']['rating'] ?? '—' }}★, {{ $maps['ours']['votes'] ?? 0 }} yorum).@else İşletme bu aramanın ilk {{ $maps['results'] ?? 20 }} sonucunda <strong class="bad">görünmüyor</strong>.@endif</p>
            @if (($maps['top3'] ?? []) !== [])
                <table>
                    <tr><th>Sıra</th><th>İşletme</th><th>Puan</th><th>Yorum</th></tr>
                    @foreach ($maps['top3'] as $row)
                        <tr><td>{{ $row['rank'] }}</td><td>{{ $row['title'] }}</td><td>{{ $row['rating'] ?? '—' }}</td><td>{{ $row['votes'] ?? '—' }}</td></tr>
                    @endforeach
                </table>
            @endif
        @endif
    @endif

    <h2>Sonraki adım</h2>
    <p>Eksik maddeler genellikle birkaç hafta içinde giderilebilir. Ölçüm (Analytics, piksel) ilk kurulmalı; aksi halde yapılan işin etkisi görülemez.</p>
</body>
</html>
