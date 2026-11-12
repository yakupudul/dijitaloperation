<?php

use App\Services\Site\BrandCover;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Günlük blog (yakup 2026-11-12, "Onaylıyorum yap"): a brand with `auto_blog` publishes its own blog by itself, without
 * the operator's approval, at the set times each day (DailyBlog). Only moximu.com (the agency's own brand) has it; every
 * other brand keeps the weekly cycle and the one daily click.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'auto_blog')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->json('auto_blog')->nullable();
            });
        }
        DB::table('brands')->where('cover_style', BrandCover::MOXIMU_REHBER)->whereNull('auto_blog')
            ->update(['auto_blog' => json_encode(['per_day' => 2, 'times' => ['09:00', '15:00']])]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('brands', 'auto_blog')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->dropColumn('auto_blog');
            });
        }
    }
};
