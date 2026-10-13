<?php

use App\Models\DigitalAsset;
use App\Services\Gbp\Desk\GbpDesk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * yakup, 2026-10-13: "Çiğli şubesine tanımladığımız tüm hizmet kategori ve hizmetleri sadece açıklamaları farklı olacak
 * şekilde diğer şubeler için de hazırla gönder onaylıyorum", and on the decision card "Deploy sonrası gönder". Once on
 * deploy: one queued copy per Avrupadent branch (moxdop:gbp:copy-profile --send, additions only, undoable), then a report
 * half an hour later kept as a Claude note on the brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('external_write_actions') || ! Schema::hasTable('claude_notes')) {
            return;
        }
        try {
            $locations = app(GbpDesk::class)->locations();
            $source = $locations->first(fn (DigitalAsset $l): bool => str_starts_with(mb_strtolower(GbpDesk::shortName((string) $l->name)), 'avrupadent çiğli'));
            if ($source === null) {
                return;
            }
            foreach ($locations->where('brand_id', $source->brand_id)->where('id', '!=', $source->id) as $target) {
                Artisan::queue('moxdop:gbp:copy-profile', ['source' => (string) $source->id, '--only' => [(string) $target->id], '--send' => true, '--wait' => 0]);
            }
            Artisan::queue('moxdop:gbp:copy-profile', ['source' => (string) $source->id, '--report' => true])->delay(now()->addMinutes(30));
        } catch (Throwable) {
            // A fresh database: no profiles.
        }
    }

    public function down(): void
    {
        // Sent additions are undone per profile on its "Kategori ve hizmetler" tab.
    }
};
