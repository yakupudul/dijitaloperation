<?php

use App\Services\Site\BrandCover;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A brand's own article cover (yakup 2026-11-09): with `cover_style` set, new articles get a cover drawn in the brand's
 * approved style instead of a picture from the site's media library. Moximu uses its "Rehber" card.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'cover_style')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->string('cover_style', 32)->nullable();
            });
        }
        DB::table('brands')->where('name', 'Moximu')->whereNull('cover_style')->update(['cover_style' => BrandCover::MOXIMU_REHBER]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('brands', 'cover_style')) {
            Schema::table('brands', function (Blueprint $table): void {
                $table->dropColumn('cover_style');
            });
        }
    }
};
