<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apps that registered themselves to connect to the MoxDOP MCP server over OAuth (claude.ai connectors, Claude Code).
 * claude.ai custom connectors cannot send a fixed bearer key; they register here, an Admin approves them in the panel
 * and they get a key that lands in mcp_tokens like any other (yakup 2026-10-22: "bağlanamadı").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_oauth_clients')) {
            return;
        }
        Schema::create('mcp_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('name', 120);
            $table->json('redirect_uris');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
