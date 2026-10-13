<?php

use App\Models\DigitalAsset;
use App\Services\Gbp\Desk\GbpDesk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * The 2026_12_13_210000 retry ran at 00:20 Istanbul but the copy command had no user, so the AI budget treated it as
 * automatic work and refused every branch ("Uygun AI sağlayıcısı yok"). The command now acts as the Admin the send is
 * made for; the same approved send runs once more right after this deploy, and the report follows 45 minutes later.
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
            $at = now()->addMinutes(2);
            foreach ($locations->where('brand_id', $source->brand_id)->where('id', '!=', $source->id) as $target) {
                Artisan::queue('moxdop:gbp:copy-profile', ['source' => (string) $source->id, '--only' => [(string) $target->id], '--send' => true, '--wait' => 0])->delay($at);
            }
            Artisan::queue('moxdop:gbp:copy-profile', ['source' => (string) $source->id, '--report' => true])->delay($at->copy()->addMinutes(45));
        } catch (Throwable) {
            // A fresh database: no profiles.
        }
    }

    public function down(): void
    {
        // Sent additions are undone per profile on its "Kategori ve hizmetler" tab.
    }
};
