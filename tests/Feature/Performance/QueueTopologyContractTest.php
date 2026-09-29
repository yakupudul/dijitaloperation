<?php

namespace Tests\Feature\Performance;

use App\Jobs\BuildTopicMapJob;
use App\Jobs\Queries\IngestQuerySourcesJob;
use App\Jobs\RefreshUrlVerdictsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
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

        config([
            'queue.heavy_queue' => $production['queue']['heavy_queue'],
            'moxdop-seo-tasks.queue' => $production['seo']['queue'],
            'moxdop-advisor.queue' => $production['advisor']['queue'],
        ]);
        $this->assertSame('heavy', $production['queue']['heavy_queue'], 'with Redis the long jobs go to the heavy queue');

        $jobs = $this->jobClasses();
        $this->assertGreaterThan(50, count($jobs));
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
        $this->assertContains(BuildTopicMapJob::class, $queues['heavy'], 'the topic map runs on the heavy queue');
        $this->assertContains(RefreshUrlVerdictsJob::class, $queues['heavy']);
        $this->assertContains(IngestQuerySourcesJob::class, $queues['heavy']);
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
     * @param  array<string, mixed>  $horizon
     * @return array<string, array<string, mixed>>
     */
    private function productionSupervisors(array $horizon): array
    {
        $out = [];
        foreach ((array) ($horizon['environments']['production'] ?? []) as $name => $overrides) {
            $out[$name] = array_replace((array) ($horizon['defaults'][$name] ?? []), (array) $overrides);
        }
        $this->assertNotEmpty($out, 'Horizon has production supervisors');

        return $out;
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
