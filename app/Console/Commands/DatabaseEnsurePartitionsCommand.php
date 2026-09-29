<?php

namespace App\Console\Commands;

use App\Services\DataPool\PartitionManager;
use Illuminate\Console\Command;

/**
 * moxdop:db:ensure-partitions — keeps every monthly partitioned fact table writable: the current month plus the
 * next N months get their partition ahead of time, and each parent gets a DEFAULT partition as a safety net (rows
 * of a month without a partition land there instead of failing the collection write).
 * Scheduled daily; idempotent; no-op off PostgreSQL.
 */
final class DatabaseEnsurePartitionsCommand extends Command
{
    protected $signature = 'moxdop:db:ensure-partitions
        {--months=3 : Kaç ay ilerisi hazırlansın}
        {--back=0 : Kaç ay gerisi de hazırlansın (geçmiş aktarım için)}';

    protected $description = 'Aylık bölümlenmiş veri tablolarında önümüzdeki ayların bölümlerini ve DEFAULT güvenlik bölümünü hazırlar.';

    public function handle(PartitionManager $partitions): int
    {
        if (! $partitions->isPartitioningSupported()) {
            $this->line('Yalnız PostgreSQL için; atlandı.');

            return self::SUCCESS;
        }
        $result = $partitions->ensureAhead(max(0, (int) $this->option('months')), max(0, (int) $this->option('back')));
        $this->info(sprintf('%d bölümlenmiş tablo hazır · yeni DEFAULT bölüm: %d', $result['tables'], $result['defaults_created']));

        return self::SUCCESS;
    }
}
