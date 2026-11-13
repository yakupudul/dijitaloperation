<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LinkedIn şirket sayfasına otomatik paylaşım (yakup 2026-11-13: "linkedin şirket sayfamızda otomatik paylaşımı aktif
 * edemez miyiz?"): the LinkedIn developer app's Client ID / Secret, and on the LinkedIn account row the refresh token and
 * when the access token ends (LinkedIn tokens live 60 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('agency_settings', 'social_linkedin_client_id')) {
                $table->string('social_linkedin_client_id', 64)->nullable();
                $table->text('social_linkedin_client_secret')->nullable();
            }
        });
        Schema::table('social_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('social_accounts', 'refresh_token')) {
                $table->text('refresh_token')->nullable();
                $table->timestampTz('token_expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['social_linkedin_client_id', 'social_linkedin_client_secret']);
        });
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn(['refresh_token', 'token_expires_at']);
        });
    }
};
