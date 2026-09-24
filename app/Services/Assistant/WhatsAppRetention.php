<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KVKK (Faz 10d): when Ayarlar › KVKK sets a retention period, WhatsApp message texts older than it are blanked;
 * conversation, direction and time stay so the contact history remains. Off by default.
 */
final class WhatsAppRetention
{
    public const string REDACTED = '[saklama süresi doldu]';

    public function run(): int
    {
        $days = Schema::hasColumn('agency_settings', 'whatsapp_retention_days') ? DB::table('agency_settings')->value('whatsapp_retention_days') : null;
        if ($days === null || (int) $days < 30 || ! Schema::hasTable('whatsapp_messages')) {
            return 0;
        }

        return DB::table('whatsapp_messages')->where('sent_at', '<', now()->subDays((int) $days))
            ->whereNotNull('body')->where('body', '!=', self::REDACTED)->update(['body' => self::REDACTED, 'updated_at' => now()]);
    }
}
