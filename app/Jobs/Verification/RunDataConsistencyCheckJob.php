<?php

namespace App\Jobs\Verification;

use App\Services\Verification\DataConsistencyChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily data consistency check over stored facts ("Veri şüpheli"); no provider calls; heavy queue on Redis. */
final class RunDataConsistencyCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function handle(DataConsistencyChecker $checker): void
    {
        $checker->run();
    }
}
