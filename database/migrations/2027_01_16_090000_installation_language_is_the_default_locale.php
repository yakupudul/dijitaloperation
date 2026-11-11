<?php

use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.locale and agency_settings.locale were created with a blind 'en' default, so every operator who never chose a
 * language got English menus on a Turkish installation (Satış Düzeni 1.10, Günlük Düzen 1.5, Bağlantı Düzeni 1.10).
 * New rows now take the installation language (model defaults); rows still carrying the old blind default move to it.
 * English stays selectable in Profil.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = AgencySettingCatalog::appDefaultLocale();
        if ($default === AgencySettingCatalog::LOCALE_EN) {
            return;
        }
        foreach (['users', 'agency_settings'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'locale')) {
                DB::table($table)->where('locale', AgencySettingCatalog::LOCALE_EN)->update(['locale' => $default]);
            }
        }
    }

    public function down(): void {}
};
