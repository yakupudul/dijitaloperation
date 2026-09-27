<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Value loop: an advisor item that went out in a Google Ads Editor file carries when and by whom, so the advisor
 * and the command center can show "dışa aktarıldı, Editor'da yüklenmeyi bekliyor" until the operator marks it done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisor_items', function (Blueprint $table): void {
            $table->timestampTz('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('advisor_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('exported_by');
            $table->dropColumn('exported_at');
        });
    }
};
