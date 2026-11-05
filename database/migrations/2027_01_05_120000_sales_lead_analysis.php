<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Satış 3. hafta (2026-11-05): the full written analysis of a prospect, kept on the card. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->longText('analysis')->nullable();
            $table->text('analysis_message')->nullable();
            $table->string('analysis_headline', 500)->nullable();
            $table->string('analysis_status', 12)->nullable();
            $table->string('analysis_note', 500)->nullable();
            $table->timestamp('analysis_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['analysis', 'analysis_message', 'analysis_headline', 'analysis_status', 'analysis_note', 'analysis_at']);
        });
    }
};
