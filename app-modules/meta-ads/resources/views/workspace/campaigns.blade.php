@php
    use MoxDop\MetaAds\Support\MetaPercentage;

    $campaigns = $data['campaigns'] ?? [];
    $adsets = $data['adsets'] ?? [];
    $ads = $data['ads'] ?? [];
    $creatives = $data['creatives'] ?? [];
    $needsAnalyze = (bool) ($data['needs_analyze'] ?? false);
    $periodMatched = (bool) ($data['period_matched'] ?? false);
    $async = $data['async_collection'] ?? null;
    $showExpert = (bool) ($data['filters']['expert_columns'] ?? false);

    $num = fn ($v, int $decimals = 0) => is_numeric($v) ? number_format((float) $v, $decimals) : '—';
    $pct = fn ($v) => is_numeric($v) ? MetaPercentage::format($v) : '—';

    $creativesById = collect($creatives)->keyBy('creative_id');
@endphp

<div
    class="mox-website-workspace mox-meta-workspace mox-meta-expert mox-meta-drill"
    x-data="{
        view: 'list',
        campaignId: null,
        adsetId: null,
        adId: null,
        openCampaign(id) { this.campaignId = id; this.adsetId = null; this.adId = null; this.view = 'campaign'; },
        openAdset(id) { this.adsetId = id; this.adId = null; this.view = 'adset'; },
        openAd(id) { this.adId = id; this.view = 'ad'; },
        back() {
            if (this.view === 'ad') { this.view = 'adset'; this.adId = null; return; }
            if (this.view === 'adset') { this.view = 'campaign'; this.adsetId = null; return; }
            if (this.view === 'campaign') { this.view = 'list'; this.campaignId = null; }
        }
    }"
