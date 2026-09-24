<?php

namespace App\Console\Commands;

use App\Services\Assistant\RenewalService;
use Illuminate\Console\Command;

/**
 * moxdop:renewals:daily — domain (RDAP) and SSL expiry refresh for active websites, then due-renewal pushes.
 */
final class RenewalsDailyCommand extends Command
{
    protected $signature = 'moxdop:renewals:daily';

    protected $description = 'Refresh domain / SSL expiry dates and send renewal reminders.';

    public function handle(RenewalService $renewals): int
    {
        $stats = $renewals->daily();
        $this->line(sprintf('%d site; %d alan adı, %d SSL tarihi güncellendi; %d hatırlatma gönderildi.', $stats['sites'], $stats['domains_refreshed'], $stats['ssl_refreshed'], $stats['pushed']));

        return self::SUCCESS;
    }
}
