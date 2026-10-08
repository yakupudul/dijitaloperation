<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DataStatus\ConnectionHealth;
use App\Support\Roles;
use Illuminate\Console\Command;

/**
 * moxdop:health:repair — nightly Bağlantı sağlığı repair: collects late accounts again and crawls sites that were never
 * (or not recently) crawled, as the first active admin. Only MoxDOP's own collection; nothing is written outside.
 */
final class ConnectionHealthRepairCommand extends Command
{
    protected $signature = 'moxdop:health:repair';

    protected $description = 'Restart collection of late accounts and crawl sites that are not crawled.';

    public function handle(ConnectionHealth $health): int
    {
        $admin = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            $this->error('Etkin yönetici yok.');

            return self::FAILURE;
        }
        $done = $health->repair($admin);
        $summary = $health->summary();
        $this->line(sprintf('%d hesabın veri çekimi, %d site taraması başlatıldı (%d başlatılamadı). Senin işin: %d satır.',
            $done['collections'], $done['crawls'], $done['failed'], $summary['operator']));

        return self::SUCCESS;
    }
}
