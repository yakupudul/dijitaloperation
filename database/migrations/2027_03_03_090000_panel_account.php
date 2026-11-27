<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri paneli › Hesap (2026-11-28): the brand's manager sees its services, invoices, payments and yearly renewals.
 * An invoice is shown on the panel only after the operator says so (a wrongly matched paper never reaches a client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_documents', function (Blueprint $table): void {
            $table->boolean('panel_visible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('finance_documents', function (Blueprint $table): void {
            $table->dropColumn('panel_visible');
        });
    }
};
