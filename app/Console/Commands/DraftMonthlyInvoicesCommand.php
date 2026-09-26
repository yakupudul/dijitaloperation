<?php

namespace App\Console\Commands;

use App\Services\Agency\AgencyOperations;
use Illuminate\Console\Command;

/** moxdop:invoices:draft-monthly — a draft invoice per active customer with a monthly fee (internal record). */
final class DraftMonthlyInvoicesCommand extends Command
{
    protected $signature = 'moxdop:invoices:draft-monthly {--period= : Y-m, defaults to this month}';

    protected $description = 'Create this month\'s draft invoices from each active customer\'s monthly fee.';

    public function handle(AgencyOperations $operations): int
    {
        $this->info($operations->draftMonthlyInvoices($this->option('period') ?: null).' draft invoice(s) created.');

        return self::SUCCESS;
    }
}
