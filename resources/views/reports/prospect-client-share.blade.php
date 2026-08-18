<!DOCTYPE html>
<html lang="{{ $snapshot->locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $snapshot->title }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; color: #111; }
        h1 { font-size: 1.5rem; }
        a { color: #4f46e5; }
    </style>
</head>
<body data-testid="prospect-client-share">
    <h1>{{ $content['company_name'] ?? $snapshot->title }}</h1>
    <p>{{ $content['analysis_date'] ?? '' }}</p>
    <h2>{{ __('operator.prospects.reports.public_situation', [], $snapshot->locale) }}</h2>
    <p>{{ $content['public_digital_situation'] ?? '' }}</p>
    <h2>{{ __('operator.prospects.reports.observed_findings', [], $snapshot->locale) }}</h2>
    <ul>
        @foreach ($content['observed_findings'] ?? [] as $finding)
            <li>{{ is_array($finding) ? ($finding['title'] ?? '') : $finding }}</li>
        @endforeach
    </ul>
    <h2>{{ __('operator.prospects.reports.opportunities', [], $snapshot->locale) }}</h2>
    <ul>
        @foreach ($content['important_opportunities'] ?? [] as $row)
            <li><strong>{{ $row['title'] ?? '' }}</strong> — {{ $row['explanation'] ?? '' }}</li>
        @endforeach
    </ul>
    <p><a href="{{ route('prospect-reports.share.pdf', ['token' => $token]) }}">PDF</a></p>
</body>
</html>
