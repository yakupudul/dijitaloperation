<?php

namespace Tests\Feature\Performance;

use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Jobs\RunChannelAnalystJob;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\QueueRoutes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;
use Throwable;

/**
 * Every queue a job can land on must be consumed by a Horizon supervisor in production, and every job must finish
 * well before the queue's retry_after — otherwise Redis hands the still-running job to a second worker (duplicate
 * run, then MaxAttemptsExceededException: RunQueryPipelineJob 3 500 s vs retry_after 900 s, RefreshUrlVerdictsJob
 * 900 s vs 900 s). A queue nobody consumes means jobs that are "queued, never built".
 */
#[Group('performance')]
final class QueueTopologyContractTest extends TestCase
{
    public function test_every_job_queue_is_consumed_and_every_timeout_fits_retry_after_in_production(): void
    {
        $production = $this->productionConfig();
        $supervisors = $this->productionSupervisors($production['horizon']);
        $consumed = [];
        foreach ($supervisors as $name => $supervisor) {
            foreach ((array) $supervisor['queue'] as $queue) {
                $consumed[$supervisor['connection'].':'.$queue] = $name;
            }
        }
        $retryAfter = (int) $production['queue']['connections']['redis']['retry_after'];

        foreach ($supervisors as $name => $supervisor) {
            $this->assertLessThan($retryAfter, (int) $supervisor['timeout'], $name.' timeout must stay below retry_after');
        }

        $this->useProductionQueueConfig($production);
        $this->assertSame('heavy', $production['queue']['heavy_queue'], 'with Redis the long jobs go to the heavy queue');

        $jobs = $this->jobClasses();
        $this->assertGreaterThan(25, count($jobs));
        $queues = [];
        foreach ($jobs as $class) {
            $job = $this->instantiate($class);
            $queue = (string) ($job->queue ?? 'default');
            $queues[$queue][] = $class;
            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
            if ($timeout !== null) {
                $this->assertLessThanOrEqual($retryAfter - 60, (int) $timeout, $class.': $timeout must stay at least 60 s below retry_after ('.$retryAfter.' s)');
            }
        }
        // Queues chosen at dispatch time (config) and the heartbeat probes.
        foreach ([$production['seo']['queue'], $production['advisor']['queue'], $production['queue']['heavy_queue'],
            (string) config('moxdop-external-writes.queue'), (string) config('moxdop-intelligence-scheduling.queue'), (string) config('moxdop-collection.queue'),
            ...(array) config('moxdop-observability.probe_queues')] as $queue) {
            $queues[$queue][] = 'config';
        }
        foreach ($this->literalQueues() as $queue) {
            $queues[$queue][] = 'literal onQueue()';
        }

        foreach ($queues as $queue => $users) {
            $this->assertArrayHasKey('redis:'.$queue, $consumed, 'queue "'.$queue.'" has no Horizon supervisor in production; used by '.implode(', ', array_unique($users)));
        }
        $this->assertArrayHasKey('heavy', $queues);
    }

    /**
     * An auto-scaling supervisor (any balance but "simple") scales down when its queue is empty and SIGKILLs a
     * terminating worker once the supervisor timeout has passed, busy or not (Horizon ProcessPool::scaleDown and
     * stopTerminatingProcessesThatAreHanging). A job longer than that timeout dies from outside at every scale-down
     * and ends in MaxAttemptsExceededException (RebuildWebsiteProjectionJob 900 s on supervisor-1 at 300 s).
     * A job lands where the Bus dispatcher puts it: its own connection / queue, else its Queue::route. Every Horizon
     * environment counts: the live server runs APP_ENV=staging (deploy/staging/deploy.sh).
     */
    public function test_every_job_on_an_auto_scaling_supervisor_finishes_within_the_supervisor_timeout(): void
    {
        $production = $this->productionConfig();
        $retryAfter = (int) $production['queue']['connections']['redis']['retry_after'];
        $this->useProductionQueueConfig($production);
        $jobs = [];
        foreach ($this->jobClasses() as $class) {
            $job = $this->instantiate($class);
            if (($job->timeout ?? null) !== null) {
                $jobs[$class] = ['destination' => $this->productionDestination($job, $production), 'timeout' => (int) $job->timeout];
            }
        }

        $this->assertNotEmpty($production['horizon']['environments']);
        foreach (array_keys($production['horizon']['environments']) as $environment) {
            $autoScaled = [];
            foreach ($this->productionSupervisors($production['horizon'], $environment) as $name => $supervisor) {
                if (($supervisor['balance'] ?? 'off') === 'simple') {
                    continue;
                }
                $this->assertLessThan($retryAfter, (int) $supervisor['timeout'], $environment.' '.$name.' timeout must stay below retry_after');
                foreach ((array) $supervisor['queue'] as $queue) {
                    $autoScaled[$supervisor['connection'].':'.$queue] = ['name' => $name, 'timeout' => (int) $supervisor['timeout']];
                }
            }
            $this->assertArrayHasKey('redis:default', $autoScaled, $environment.': supervisor-1 auto-scales the default queue');

            $checked = [];
            foreach ($jobs as $class => $job) {
                $supervisor = $autoScaled[$job['destination']] ?? null;
                if ($supervisor === null) {
                    continue;
                }
                $this->assertLessThan($supervisor['timeout'], $job['timeout'], $class.' ('.$job['timeout'].' s) lands on '.$job['destination'].': '
                    .$environment.' '.$supervisor['name'].' timeout ('.$supervisor['timeout'].' s) must be longer, or a scale-down kills it mid-run');
                $checked[] = $class;
            }
            $this->assertContains(RebuildWebsiteProjectionJob::class, $checked, $environment);
        }
    }

