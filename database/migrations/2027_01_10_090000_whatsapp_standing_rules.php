<?php

use App\Services\Sales\StandingRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sabit kararlar (yakup 2026-11-08): his standing rules become the WhatsApp integration's business_context (WhatsApp ›
 * Beyin › Sabit kararlar). What was written there before is kept underneath; the starter placeholder is dropped.
 * Automatic reply drafts are switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = StandingRules::initial();
        if ($rules === '') {
            return;
        }
        foreach (DB::table('core_integrations')->where('provider', 'whatsapp')->get(['id', 'config']) as $row) {
            $config = json_decode((string) $row->config, true);
            $config = is_array($config) ? $config : [];
            // "Otomatik anında" (yakup 2026-11-08): a draft is written the moment a message arrives.
            $config['automatic_suggestions'] = true;
            $old = trim((string) ($config['business_context'] ?? ''));
            if (str_contains($old, 'SABİT KARARLAR')) {
                DB::table('core_integrations')->where('id', $row->id)->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

                continue;
            }
            $keep = $old !== '' && ! str_contains($old, '(buraya yazın)');
            $config['business_context'] = mb_substr($rules.($keep ? "\n\nÖnceki talimatlar\n".$old : ''), 0, 12000);
            DB::table('core_integrations')->where('id', $row->id)->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void {}
};
