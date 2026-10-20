<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suggestion reason was a 240-character column while cluster overlap, content and repair checks write up to 600
 * characters: those rows failed to save (pool #49). varchar to text needs no table rewrite on PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('suggestions', 'reason')) {
            Schema::table('suggestions', fn (Blueprint $table) => $table->text('reason')->change());
        }
    }

    public function down(): void
    {
        // Kept wide: narrowing would cut stored reasons.
    }
};
