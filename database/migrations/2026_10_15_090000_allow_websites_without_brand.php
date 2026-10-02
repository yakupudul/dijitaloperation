<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A website can be added and connected (WordPress connector) from Integrations before it belongs to a brand;
 * the brand picks it later. Only websites are created without a brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite rebuilds digital_assets for ->change(). Inside the migration transaction it ignores
            // PRAGMA foreign_keys, so dropping the old table fails while rows reference it; defer the
            // foreign-key check to commit, when the rebuilt table holds every row again.
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::table('digital_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Brandless websites would violate NOT NULL; they stay nullable.
    }
};
