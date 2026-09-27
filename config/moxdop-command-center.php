<?php

/*
|--------------------------------------------------------------------------
| Komuta merkezi (topic → assets inbox)
|--------------------------------------------------------------------------
|
| Topic keys are "source:rule" (see App\Services\CommandCenter\TopicCatalog). Patterns may end with "*"
| ("live:*" matches every live verification topic); a bare source ("system") matches that source's topics.
|
*/

return [

    'aging' => [
        /*
         * An item whose condition has been open continuously for this many days moves to "Uzun süredir devam
         * eden": out of the top badge, out of the dashboard "Önce bunlar" and no push. A materially changed item
         * (fingerprint) or one that disappeared and came back resurfaces with a fresh age.
         */
        'days' => (int) env('MOXDOP_COMMAND_CENTER_AGING_DAYS', 10),

        /* Critical conditions never age out: lost access, site down, security, failed publish, broken tagging. */
        'never_age' => [
            'coverage:lost',
            'coverage:reconnect',
            'live:*',
            'alert:site_down',
            'alert:renewal_due',
            'data:ads_untagged',
            'advisor:auto-tagging-off',
            'seo:site-noindex',
            'calendar:failed',
            'compliance',
            'system',
        ],

        /* People's own work (tasks, follow-ups, invoices, client answers…) never ages: it is a promise, not a signal. */
        'exempt_sources' => ['task', 'followup', 'invoice', 'commitment', 'client_approval', 'calendar', 'lead', 'approval'],
    ],

    'activity' => [
        /* Budget / spend topics hidden for an ad account whose activity tier is one of these or that is operator-paused. */
        'suppressed_tiers' => ['dormant'],

        'suppressed_topics' => [
            'alert:budget_exhausted',
            'alert:budget_low',
            'alert:budget_account_blocked',
            'alert:budget_no_spend_today',
            'alert:budget_campaign_capped',
            'alert:delivery_stopped',
            'alert:spend_spike',
            'alert:conversions_stopped',
            'alert:ads_disapproved',
            'advisor:spend-no-results',
            'advisor:budget-limited-profitable',
            'advisor:budget-waste',
            'advisor:daily-anomaly',
            'advisor:performance-anomaly',
            'advisor:primary-no-signal',
        ],
    ],

];
