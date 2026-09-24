<?php

namespace App\Console\Commands;

use App\Services\Assistant\WhatsAppRetention;
use Illuminate\Console\Command;

/**
 * moxdop:whatsapp:retention — blank WhatsApp message texts older than the KVKK retention period (if set).
 */
final class WhatsAppRetentionCommand extends Command
{
    protected $signature = 'moxdop:whatsapp:retention';

    protected $description = 'Blank WhatsApp message texts older than the retention period set in Ayarlar › KVKK.';

    public function handle(WhatsAppRetention $retention): int
    {
        $this->info(sprintf('%d mesaj metni silindi.', $retention->run()));

        return self::SUCCESS;
    }
}
