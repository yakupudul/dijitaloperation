<?php

namespace App\Console\Commands;

use App\Services\Queries\PendingQueries;
use Illuminate\Console\Command;

/**
 * moxdop:queries:prune-pending — Bekleyenler cleanup: drops pending queries already in the library (normalized text)
 * or caught by a current filter term. Hourly on the schedule; also runs after every pipeline run and filter change.
 */
final class QueriesPrunePendingCommand extends Command
{
    protected $signature = 'moxdop:queries:prune-pending';

    protected $description = 'Bekleyen sorgulardan kütüphanede olanları ve filtre terimine takılanları temizler.';

    public function handle(PendingQueries $pending): int
    {
        $this->info(sprintf('%d bekleyen sorgu temizlendi.', $pending->prune()));

        return self::SUCCESS;
    }
}
