<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        h2 { font-size: 13px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #444; }
        .meta { color: #555; margin-bottom: 14px; }
        .section { margin-bottom: 10px; }
        ul { margin: 4px 0 0 16px; padding: 0; }
        li { margin: 2px 0; }
        .disclaimer { font-size: 10px; color: #666; margin-top: 8px; }
        .empty { color: #888; font-style: italic; }
        .footer { margin-top: 24px; font-size: 9px; color: #777; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        <div><strong>{{ $brandName }}</strong> · {{ $customerName }}</div>
        <div>{{ __('operator.reports.reporting_period') }}: {{ $periodStart }} → {{ $periodEnd }}</div>
        <div>{{ __('operator.reports.generated_at') }}: {{ $generatedAt }}</div>
        <div>{{ __('operator.reports.locale') }}: {{ $locale }}</div>
    </div>

    <div class="section">
        <h2>{{ __('operator.value.what_observed') }}</h2>
        @if (($observations ?? []) === [])
            <p class="empty">{{ __('operator.reports.section_empty') }}</p>
        @else
            <ul>
                @foreach ($observations as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="section">
        <h2>{{ __('operator.value.open_opportunities') }}</h2>
        @if (($opportunities ?? []) === [])
            <p class="empty">{{ __('operator.reports.section_empty') }}</p>
        @else
            <ul>
                @foreach ($opportunities as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="section">
        <h2>{{ __('operator.value.what_did') }}</h2>
        @if (($completedWork ?? []) === [])
            <p class="empty">{{ __('operator.reports.section_empty') }}</p>
        @else
            <ul>
                @foreach ($completedWork as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="section">
        <h2>{{ __('operator.value.business_outcome') }}</h2>
        @if (! ($outcomesAvailable ?? false))
            <p class="empty">{{ $outcomesUnavailable ?? __('operator.value.business_unavailable') }}</p>
        @else
            <ul>
                <li>{{ __('operator.outcomes.qualified_leads') }}: {{ $qualifiedLeads }}</li>
                <li>{{ __('operator.outcomes.consultations') }}: {{ $consultations }}</li>
                <li>{{ __('operator.outcomes.patients') }}: {{ $patients }}</li>
                <li>{{ __('operator.outcomes.revenue') }}: {{ $revenueDisplay }}</li>
            </ul>
        @endif
        @if (! empty($causationDisclaimer))
            <p class="disclaimer">{{ $causationDisclaimer }}</p>
        @endif
    </div>

    @if (($limitations ?? []) !== [])
        <div class="section">
            <h2>{{ __('operator.reports.limitations') }}</h2>
            <ul>
                @foreach ($limitations as $limitation)
                    <li>{{ $limitation }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="footer">
        {{ __('operator.reports.pdf_footer_snapshot_only') }}
        · renderer {{ $rendererVersion }}
        · schema {{ $schemaVersion }}
    </div>
</body>
</html>
