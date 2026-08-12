@php
    $open = $data['findings']['open'] ?? collect();
    $findingGroups = $data['finding_groups'] ?? [];
    $recommendations = $data['recommendations'] ?? collect();
    $ai = $data['ai_guidance'] ?? [];
    $attention = $data['attention'] ?? ['items' => []];
    $opening = $data['insights_opening'] ?? ['headline' => 'No critical issues', 'review_count' => 0];

    $themes = [
        'Performance' => [],
        'Delivery' => [],
        'Budget allocation' => [],
        'Creative' => [],
        'Measurement' => [],
        'Funnel' => [],
    ];

    foreach ($findingGroups as $group) {
        $hay = mb_strtolower(($group['title'] ?? '').' '.($group['sample_summary'] ?? ''));
        $bucket = match (true) {
            str_contains($hay, 'creative') || str_contains($hay, 'frequency') => 'Creative',
            str_contains($hay, 'budget') || str_contains($hay, 'spend') => 'Budget allocation',
            str_contains($hay, 'delivery') || str_contains($hay, 'impression') || str_contains($hay, 'reach') => 'Delivery',
            str_contains($hay, 'result') || str_contains($hay, 'pixel') || str_contains($hay, 'attribution') || str_contains($hay, 'measurement') => 'Measurement',
            str_contains($hay, 'funnel') || str_contains($hay, 'landing') => 'Funnel',
            default => 'Performance',
        };
        $themes[$bucket][] = $group;
    }
@endphp

<div class="mox-website-workspace mox-meta-workspace mox-meta-expert">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Insights</h3>
            <p class="mox-section-sub">Decision support from Findings and AI guidance — recommendations stay human-gated. No Meta writes.</p>
        </div>
    </div>

    @include('meta-ads::workspace.partials.filter-bar', ['data' => $data])

    <section class="mox-panel mox-insights-opening">
        <div class="mox-insights-opening__headline">{{ $opening['headline'] ?? 'No critical issues' }}</div>
        @if (($opening['review_count'] ?? 0) > 0)
            <p class="mox-muted">{{ $opening['review_count'] }} {{ str()->plural('item', $opening['review_count']) }} worth reviewing</p>
        @elseif (($opening['attention_count'] ?? 0) === 0)
            <p class="mox-muted">No high-confidence issues detected for this period.</p>
        @endif
    </section>

    @if (($attention['items'] ?? []) !== [])
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Priority attention</h4></div>
            <div class="mox-issue-cards">
                @foreach ($attention['items'] as $item)
                    <article class="mox-issue-card">
                        <span class="mox-severity mox-severity--{{ $item['severity'] }}">{{ strtoupper($item['severity']) }}</span>
                        <div>
                            <h5>{{ $item['title'] }}</h5>
                            <p>{{ $item['summary'] }}</p>
                            @if (! empty($item['campaign_name']))
                                <p class="mox-muted">Affected: {{ $item['campaign_name'] }}</p>
                            @endif
                            <p class="mox-footnote">{{ $item['inspect_label'] ?? 'Review in campaign drill-down' }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @foreach ($themes as $theme => $groups)
        @continue($groups === [])
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>{{ $theme }}</h4></div>
            <div class="mox-issue-cards">
                @foreach ($groups as $group)
                    <article class="mox-issue-card">
                        <span class="mox-sev mox-sev--{{ $group['severity'] }}">{{ strtoupper($group['severity']) }}</span>
                        <div>
                            <h5>
                                {{ $group['title'] }}
                                @if (($group['count'] ?? 1) > 1)
                                    <span class="mox-muted">· {{ $group['count'] }} related</span>
                                @endif
                            </h5>
                            <p>{{ \Illuminate\Support\Str::limit((string) $group['sample_summary'], 220) }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($open->isEmpty() && $findingGroups === [])
        <div class="mox-empty mox-empty--calm">No open Findings for this account right now.</div>
    @endif

    @if ($recommendations->isNotEmpty())
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Recommendations</h4></div>
            @foreach ($recommendations as $rec)
                <div class="mox-finding-row">
                    <span class="mox-sev mox-sev--{{ $rec->priority }}">{{ strtoupper($rec->priority) }}</span>
                    <div>
                        <div>{{ $rec->title }}</div>
                        <div class="mox-muted">{{ \Illuminate\Support\Str::limit((string) $rec->action, 180) }}</div>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <section class="mox-panel">
        <div class="mox-panel__head mox-panel__head--split">
            <div>
                <h4>AI guidance</h4>
                <span class="mox-badge-ai">Advisory only</span>
            </div>
            <button
                type="button"
                class="mox-btn mox-btn--secondary"
                wire:click="generateMetaAdsAiGuidanceFromWorkspace"
            >
                Generate analysis
            </button>
        </div>
        @if (! ($ai['available'] ?? false) && ($ai['failed'] ?? null) === null)
            <div class="mox-empty mox-empty--calm">
                No AI guidance yet for this account.
            </div>
        @else
            @if (($ai['failed'] ?? null) !== null)
                <p class="mox-muted">{{ $ai['failed']['message'] ?? 'Latest AI request failed.' }}</p>
            @endif

            @if ($ai['available'] ?? false)
                <p class="mox-muted" style="margin-bottom:0.75rem;">
                    {{ $ai['agent_name'] ?? 'Meta Ads Analyst' }}
                    @if (! empty($ai['generated_human'])) · {{ $ai['generated_human'] }} @endif
                </p>
                @if (! empty($ai['executive_summary']))
                    <p class="mox-insights-summary">{{ $ai['executive_summary'] }}</p>
                @endif

                @foreach ($ai['interpretations'] ?? [] as $interpretation)
                    <article class="mox-insight-card">
                        <header>
                            <span class="mox-sev mox-sev--{{ $interpretation['severity'] ?? 'medium' }}">
                                {{ strtoupper((string) ($interpretation['suggested_priority'] ?? $interpretation['severity'] ?? 'medium')) }}
                            </span>
                            <strong>{{ $interpretation['finding_title'] ?? 'Finding' }}</strong>
                        </header>
                        <dl class="mox-insight-structure">
                            <div><dt>Issue</dt><dd>{{ $interpretation['explanation'] ?? '—' }}</dd></div>
                            <div><dt>Why it matters</dt><dd>{{ $interpretation['business_relevance'] ?? '—' }}</dd></div>
                            @if (! empty($interpretation['evidence_ids']))
                                <div><dt>Evidence</dt><dd>#{{ implode(', #', $interpretation['evidence_ids']) }}</dd></div>
                            @endif
                            @if (! empty($interpretation['recommendation_draft']['action']))
                                <div><dt>Suggested action</dt><dd>{{ $interpretation['recommendation_draft']['action'] }}</dd></div>
                            @endif
                        </dl>
                        @if (($interpretation['can_accept'] ?? false) && ! empty($interpretation['recommendation_draft']))
                            <button
                                type="button"
                                class="mox-btn mox-btn--secondary"
                                wire:click="acceptAiRecommendationDraft({{ (int) $interpretation['finding_id'] }})"
                            >
                                Create recommendation
                            </button>
                        @elseif (! empty($interpretation['existing_recommendation']))
                            <p class="mox-muted">Recommendation already recorded ({{ $interpretation['existing_recommendation']->status }}).</p>
                        @endif
                    </article>
                @endforeach
            @endif
        @endif
    </section>
</div>
