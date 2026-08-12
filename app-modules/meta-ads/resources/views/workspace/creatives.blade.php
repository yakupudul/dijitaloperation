@php
    use MoxDop\MetaAds\Support\MetaPercentage;

    /** @var array<string, mixed> $data */
    $creatives = $data['creatives'] ?? [];
    $needsAnalyze = (bool) ($data['needs_analyze'] ?? false);
    $periodMatched = (bool) ($data['period_matched'] ?? false);
    $async = $data['async_collection'] ?? null;
    $num = fn ($v, int $d = 0) => is_numeric($v) ? number_format((float) $v, $d) : '—';
    $pct = fn ($v) => is_numeric($v) ? MetaPercentage::format($v) : '—';
@endphp

<div class="mox-website-workspace mox-meta-workspace mox-meta-expert">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Creatives</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? '' }} · Preview references only — no media binaries stored</p>
        </div>
    </div>

    @include('meta-ads::workspace.partials.filter-bar', ['data' => $data])

    @if ($needsAnalyze || ! $periodMatched)
        <div class="mox-meta-analyze">
            <div>
                <h4>Creative performance for this period is not loaded</h4>
                <p class="mox-muted">Analyze the selected period to associate creatives with delivery metrics.</p>
            </div>
            @if (! $async)
                <button type="button" class="mox-btn mox-btn--primary" wire:click="analyzeMetaSelectedPeriod">Analyze this period</button>
            @endif
        </div>
    @elseif ($creatives === [])
        <div class="mox-empty">No creative metadata for this period.</div>
    @else
        <div class="mox-creative-grid">
            @foreach ($creatives as $row)
                <article class="mox-creative-card">
                    <div class="mox-creative-card__media">
                        @if (! empty($row['thumbnail_url']))
                            <img src="{{ $row['thumbnail_url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                        @else
                            <div class="mox-creative-placeholder">
                                {{ $row['object_type'] ?? 'Creative' }}
                            </div>
                        @endif
                    </div>
                    <div class="mox-creative-card__body">
                        <h4>{{ $row['creative_name'] ?? $row['ad_name'] ?? 'Creative' }}</h4>
                        @if (! empty($row['headline']))
                            <p class="mox-creative-card__headline">{{ $row['headline'] }}</p>
                        @endif
                        @if (! empty($row['primary_text_excerpt']))
                            <p class="mox-muted">{{ $row['primary_text_excerpt'] }}</p>
                        @endif
                        <p class="mox-meta-line">
                            @if (! empty($row['cta_type'])) CTA {{ $row['cta_type'] }} · @endif
                            @if (! empty($row['destination_domain'])) {{ $row['destination_domain'] }} · @endif
                            @if (! empty($row['object_type'])) {{ $row['object_type'] }} @endif
                        </p>
                        <dl class="mox-creative-metrics">
                            <div><dt>Spend</dt><dd>{{ $num($row['spend'] ?? null, 2) }}</dd></div>
                            <div><dt>Result</dt><dd>{{ $row['primary_result_human_label'] ?? '—' }}</dd></div>
                            <div><dt>Count</dt><dd>{{ $num($row['primary_result_count'] ?? null) }}</dd></div>
                            <div><dt>Cost / result</dt><dd>{{ $num($row['primary_result_cost'] ?? null, 2) }}</dd></div>
                            <div><dt>Link CTR</dt><dd>{{ $pct($row['inline_link_click_ctr'] ?? null) }}</dd></div>
                            <div><dt>Frequency</dt><dd>{{ $num($row['frequency'] ?? null, 2) }}</dd></div>
                        </dl>
                        @if (! empty($row['campaign_name']))
                            <p class="mox-footnote">{{ $row['campaign_name'] }}</p>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        <p class="mox-footnote">Frequency alone is not treated as creative fatigue. Deterioration claims require Finding / Evidence support.</p>
    @endif
</div>
