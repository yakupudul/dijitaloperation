<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kişisel LinkedIn (yakup 2026-11-17): the owner's own profile gets its posts through a second LinkedIn app
 * ("Share on LinkedIn" + OpenID) with its own client, and a post carries a separate text for that profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('agency_settings', 'social_linkedin_personal_client_id')) {
                $table->string('social_linkedin_personal_client_id', 64)->nullable();
                $table->text('social_linkedin_personal_client_secret')->nullable();
            }
        });
        Schema::table('social_posts', function (Blueprint $table): void {
            if (! Schema::hasColumn('social_posts', 'personal_text')) {
                $table->text('personal_text')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['social_linkedin_personal_client_id', 'social_linkedin_personal_client_secret']);
        });
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropColumn('personal_text');
        });
    }
};
