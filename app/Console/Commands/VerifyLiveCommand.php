<?php

namespace App\Console\Commands;

use App\Jobs\Verification\RunLiveVerificationJob;
use App\Services\Verification\LiveVerifier;
use Illuminate\Console\Command;

/** moxdop:verify:live — read-only proof that every integration and bound account still answers. */
final class VerifyLiveCommand extends Command
{
    protected $signature = 'moxdop:verify:live {--sync : Run now in this process instead of queueing}';

    protected $description = 'Verify every active integration and bound account with the cheapest read-only call (results in Sistem Sağlığı, failures in Komuta merkezi).';

    public function handle(LiveVerifier $verifier): int
    {
        if (! $this->option('sync')) {
            RunLiveVerificationJob::dispatch();
            $this->info('Live verification queued.');

            return self::SUCCESS;
        }
        $stats = $verifier->run();
        $this->info(sprintf('Live verification: %d ok, %d failed, %d skipped.', $stats['ok'], $stats['fail'], $stats['skipped']));

        return self::SUCCESS;
    }
}
