<!DOCTYPE html>
<html lang="{{ $snapshot->locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $snapshot->title_snapshot }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 48rem; margin: 2rem auto; padding: 0 1rem; color: #111; }
        h1 { font-size: 1.5rem; }
        h2 { font-size: 1rem; text-transform: uppercase; color: #444; margin-top: 1.5rem; }
        .meta { color: #555; margin-bottom: 1rem; }
        .disclaimer { color: #666; font-size: .9rem; }
    </style>
</head>
<body>
    <p class="meta">Historical Report Snapshot</p>
    <h1>{{ $snapshot->title_snapshot }}</h1>
    <div class="meta">
        {{ $snapshot->brand_name_snapshot }} · {{ $snapshot->customer_name_snapshot }}<br>
        Reporting period: {{ $snapshot->period_start?->toDateString() }} → {{ $snapshot->period_end?->toDateString() }}<br>
        Generated at: {{ $snapshot->generated_at?->toIso8601String() }}
    </div>

    @php($story = $content['story'] ?? [])
    <h2>What we observed</h2>
    <ul>
        @forelse ($story['observations'] ?? [] as $item)
            <li>{{ $item['text'] ?? '' }}</li>
        @empty
            <li>No items at generation time.</li>
        @endforelse
    </ul>

    <h2>Where we saw potential</h2>
    <ul>
        @forelse ($story['opportunities'] ?? [] as $item)
            <li>{{ $item['title'] ?? '' }}</li>
        @empty
            <li>No items at generation time.</li>
        @endforelse
    </ul>

    <h2>What we did</h2>
    <ul>
        @forelse ($story['completed_work'] ?? [] as $item)
            <li>{{ $item['text'] ?? '' }}</li>
        @empty
            <li>No items at generation time.</li>
        @endforelse
    </ul>

    <h2>What the business reported</h2>
    @php($outcomes = $story['business_outcomes'] ?? [])
    @if ($outcomes['available'] ?? false)
        <p>
            Qualified leads: {{ $outcomes['qualified_leads'] ?? '—' }}
            · Consultations: {{ $outcomes['consultations'] ?? '—' }}
            · Patients: {{ $outcomes['patients'] ?? '—' }}
            · Revenue: {{ $outcomes['revenue_display'] ?? ($outcomes['revenue'] ?? '—') }}
        </p>
    @else
        <p>{{ $outcomes['unavailable_message'] ?? 'No reported Business Outcome data.' }}</p>
    @endif
    <p class="disclaimer">{{ $story['causation_disclaimer'] ?? '' }}</p>

    @if ($allowsPdf)
        <p><a href="{{ route('reports.share.pdf') }}">Download PDF</a></p>
    @endif
</body>
</html>
