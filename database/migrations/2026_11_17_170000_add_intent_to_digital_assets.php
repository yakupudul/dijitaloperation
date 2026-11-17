<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arayüz kararı 2026-11-17 ("bildiğin şey durum olur"): what the operator knows about an asset is stored on it, so the
 * panel stops raising an alarm for it. `intent`: paused (stopped on purpose, e.g. ads paused by the operator) or
 * retired (an old account that is no longer used). The note is the operator's own sentence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('digital_assets') || Schema::hasColumn('digital_assets', 'intent')) {
            return;
        }
        Schema::table('digital_assets', function (Blueprint $table): void {
            $table->string('intent', 16)->nullable();
            $table->string('intent_note', 255)->nullable();
            $table->timestamp('intent_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('digital_assets') && Schema::hasColumn('digital_assets', 'intent')) {
            Schema::table('digital_assets', function (Blueprint $table): void {
                $table->dropColumn(['intent', 'intent_note', 'intent_at']);
            });
        }
    }
};
