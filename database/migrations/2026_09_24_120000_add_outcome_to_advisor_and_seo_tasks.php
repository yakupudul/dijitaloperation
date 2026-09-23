<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 6: "Yapıldı" work is measured once, 28 days later, against the same metric it was produced from.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['advisor_items', 'seo_tasks'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('outcome')->nullable();
                $blueprint->timestampTz('measured_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['advisor_items', 'seo_tasks'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['outcome', 'measured_at']);
            });
        }
    }
};