    /**
     * A server's .env starts as a copy of .env.staging.example (STAGING_RUNBOOK) and a value pinned there wins over the
     * config default the check above verifies: the example must not pin a shorter supervisor-1 timeout.
     */
    public function test_the_staging_env_example_does_not_pin_a_shorter_default_queue_timeout(): void
    {
        $production = $this->productionConfig();
        $example = File::get(base_path('.env.staging.example'));
        if (preg_match('/^HORIZON_DEFAULT_TIMEOUT=["\']?(\d+)["\']?\s*$/m', $example, $pinned) !== 1) {
            $this->assertStringNotContainsString('HORIZON_DEFAULT_TIMEOUT=', $example, 'not pinned, so the config default applies');

            return;
        }

        $this->assertGreaterThanOrEqual((int) $production['horizon']['environments']['staging']['supervisor-1']['timeout'], (int) $pinned[1],
            '.env.staging.example HORIZON_DEFAULT_TIMEOUT');
        $this->assertLessThan((int) $production['queue']['connections']['redis']['retry_after'], (int) $pinned[1], '.env.staging.example HORIZON_DEFAULT_TIMEOUT');
    }

    public function test_the_destination_check_resolves_queues_like_the_bus_dispatcher(): void
    {
        $production = $this->productionConfig();
        $this->app->instance('queue.routes', $routes = new QueueRoutes);
        Queue::fake();

        RunChannelAnalystJob::dispatch(1);
        Queue::assertPushed(RunChannelAnalystJob::class, fn (RunChannelAnalystJob $job, ?string $queue): bool => $job->runId === 1 && $queue === null);
        $this->assertSame('redis:default', $this->productionDestination(new RunChannelAnalystJob(1), $production), 'no queue, no route → the connection default');

        $routes->set([RunChannelAnalystJob::class => 'heavy', RebuildWebsiteProjectionJob::class => 'heavy']);
        RunChannelAnalystJob::dispatch(2);
        Queue::assertPushedOn('heavy', RunChannelAnalystJob::class, fn (RunChannelAnalystJob $job): bool => $job->runId === 2);
        $this->assertSame('redis:heavy', $this->productionDestination(new RunChannelAnalystJob(2), $production));

        RebuildWebsiteProjectionJob::dispatch(7);
        Queue::assertPushedOn('default', RebuildWebsiteProjectionJob::class);
        $this->assertSame('redis:default', $this->productionDestination(new RebuildWebsiteProjectionJob(7), $production), 'onQueue() wins over a route');
    }