>
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Campaigns</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? '' }} · Default: delivered in selected period</p>
        </div>
        <label class="mox-meta-inline-select">
            <input type="checkbox" wire:change="setMetaWorkspaceFilter('expert_columns', $event.target.checked)" @checked($showExpert)>
            <span>Expert columns</span>
        </label>
    </div>

    @include('meta-ads::workspace.partials.filter-bar', ['data' => $data])

    @if ($needsAnalyze || ! $periodMatched)
        <div class="mox-meta-analyze">
            <div>
                <h4>Campaign metrics for this period are not loaded</h4>
                <p class="mox-muted">Avoiding stale numbers from another date range.</p>
            </div>
            @if (! $async)
                <button type="button" class="mox-btn mox-btn--primary" wire:click="analyzeMetaSelectedPeriod">Analyze this period</button>
            @endif
        </div>
    @elseif ($campaigns === [])
        <div class="mox-empty">No delivered campaigns match the current filters.</div>
    @else
        <div x-show="view === 'list'" x-cloak>
            <div class="mox-table-wrap">
                <table class="mox-table mox-table--campaigns mox-table--interactive">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Status</th>
                            <th class="mox-num">Spend</th>
                            <th>Primary result</th>
                            <th class="mox-num">Results</th>
                            <th class="mox-num">Link CTR</th>
                            @if ($showExpert)
                                <th class="mox-num">Reach</th>
                                <th class="mox-num">Frequency</th>
                                <th class="mox-num">All clicks CTR</th>
                            @endif
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $row)
                            @php $cid = (string) ($row['campaign_id'] ?? $row['entity_id'] ?? ''); @endphp
                            <tr class="mox-table-row--clickable" x-on:click="openCampaign('{{ $cid }}')">
                                <td>
                                    <strong>{{ $row['name'] ?? '—' }}</strong>
                                    <div class="mox-muted">{{ $row['objective'] ?? '' }}</div>
                                </td>
                                <td>
                                    <span class="mox-status-pill mox-status-pill--{{ strtolower((string) ($row['effective_status'] ?? $row['status'] ?? 'unknown')) }}">
                                        {{ $row['effective_status'] ?? $row['status'] ?? '—' }}
                                    </span>
                                </td>
                                <td class="mox-num">{{ $num($row['spend'] ?? null, 2) }}</td>
                                <td>{{ $row['primary_result_human_label'] ?? ($row['primary_result_status'] ?? '—') }}</td>
                                <td class="mox-num">{{ $num($row['primary_result_count'] ?? null) }}</td>
                                <td class="mox-num">{{ $pct($row['inline_link_click_ctr'] ?? null) }}</td>
                                @if ($showExpert)
                                    <td class="mox-num">{{ $num($row['reach'] ?? null) }}</td>
                                    <td class="mox-num">{{ $num($row['frequency'] ?? null, 2) }}</td>
                                    <td class="mox-num">{{ $pct($row['ctr'] ?? null) }}</td>
                                @endif
                                <td><span class="mox-drill-chevron">›</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @foreach ($campaigns as $row)
            @php
                $cid = (string) ($row['campaign_id'] ?? $row['entity_id'] ?? '');
                $childAdsets = collect($adsets)->filter(fn ($a) => (string) ($a['campaign_id'] ?? '') === $cid)->values();
            @endphp
            <div x-show="view === 'campaign' && campaignId === '{{ $cid }}'" x-cloak class="mox-drill-panel">
                <button type="button" class="mox-btn mox-btn--ghost" x-on:click="back()">← Back to campaigns</button>
                <header class="mox-drill-header">
                    <div>
                        <h4>{{ $row['name'] ?? 'Campaign' }}</h4>
                        <p class="mox-muted">{{ $row['objective'] ?? '—' }} · {{ $data['period_label'] ?? '' }}</p>
                    </div>
                    <span class="mox-status-pill mox-status-pill--{{ strtolower((string) ($row['effective_status'] ?? $row['status'] ?? 'unknown')) }}">
                        {{ $row['effective_status'] ?? $row['status'] ?? '—' }}
                    </span>
                </header>
                <div class="mox-kpi-grid mox-kpi-grid--compact">
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Spend</div><div class="mox-kpi-card__value">{{ $num($row['spend'] ?? null, 2) }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Primary result</div><div class="mox-kpi-card__value mox-kpi-card__value--compact">{{ $row['primary_result_human_label'] ?? '—' }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Results</div><div class="mox-kpi-card__value">{{ $num($row['primary_result_count'] ?? null) }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Cost / result</div><div class="mox-kpi-card__value">{{ $num($row['primary_result_cost'] ?? null, 2) }}</div></div>
                </div>
                <h5 class="mox-drill-subtitle">Ad sets</h5>
                @if ($childAdsets->isEmpty())
                    <div class="mox-empty">No ad set rows for this campaign in the selected period.</div>
                @else
                    <div class="mox-table-wrap">
                        <table class="mox-table mox-table--interactive">
                            <thead>
                                <tr>
                                    <th>Ad set</th>
                                    <th>Status</th>
                                    <th class="mox-num">Spend</th>
                                    <th>Primary result</th>
                                    <th class="mox-num">Results</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($childAdsets as $adset)
                                    @php $asid = (string) ($adset['adset_id'] ?? $adset['entity_id'] ?? ''); @endphp
                                    <tr class="mox-table-row--clickable" x-on:click="openAdset('{{ $asid }}')">
                                        <td>{{ $adset['name'] ?? '—' }}</td>
                                        <td><span class="mox-status-pill mox-status-pill--{{ strtolower((string) ($adset['effective_status'] ?? $adset['status'] ?? 'unknown')) }}">{{ $adset['effective_status'] ?? $adset['status'] ?? '—' }}</span></td>
                                        <td class="mox-num">{{ $num($adset['spend'] ?? null, 2) }}</td>
                                        <td>{{ $adset['primary_result_human_label'] ?? ($adset['primary_result_status'] ?? '—') }}</td>
                                        <td class="mox-num">{{ $num($adset['primary_result_count'] ?? null) }}</td>
                                        <td><span class="mox-drill-chevron">›</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach

        @foreach ($adsets as $adset)
            @php
                $asid = (string) ($adset['adset_id'] ?? $adset['entity_id'] ?? '');
                $childAds = collect($ads)->filter(fn ($a) => (string) ($a['adset_id'] ?? '') === $asid)->values();
            @endphp
            <div x-show="view === 'adset' && adsetId === '{{ $asid }}'" x-cloak class="mox-drill-panel">
                <button type="button" class="mox-btn mox-btn--ghost" x-on:click="back()">← Back to campaign</button>
                <header class="mox-drill-header">
                    <div>
                        <h4>{{ $adset['name'] ?? 'Ad set' }}</h4>
                        <p class="mox-muted">{{ $adset['optimization_goal'] ?? '—' }} · {{ $adset['destination_type'] ?? '—' }}</p>
                    </div>
                </header>
                <div class="mox-kpi-grid mox-kpi-grid--compact">
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Spend</div><div class="mox-kpi-card__value">{{ $num($adset['spend'] ?? null, 2) }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Primary result</div><div class="mox-kpi-card__value mox-kpi-card__value--compact">{{ $adset['primary_result_human_label'] ?? '—' }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Link CTR</div><div class="mox-kpi-card__value">{{ $pct($adset['inline_link_click_ctr'] ?? null) }}</div></div>
                </div>
                <h5 class="mox-drill-subtitle">Ads</h5>
                @if ($childAds->isEmpty())
                    <div class="mox-empty">No ads for this ad set in the selected period.</div>
                @else
                    <div class="mox-ad-cards">
                        @foreach ($childAds as $ad)
                            @php
                                $adid = (string) ($ad['ad_id'] ?? $ad['entity_id'] ?? '');
                                $creative = $creativesById->get($ad['creative_id'] ?? '');
                            @endphp
                            <article class="mox-ad-card mox-table-row--clickable" x-on:click="openAd('{{ $adid }}')">
                                <div>
                                    <strong>{{ $ad['name'] ?? '—' }}</strong>
                                    <p class="mox-muted">{{ $ad['primary_result_human_label'] ?? '—' }} · Spend {{ $num($ad['spend'] ?? null, 2) }}</p>
                                </div>
                                <span class="mox-drill-chevron">›</span>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        @foreach ($ads as $ad)
            @php
                $adid = (string) ($ad['ad_id'] ?? $ad['entity_id'] ?? '');
                $creative = $creativesById->get($ad['creative_id'] ?? '');
            @endphp
            <div x-show="view === 'ad' && adId === '{{ $adid }}'" x-cloak class="mox-drill-panel">
                <button type="button" class="mox-btn mox-btn--ghost" x-on:click="back()">← Back to ad set</button>
                <header class="mox-drill-header">
                    <div>
                        <h4>{{ $ad['name'] ?? 'Ad' }}</h4>
                        <p class="mox-muted">{{ $ad['campaign_name'] ?? '' }} · {{ $ad['adset_name'] ?? '' }}</p>
                    </div>
                </header>
                <div class="mox-kpi-grid mox-kpi-grid--compact">
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Spend</div><div class="mox-kpi-card__value">{{ $num($ad['spend'] ?? null, 2) }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Primary result</div><div class="mox-kpi-card__value mox-kpi-card__value--compact">{{ $ad['primary_result_human_label'] ?? '—' }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Link CTR</div><div class="mox-kpi-card__value">{{ $pct($ad['inline_link_click_ctr'] ?? null) }}</div></div>
                    <div class="mox-kpi-card"><div class="mox-kpi-card__label">Frequency</div><div class="mox-kpi-card__value">{{ $num($ad['frequency'] ?? null, 2) }}</div></div>
                </div>
                @if (is_array($creative))
                    <section class="mox-panel mox-creative-detail">
                        <div class="mox-panel__head"><h4>Creative</h4></div>
                        <div class="mox-creative-detail__grid">
                            <div class="mox-creative-card__media">
                                @if (! empty($creative['thumbnail_proxy_url']))
                                    <img src="{{ $creative['thumbnail_proxy_url'] }}" alt="" loading="lazy" x-on:error="$el.replaceWith($refs.placeholder)">
                                    <div class="mox-creative-placeholder" x-ref="placeholder" style="display:none">{{ $creative['object_type'] ?? 'Creative' }}</div>
                                @else
                                    <div class="mox-creative-placeholder">{{ $creative['object_type'] ?? 'Creative' }}</div>
                                @endif
                            </div>
                            <div>
                                <h5>{{ $creative['creative_name'] ?? 'Creative' }}</h5>
                                @if (! empty($creative['headline']))
                                    <p class="mox-creative-card__headline">{{ $creative['headline'] }}</p>
                                @endif
                                @if (! empty($creative['primary_text_excerpt']))
                                    <p class="mox-muted">{{ $creative['primary_text_excerpt'] }}</p>
                                @endif
                                <p class="mox-meta-line">
                                    @if (! empty($creative['cta_type'])) CTA {{ $creative['cta_type'] }} · @endif
                                    @if (! empty($creative['destination_domain'])) {{ $creative['destination_domain'] }} @endif
                                </p>
                            </div>
                        </div>
                    </section>
                @endif
            </div>
        @endforeach
    @endif
</div>
