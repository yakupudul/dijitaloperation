<?php

namespace App\Jobs\Site;

use App\Models\BacklinkSource;
use App\Services\Site\Backlinks\BacklinkVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Checks one potential source's page for a link to the brand right after the operator marks it "verildi". */
final class VerifyBacklinkSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(BacklinkVerifier $verifier): void
    {
        $source = BacklinkSource::query()->with('brand')->find($this->sourceId);
        if ($source !== null) {
            $verifier->verify($source);
        }
    }
}
