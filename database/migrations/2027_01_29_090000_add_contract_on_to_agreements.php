<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Satış Düzeni 3.1: the day the contract was signed, the second closing step after "evet". */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agreements') && ! Schema::hasColumn('agreements', 'contract_on')) {
            Schema::table('agreements', fn (Blueprint $table) => $table->date('contract_on')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agreements') && Schema::hasColumn('agreements', 'contract_on')) {
            Schema::table('agreements', fn (Blueprint $table) => $table->dropColumn('contract_on'));
        }
    }
};
