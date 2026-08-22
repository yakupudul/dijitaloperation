<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of this application so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:collection' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs. This setting may be used to remove noisy jobs from
    | the dashboard.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with the
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option is
    | provided.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => (int) env('HORIZON_DEFAULT_TIMEOUT', 300),
            'nice' => 0,
        ],
        // Collection engine — infrastructure workers only (not product UI).
        'supervisor-collection' => [
            'connection' => 'redis',
            'queue' => ['collection'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => (int) env('HORIZON_COLLECTION_TIMEOUT', 300),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', 5),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'timeout' => (int) env('HORIZON_DEFAULT_TIMEOUT', 300),
            ],
            'supervisor-collection' => [
                'maxProcesses' => (int) env('HORIZON_COLLECTION_MAX_PROCESSES', 3),
                'timeout' => (int) env('HORIZON_COLLECTION_TIMEOUT', 300),
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 2,
                'timeout' => (int) env('HORIZON_DEFAULT_TIMEOUT', 300),
            ],
            'supervisor-collection' => [
                'maxProcesses' => 1,
                'timeout' => (int) env('HORIZON_COLLECTION_TIMEOUT', 300),
            ],
        ],

        /*
         * Staging / UAT must provision workers. Horizon only starts supervisors whose
         * environment key matches APP_ENV. Without these keys, `php artisan horizon`
         * would start and then idle with zero processes.
         */
        'staging' => [
            'supervisor-1' => [
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', 2),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'timeout' => (int) env('HORIZON_DEFAULT_TIMEOUT', 300),
            ],
            'supervisor-collection' => [
                // Three concurrent collection workers keeps multi-property GA4 imports responsive
                // while remaining conservative against provider quotas on the staging VPS.
                'maxProcesses' => (int) env('HORIZON_COLLECTION_MAX_PROCESSES', 3),
                'timeout' => (int) env('HORIZON_COLLECTION_TIMEOUT', 300),
            ],
        ],

        'uat' => [
            'supervisor-1' => [
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', 2),
                'timeout' => (int) env('HORIZON_DEFAULT_TIMEOUT', 300),
            ],
            'supervisor-collection' => [
                'maxProcesses' => (int) env('HORIZON_COLLECTION_MAX_PROCESSES', 1),
                'timeout' => (int) env('HORIZON_COLLECTION_TIMEOUT', 300),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
