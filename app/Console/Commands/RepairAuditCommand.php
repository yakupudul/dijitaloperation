<?php

namespace App\Console\Commands;

use App\Services\Repair\SiteAudit;
use Illuminate\Console\Command;

/**
 * moxdop:repair:audit — nightly SEO title / description check of every indexable WordPress page (no AI); affected
 * pages get a suggestion that is prepared overnight and approved on the Onarım masası.
 */
final class RepairAuditCommand extends Command
{
    protected $signature = 'moxdop:repair:audit';

    protected $description = 'Check every page for missing, short, long or duplicate SEO titles and descriptions.';

    public function handle(SiteAudit $audit): int
    {
        $done = $audit->run();
        $this->line(sprintf('%d site denetlendi: %d sayfa için düzeltme açıldı, %d düzeltme sorun kalmadığı için kapandı.', $done['sites'], $done['opened'], $done['closed']));

        return self::SUCCESS;
    }
}
