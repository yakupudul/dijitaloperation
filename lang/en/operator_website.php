<?php

return [
    'title' => 'Website',
    'nav_label' => 'Website workspace',

    'tabs' => [
        'overview' => 'Overview',
        'seo' => 'SEO Görevleri',
        'scorecard' => 'Page Scorecard',
        'search_console' => 'Search Console',
        'ga4_analysis' => 'Google Analytics',
        'content' => 'Pages & Content',
        'health' => 'Site Health',
        'standards' => 'Standards',
        'infrastructure' => 'Infrastructure & WordPress',
        'setup' => 'Data Sources',
    ],

    'actions' => [
        'refresh_seo' => 'Refresh SEO visibility',
    ],

    'kpi_fallback' => [
        'organic_search' => 'Organic search',
        'analytics' => 'Google Analytics',
        'findings' => 'Findings',
        'recommendations' => 'Recommendations',
    ],

    'overview' => [
        'no_data_title' => 'Needs attention · this website has no Google Analytics / Search Console data yet',
        'no_data_body' => 'Bind a data source and start a data collection; KPIs appear as data arrives.',
        'awaiting_data' => 'Waiting for data',
        'inventory_body' => 'Inventory is built only from collected website and Site Connector data.',
        'open_findings' => 'Open findings',
        'open_findings_hint' => 'Findings recorded for this website',
        'no_open_findings' => 'No open findings yet.',
        'recommendations' => 'Recommendations',
        'recommendations_hint' => 'Action recommendations derived from findings',
        'no_recommendations' => 'No recommendations yet.',
        'all' => 'View all →',
    ],

    'severity' => [
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
        'info' => 'Info',
        'warning' => 'Warning',
    ],

    'priority' => [
        'critical' => 'Critical priority',
        'high' => 'High priority',
        'medium' => 'Medium priority',
        'low' => 'Low priority',
    ],

    'finding_status' => [
        'open' => 'Open',
        'acknowledged' => 'Under review',
        'resolved' => 'Resolved',
    ],

    'recommendation_status' => [
        'open' => 'Open',
        'accepted' => 'Accepted',
        'dismissed' => 'Dismissed',
        'converted' => 'Converted to task',
    ],

    'fact_notes' => [
        'pages_content' => 'This tab shows collected and merged data. Problem and opportunity interpretations appear in the findings and recommendations lists on the Overview tab, with evidence links.',
        'technical_health' => 'Site Health shows collected external site facts and deterministic observations. Findings, prioritization, and recommended actions are listed on the Overview tab and the Findings page.',
        'infrastructure' => 'This tab keeps Website configuration, WordPress inside state, and external infrastructure observations as separate source facts. WordPress site health and update records are raw observations; findings, priority, and recommendations are listed on the Overview tab.',
    ],

    'search_console' => [
        'workflow_title' => 'Findings → Recommendations → Tasks',
        'external_resource' => 'Bound resource',
        'search_types' => 'Search types',
        'site_totals' => 'Site totals',
        'site_totals_value' => 'Daily property totals',
    ],

    'health_score' => [
        'title' => 'Site health score',
        'subtitle' => 'One score computed from collected crawl data; no new crawl or external call is made.',
        'score_label' => 'Score',
        'grades' => [
            'good' => 'Good',
            'fair' => 'Needs work',
            'poor' => 'Poor',
        ],
        'pages_checked' => 'Pages checked',
        'collected_at' => 'Latest crawl / collection',
        'crawl_count' => 'Stored crawls',
        'source_projection' => 'No raw crawl rows found; the score was computed from the projected page profiles as a single crawl.',
        'trend_title' => 'Score trend',
        'trend_hint' => 'Scores of the last :count crawls',
        'trend_hidden' => 'A trend needs at least two crawls; only one crawl is stored so far.',
        'change' => ':value points vs previous crawl',
        'no_change' => 'No change vs previous crawl',
        'formula_title' => 'How is the score calculated?',
        'formula' => 'Score = 100 − Σ (weight × share of affected pages). Weights: Critical :critical, High :high, Medium :medium, Low :low. The share is the number of checked pages with at least one rule issue of that severity divided by checked pages. Duplicate title/description, orphan page and broken internal link groups are informational and not scored.',
        'summary' => [
            'broken_pages' => 'Broken pages (4xx/5xx)',
            'broken_links' => 'Broken internal links',
            'redirects' => 'Redirect chains',
            'orphans' => 'Orphan pages',
            'duplicates' => 'Pages with duplicate titles',
        ],
        'unavailable' => [
            'links' => 'Not computed: no internal link graph has been collected.',
            'duplicates' => 'Not computed: no title / meta description data has been collected.',
        ],
        'groups_title' => 'Issues',
        'groups_hint' => 'Current state grouped by rule or issue type. Click a row to list the affected URLs.',
        'columns' => [
            'issue' => 'Issue',
            'severity' => 'Severity',
            'affected' => 'Affected URLs',
            'share' => 'Share of pages',
            'new' => 'New in last crawl',
        ],
        'not_scored' => 'Not scored',
        'new_unknown' => '—',
        'url_limit' => 'Showing the first :limit URLs (of :total). Download the CSV for all.',
        'no_issues' => 'No issues in the current crawl.',
        'export_csv' => 'Download CSV',
        'redirect_steps' => ':count redirect steps',
        'kinds' => [
            'broken' => 'Broken',
            'redirect' => 'Redirect',
            'rule' => 'Rule',
            'duplicate' => 'Duplicate',
            'orphan' => 'Orphan',
        ],
        'codes' => [
            'DUPLICATE_TITLE' => 'Duplicate title (pages sharing a title)',
            'DUPLICATE_META_DESCRIPTION' => 'Duplicate meta description',
            'ORPHAN_PAGE' => 'Orphan page (no internal inlinks)',
            'BROKEN_INTERNAL_LINK' => 'Internal link to a 4xx/5xx page',
        ],
        'csv' => [
            'filename' => 'site-health',
            'code' => 'Code',
            'issue' => 'Issue',
            'severity' => 'Severity',
            'scored' => 'Scored',
            'url_count' => 'Affected URL count',
            'share' => 'Share of pages (%)',
            'new_count' => 'New (last crawl)',
            'url' => 'URL',
            'detail' => 'Detail',
            'yes' => 'Yes',
            'no' => 'No',
        ],
    ],
];
