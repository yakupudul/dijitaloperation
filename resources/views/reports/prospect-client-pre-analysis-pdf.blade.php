<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $snapshot->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; }
        h2 { font-size: 14px; margin-top: 16px; }
        .meta { color: #555; margin-bottom: 16px; }
        li { margin-bottom: 4px; }
    </style>
</head>
<body>
    <h1>{{ $snapshot->title }}</h1>
    <p class="meta">{{ $content['company_name'] ?? '' }} · {{ $content['analysis_date'] ?? '' }}</p>
    <h2>{{ __('operator.prospects.reports.public_situation', [], $locale) }}</h2>
    <p>{{ $content['public_digital_situation'] ?? '' }}</p>
    <h2>{{ __('operator.prospects.reports.observed_findings', [], $locale) }}</h2>
    <ul>
        @foreach ($content['observed_findings'] ?? [] as $finding)
            <li>{{ is_array($finding) ? ($finding['title'] ?? '') : $finding }}</li>
        @endforeach
    </ul>
    <h2>{{ __('operator.prospects.reports.opportunities', [], $locale) }}</h2>
    <ul>
        @foreach ($content['important_opportunities'] ?? [] as $row)
            <li><strong>{{ $row['title'] ?? '' }}</strong> — {{ $row['explanation'] ?? '' }}</li>
        @endforeach
    </ul>
    <h2>{{ __('operator.prospects.reports.next_steps', [], $locale) }}</h2>
    <ul>
        @foreach ($content['suggested_next_steps'] ?? [] as $step)
            <li>{{ $step }}</li>
        @endforeach
    </ul>
</body>
</html>
