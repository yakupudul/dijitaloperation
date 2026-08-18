<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $snapshot->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; }
        h2 { font-size: 14px; margin-top: 16px; }
        .internal { background: #f4f4f5; padding: 8px; }
    </style>
</head>
<body>
    <h1>{{ $snapshot->title }}</h1>
    <p>{{ $content['company_name'] ?? '' }} · {{ $content['identity_status'] ?? '' }}</p>
    <div class="internal">
        <h2>{{ __('operator.prospects.first_meeting_focus', [], $locale) }}</h2>
        <p>{{ $content['first_meeting_focus'] ?? '' }}</p>
        <h2>{{ __('operator.prospects.not_recommended', [], $locale) }}</h2>
        <ul>
            @foreach ($content['not_recommended_services'] ?? [] as $row)
                <li>{{ $row['service_definition_code'] ?? '' }} — {{ $row['rationale'] ?? '' }}</li>
            @endforeach
        </ul>
        <h2>{{ __('operator.prospects.overall_confidence', [], $locale) }}</h2>
        <p>{{ $content['overall_confidence'] ?? '' }}</p>
        @if (!empty($content['internal_notes']))
            <h2>{{ __('operator.prospects.reports.internal_notes', [], $locale) }}</h2>
            <p>{{ $content['internal_notes'] }}</p>
        @endif
    </div>
</body>
</html>
