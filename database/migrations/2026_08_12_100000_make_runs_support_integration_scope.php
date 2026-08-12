<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-binding, Integration-scoped operations (e.g. Meta history import) need a Run
 * that is not yet tied to a Digital Asset. `digital_asset_id` becomes nullable and a
 * nullable `core_integration_id` is added so a Run can be scoped to either one.
 *
 * Invariant (enforced in the application layer, not a DB constraint — see
 * App\Models\Run): every Run must have `digital_asset_id` OR `core_integration_id`.
 *
 * Laravel 11+ natively rebuilds SQLite tables for column `change()`s (no doctrine/dbal
 * needed); MySQL 8 uses a native `MODIFY COLUMN`. Both paths preserve existing data and
 * foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->foreignId('digital_asset_id')->nullable()->change();
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->foreignId('core_integration_id')
                ->nullable()
                ->after('core_asset_binding_id')
                ->constrained('core_integrations')
                ->nullOnDelete();

            $table->index(['core_integration_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropIndex(['core_integration_id', 'started_at']);
            $table->dropConstrainedForeignId('core_integration_id');
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->foreignId('digital_asset_id')->nullable(false)->change();
        });
    }
};
