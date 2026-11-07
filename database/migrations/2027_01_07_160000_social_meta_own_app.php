<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sosyal medya can log in through its own Meta app (the reviewed, live one) instead of the Meta Ads read app.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'social_meta_app_id')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->string('social_meta_app_id', 64)->nullable();
                $table->text('social_meta_app_secret')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'social_meta_app_id')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->dropColumn(['social_meta_app_id', 'social_meta_app_secret']);
            });
        }
    }
};
