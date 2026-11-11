<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Satış Düzeni 3.2 (tek bağlantıyla başlangıç): what the customer filled, accepted and marked on the link; the agency's contract text and bank line. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('access_links') && ! Schema::hasColumn('access_links', 'start')) {
            Schema::table('access_links', fn (Blueprint $table) => $table->json('start')->nullable());
        }
        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'contract_text')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->text('contract_text')->nullable();
                $table->json('bank')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_links') && Schema::hasColumn('access_links', 'start')) {
            Schema::table('access_links', fn (Blueprint $table) => $table->dropColumn('start'));
        }
        if (Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'contract_text')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn(['contract_text', 'bank']));
        }
    }
};