    public function test_deploy_runs_horizon_under_supervisor_with_a_stop_window_longer_than_any_job(): void
    {
        $conf = File::get(base_path('deploy/staging/supervisor-horizon.conf.example'));
        $this->assertStringContainsString('artisan horizon', $conf);
        preg_match('/stopwaitsecs=(\d+)/', $conf, $m);
        $longest = 0;
        foreach ($this->jobClasses() as $class) {
            $longest = max($longest, (int) ((new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 0));
        }
        $this->assertGreaterThanOrEqual($longest, (int) ($m[1] ?? 0), 'a deploy lets the longest job finish before Horizon is stopped');
    }

    /** @return array{horizon: array<string, mixed>, queue: array<string, mixed>, seo: array<string, mixed>, advisor: array<string, mixed>} */
    private function productionConfig(): array
    {
        $saved = [$_ENV['QUEUE_CONNECTION'] ?? null, $_SERVER['QUEUE_CONNECTION'] ?? null, getenv('QUEUE_CONNECTION'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null, getenv('APP_ENV')];
        $set = function (string $key, ?string $value): void {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                return;
            }
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        };
        $set('QUEUE_CONNECTION', 'redis');
        $set('APP_ENV', 'production');
        try {
            return [
                'horizon' => require base_path('config/horizon.php'),
                'queue' => require base_path('config/queue.php'),
                'seo' => require base_path('config/moxdop-seo-tasks.php'),
                'advisor' => require base_path('config/moxdop-advisor.php'),
            ];
        } finally {
            $set('QUEUE_CONNECTION', $saved[0] ?? ($saved[2] !== false ? $saved[2] : null));
            $set('APP_ENV', $saved[3] ?? ($saved[5] !== false ? $saved[5] : null));
        }
    }

    /**
     * Horizon's ProvisioningPlan: every "defaults" supervisor runs in every environment, with that environment's
     * overrides on top (supervisor-background has no production key and still runs).
     *
     * @param  array<string, mixed>  $horizon
     * @return array<string, array<string, mixed>>
     */
    private function productionSupervisors(array $horizon, string $environment = 'production'): array
    {
        $this->assertArrayHasKey($environment, (array) ($horizon['environments'] ?? []), 'Horizon has '.$environment.' supervisors');
        $out = array_replace_recursive((array) ($horizon['defaults'] ?? []), (array) $horizon['environments'][$environment]);
        $this->assertNotEmpty($out, 'Horizon has '.$environment.' supervisors');

        return $out;
    }

    /**
     * Queue config as production resolves it, plus the production Queue::route table (AppServiceProvider registers it
     * only with the redis queue).
     *
     * @param  array{queue: array<string, mixed>, seo: array<string, mixed>, advisor: array<string, mixed>}  $production
     */
    private function useProductionQueueConfig(array $production): void
    {
        config([
            'queue.default' => $production['queue']['default'],
            'queue.heavy_queue' => $production['queue']['heavy_queue'],
            'queue.background_queue' => $production['queue']['background_queue'],
            'moxdop-seo-tasks.queue' => $production['seo']['queue'],
            'moxdop-advisor.queue' => $production['advisor']['queue'],
        ]);
        (new ReflectionMethod(AppServiceProvider::class, 'routeHeavyJobs'))->invoke($this->app->getProvider(AppServiceProvider::class));
    }

    /**
     * "connection:queue" a dispatched job lands on, resolved like Illuminate\Bus\Dispatcher: the job's own
     * connection / queue (onConnection / onQueue), else its Queue::route, else the connection's default queue.
     *
     * @param  array{queue: array<string, mixed>}  $production
     */
    private function productionDestination(object $job, array $production): string
    {
        $routes = $this->app->make('queue.routes');
        $connection = (string) ($job->connection ?? $routes->getConnection($job) ?? $production['queue']['default']);
        $queue = (string) ($job->queue ?? $routes->getQueue($job) ?? $production['queue']['connections'][$connection]['queue'] ?? 'default');

        return $connection.':'.$queue;
    }

    /** @return list<class-string> */
    private function jobClasses(): array
    {
        $classes = [];
        $roots = [base_path('app')];
        foreach (glob(base_path('app-modules/*/src')) ?: [] as $dir) {
            $roots[] = $dir;
        }
        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                $code = $file->getContents();
                if (! str_contains($code, 'ShouldQueue') || preg_match('/^namespace\s+([^;]+);/m', $code, $ns) !== 1
                    || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $code, $cls) !== 1) {
                    continue;
                }
                $class = $ns[1].'\\'.$cls[1];
                if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /** @return list<string> queue names given as literals: onQueue('…') */
    private function literalQueues(): array
    {
        $queues = [];
        foreach ([base_path('app'), base_path('routes'), ...(glob(base_path('app-modules/*/src')) ?: [])] as $root) {
            foreach (File::allFiles($root) as $file) {
                preg_match_all("/onQueue\\('([a-z0-9_\\-]+)'\\)/", $file->getContents(), $m);
                array_push($queues, ...$m[1]);
            }
        }

        return array_values(array_unique($queues));
    }

    private function instantiate(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        try {
            $args = [];
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                if ($parameter->isDefaultValueAvailable()) {
                    $args[] = $parameter->getDefaultValue();

                    continue;
                }
                $type = $parameter->getType();
                $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
                $args[] = match (true) {
                    $type?->allowsNull() === true => null,
                    $name === 'int' => 1,
                    $name === 'string' => 'x',
                    $name === 'bool' => false,
                    $name === 'float' => 1.0,
                    $name === 'array' => [],
                    class_exists($name) => new $name,
                    default => null,
                };
            }

            return $reflection->newInstanceArgs($args);
        } catch (Throwable) {
            return $reflection->newInstanceWithoutConstructor();
        }
    }
}
