<?php

namespace App\Console\Commands\Observability;

use App\Services\Observability\WorkerHeartbeatService;
use Illuminate\Console\Command;

final class WorkerHeartbeatCommand extends Command
{
    protected $signature = 'moxdop:ops:worker-heartbeat
        {--worker-id= : Worker identifier}
        {--supervisor=default : Supervisor class name}
        {--queue-class=NORMAL_INCREMENTAL : Workload class}';

    protected $description = 'Record a worker heartbeat (Prompt 66)';

    public function handle(WorkerHeartbeatService $heartbeats): int
    {
        $id = (string) ($this->option('worker-id') ?: ('cli-'.gethostname().'-'.getmypid()));
        $heartbeats->beat(
            workerId: $id,
            supervisor: (string) $this->option('supervisor'),
            queueClass: (string) $this->option('queue-class'),
        );
        $this->info('heartbeat recorded for '.$id);

        return self::SUCCESS;
    }
}
