<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prompt 63 — Intelligence Scheduling
    |--------------------------------------------------------------------------
    */

    'enabled' => env('MOXDOP_INTELLIGENCE_SCHEDULING_ENABLED', true),

    'dispatch_async' => env('MOXDOP_INTELLIGENCE_SCHEDULING_ASYNC', true),

    'queue' => env('MOXDOP_INTELLIGENCE_SCHEDULING_QUEUE', 'default'),

    'max_ai_fanout_per_plan' => (int) env('MOXDOP_INTELLIGENCE_MAX_AI_FANOUT', 3),

    /*
    | Automatic AI never silently uses "latest" Agent/Skill/Route.
    | Policies must pin exact versions.
    */

    'forbid_latest_version_tokens' => true,

    /*
    | Forbidden recursive edges (documented + enforced by planner):
    | - AI result → Agent
    | - AI candidate → Agent
    | - Opportunity → Finding
    | - Task/Activity/Notification → Intelligence
    */

    'forbidden_trigger_sources' => [
        'ACTIVITY',
        'NOTIFICATION',
        'TASK',
        'AGENT_RESULT',
        'AI_CANDIDATE',
        'COLLECTION_RUN_COMPLETED',
        'RECOMMENDATION',
        'APPROVAL',
        'QA',
    ],
];
