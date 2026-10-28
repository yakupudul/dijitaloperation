<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Every WhatsApp backup is a row now (yakup, 2026-10-28) and one row is marked "Görüşmelerde göster". The backup that
 * filled the inbox so far becomes the first row, marked shown, with its saved key, uploads and kept file: nothing is
 * lost and the inbox looks the same until another row is marked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_backup_lines', 'active')) {
            Schema::table('whatsapp_backup_lines', function (Blueprint $table): void {
                $table->boolean('active')->default(false);
            });
        }
        $integration = DB::table('core_integrations')->where('provider', 'whatsapp')->first();
        $config = $integration !== null ? (json_decode((string) $integration->config, true) ?: []) : [];
        $imports = DB::table('whatsapp_backup_imports')->whereNull('line_id')->where('mode', 'inbox');
        $chats = $integration !== null && DB::table('whatsapp_conversations')->where('integration_id', $integration->id)->where('phone_number_id', 'backup')->exists();
        if (DB::table('whatsapp_backup_lines')->where('active', true)->exists() || (! $chats && ! (clone $imports)->exists() && empty($config['backup_key']))) {
            return;
        }
        $name = 'Mevcut yedek';
        for ($n = 2; DB::table('whatsapp_backup_lines')->where('name', $name)->exists(); $n++) {
            $name = 'Mevcut yedek '.$n;
        }
        // The integration kept the key with Crypt::encryptString, the same form the row's encrypted cast reads.
        $id = DB::table('whatsapp_backup_lines')->insertGetId([
            'name' => $name, 'backup_key' => $config['backup_key'] ?? null, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (clone $imports)->update(['line_id' => $id]);
        $kept = storage_path('app/private/whatsapp-backups/kept/');
        if (is_file($kept.'inbox.crypt15')) {
            File::move($kept.'inbox.crypt15', $kept.'line-'.$id.'.crypt15');
        }
        if ($integration !== null && array_key_exists('backup_key', $config)) {
            unset($config['backup_key']);
            DB::table('core_integrations')->where('id', $integration->id)->update(['config' => json_encode($config)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('whatsapp_backup_lines', 'active')) {
            Schema::table('whatsapp_backup_lines', function (Blueprint $table): void {
                $table->dropColumn('active');
            });
        }
    }
};
