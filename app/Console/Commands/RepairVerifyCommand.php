<?php

namespace App\Console\Commands;

use App\Services\Repair\RepairVerifier;
use Illuminate\Console\Command;

/**
 * moxdop:repair:verify — checks applied website fixes a day after the write and sends failed ones back to the desk.
 */
final class RepairVerifyCommand extends Command
{
    protected $signature = 'moxdop:repair:verify';

    protected $description = 'Verify applied repair-desk fixes against the site and reopen failed writes.';

    public function handle(RepairVerifier $verifier): int
    {
        $done = $verifier->run();
        $this->line(sprintf('%d düzeltme doğrulandı, %d düzeltme sitede görünmüyor, %d başarısız yazma masaya geri döndü.', $done['confirmed'], $done['still_seen'], $done['reopened']));

        return self::SUCCESS;
    }
}
