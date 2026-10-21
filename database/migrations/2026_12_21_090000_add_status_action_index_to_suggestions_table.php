<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The desk (Onarım masası, menu count, Bugün) reads open suggestions of every brand by status and action type; the
 * existing indexes start with brand_id, so the all-brands read walked the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suggestions') || Schema::hasIndex('suggestions', 'suggestions_status_action_idx')) {
            return;
        }

        Schema::table('suggestions', function (Blueprint $table): void {
            $table->index(['status', 'action_type'], 'suggestions_status_action_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suggestions') || ! Schema::hasIndex('suggestions', 'suggestions_status_action_idx')) {
            return;
        }

        Schema::table('suggestions', function (Blueprint $table): void {
            $table->dropIndex('suggestions_status_action_idx');
        });
    }
};
