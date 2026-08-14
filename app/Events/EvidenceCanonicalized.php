<?php

namespace App\Events;

use App\Models\DigitalAsset;
use App\Models\Run;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after canonical Evidence writes commit. Finding evaluation is queued separately
 * so Evidence creation never fails because Finding evaluation failed.
 */
final class EvidenceCanonicalized implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly DigitalAsset $asset,
        public readonly Run $run,
    ) {}
}
