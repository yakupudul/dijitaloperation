<?php

namespace App\Jobs\Operations;

use App\Services\Operations\ScreenChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sayfa taraması in the background: after "Deploy tamamlandı" so Claude verifies against fresh screens. */
final class RunScreenChecksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(ScreenChecker $checker): void
    {
        $checker->run();
    }
}
