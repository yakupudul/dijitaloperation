<?php

return [

    'freshness_policy_registry_path' => env(
        'MOXDOP_DATA_FRESHNESS_POLICY_PATH',
        base_path('docs/data-contracts/MOXDOP_DATA_FRESHNESS_POLICY_V1.json')
    ),

    'freshness_policy_registry_id' => 'MOXDOP_DATA_FRESHNESS_POLICY',

    'supported_freshness_policy_versions' => [1],

    'numeric_freshness_score' => false,

    /*
    |--------------------------------------------------------------------------
    | Scheduler boundary (Prompt 27 / 61 / 62)
    |--------------------------------------------------------------------------
    |
    | Due query + StartIncrementalCollectionService are scheduler-callable.
    | Recurring cron ownership: Prompt 61 RecurringAutomationDispatcher
    | (moxdop:dispatch-due-automations) + Prompt 62 Collection Lifecycle Planner.
    | This flag documents that the shared recurring runtime is active.
    |
    */

    'recurring_scheduler_enabled' => true,

    'automatic_provider_reconciliation_after_incremental' => false,

];
