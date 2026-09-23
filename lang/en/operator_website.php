<?php

return [
    'title' => 'Website',
    'nav_label' => 'Website workspace',

    'tabs' => [
        'overview' => 'Overview',
        'seo' => 'SEO Görevleri',
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
];
