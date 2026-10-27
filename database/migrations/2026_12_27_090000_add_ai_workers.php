<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI sağlayıcıları (yakup, 2026-10-27): Claude MCP, ChatGPT MCP, Claude API and ChatGPT API drain the AI queue in the
 * order set on Ayarlar › AI. `mcp_tokens.provider` says which assistant a key belongs to; `ai_tasks.claimed_by` keeps a
 * taken task for that worker for two hours; `done_by` / `done_by_token_id` record who answered it and `cost_usd` what
 * an API answer cost; `agency_settings.ai_workers` holds the order, pauses and the API rows' model and TL limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_tokens') && ! Schema::hasColumn('mcp_tokens', 'provider')) {
            Schema::table('mcp_tokens', function (Blueprint $table): void {
                $table->string('provider', 30)->default('claude_mcp');
            });
        }
        if (Schema::hasTable('ai_tasks')) {
            Schema::table('ai_tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('ai_tasks', 'claimed_by')) {
                    $table->string('claimed_by', 30)->nullable();
                }
                if (! Schema::hasColumn('ai_tasks', 'done_by')) {
                    $table->string('done_by', 80)->nullable()->index();
                }
                if (! Schema::hasColumn('ai_tasks', 'done_by_token_id')) {
                    $table->unsignedBigInteger('done_by_token_id')->nullable();
                }
                if (! Schema::hasColumn('ai_tasks', 'cost_usd')) {
                    $table->decimal('cost_usd', 12, 6)->nullable();
                }
            });
        }
        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'ai_workers')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->json('ai_workers')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_tokens', 'provider')) {
            Schema::table('mcp_tokens', fn (Blueprint $table) => $table->dropColumn('provider'));
        }
        Schema::table('ai_tasks', function (Blueprint $table): void {
            $table->dropIndex(['done_by']);
            $table->dropColumn(['claimed_by', 'done_by', 'done_by_token_id', 'cost_usd']);
        });
        if (Schema::hasColumn('agency_settings', 'ai_workers')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn('ai_workers'));
        }
    }
};
