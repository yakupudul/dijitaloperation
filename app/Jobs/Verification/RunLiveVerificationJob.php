<?php

namespace App\Jobs\Verification;

use App\Services\Verification\LiveVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily (and "Şimdi doğrula") read-only live verification of every connection; heavy queue on Redis. */
final class RunLiveVerificationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function handle(LiveVerifier $verifier): void
    {
        $verifier->run();
    }
}
