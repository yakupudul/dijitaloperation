<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KVKK (Faz 10d): when Ayarlar › KVKK sets a retention period, WhatsApp message texts older than it are blanked;
 * conversation, direction and time stay so the contact history remains. Off by default.
 *
 * The `body` column is encrypted (WhatsAppMessage cast). The redacted marker must therefore be written as ciphertext
 * (Crypt::encryptString, exactly what the cast reads back) — writing the plaintext constant would break decryption
 * on read. Already-redacted rows are found by `redacted_at`, never by comparing the encrypted body to a constant.
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
        $ids = DB::table('whatsapp_messages')->where('sent_at', '<', now()->subDays((int) $days))
            ->whereNull('redacted_at')->whereNotNull('body')->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        // The redacted text is a constant, so one ciphertext is reused for every row; reads decrypt it back to REDACTED.
        $cipher = Crypt::encryptString(self::REDACTED);
        $ids->chunk(500)->each(fn ($chunk) => DB::table('whatsapp_messages')->whereIn('id', $chunk->all())
            ->update(['body' => $cipher, 'redacted_at' => now(), 'updated_at' => now()]));

        return $ids->count();
    }
}
