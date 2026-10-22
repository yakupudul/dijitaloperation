<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP keys made in the panel (Ayarlar › MCP anahtarları): shown once, stored only as a sha256 hash, each one read or
 * write, revocable one by one. agency_settings.mcp_env_token_off turns the old MOXDOP_MCP_TOKEN key off without a
 * server change (yakup 2026-10-21, panel audit T1: the old key had leaked into a chat).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mcp_tokens')) {
            Schema::create('mcp_tokens', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 80);
                $table->string('scope', 10)->default('read');
                $table->string('token_hash', 64)->unique();
                $table->string('last_four', 4);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'mcp_env_token_off')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->boolean('mcp_env_token_off')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'mcp_env_token_off')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->dropColumn('mcp_env_token_off');
            });
        }
        Schema::dropIfExists('mcp_tokens');
    }
};
