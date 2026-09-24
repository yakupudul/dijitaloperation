<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $report->brand?->name }} · {{ $report->payload['period']['label'] ?? $report->month }} raporu</title>
    @include('reports.monthly.styles')
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f9fafb; margin: 0; }
        .wrap { max-width: 860px; margin: 0 auto; padding: 24px 16px; }
        header h1 { font-size: 24px; margin: 0; } header p { color: #6b7280; margin: 4px 0 18px; font-size: 13px; }
        .print { margin-bottom: 12px; }
        @media print { .print, .preview { display: none; } body { background: #fff; } .wrap { padding: 0; } }
    </style>
</head>
<body>
<div class="wrap">
    @if ($preview ?? false)<p class="preview" style="background:#fef3c7;padding:8px 12px;border-radius:8px;font-size:13px">Önizleme — müşteri bağlantısı ancak rapor yayımlanınca çalışır.</p>@endif
    <p class="print"><button onclick="window.print()">Yazdır / PDF kaydet</button></p>
    <header>
        <h1>{{ $report->brand?->name }} — {{ $report->payload['period']['label'] ?? $report->month }} dijital raporu</h1>
        <p>{{ $agency }} · Hazırlanma: {{ $report->updated_at?->format('d.m.Y') }}</p>
    </header>
    @include('reports.monthly.body', ['payload' => $report->payload, 'commentary' => $report->commentary, 'note' => $report->operator_note])
</div>
</body>
</html>
