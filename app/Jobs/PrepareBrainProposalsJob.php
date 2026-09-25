<?php

namespace App\Jobs;

use App\Services\Brain\Proposals\ProposalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Brain: prepares one kind of proposal for the review queue, started by an "AI ile hazırla" click. */
final class PrepareBrainProposalsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** @param  array<string, mixed>  $options */
    public function __construct(public string $kind, public array $options = []) {}

    public function handle(ProposalService $proposals): void
    {
        $proposals->prepare($this->kind, $this->options);
    }
}
