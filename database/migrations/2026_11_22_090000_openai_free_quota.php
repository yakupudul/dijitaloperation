<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpenAI ücretsiz paylaşım kotası: the agency's switch, the admin key the audit reads OpenAI's real costs with, the last
 * audit; per call the tokens the quota covered and the list price (the billed estimate stays in cost_usd).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('agency_settings', 'ai_openai_free_quota')) {
                $table->boolean('ai_openai_free_quota')->default(false);
            }
            if (! Schema::hasColumn('agency_settings', 'ai_openai_admin_key')) {
                $table->text('ai_openai_admin_key')->nullable();
            }
            if (! Schema::hasColumn('agency_settings', 'ai_openai_audit')) {
                $table->json('ai_openai_audit')->nullable();
            }
        });
        Schema::table('ai_live_operations', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_live_operations', 'free_tokens')) {
                $table->unsignedInteger('free_tokens')->nullable();
            }
            if (! Schema::hasColumn('ai_live_operations', 'list_cost_usd')) {
                $table->decimal('list_cost_usd', 12, 6)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['ai_openai_free_quota', 'ai_openai_admin_key', 'ai_openai_audit']);
        });
        Schema::table('ai_live_operations', function (Blueprint $table): void {
            $table->dropColumn(['free_tokens', 'list_cost_usd']);
        });
    }
};
