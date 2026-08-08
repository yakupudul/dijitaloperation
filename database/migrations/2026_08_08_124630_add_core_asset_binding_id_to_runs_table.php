<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->foreignId('core_asset_binding_id')
                ->nullable()
                ->after('core_connection_id')
                ->constrained('core_asset_bindings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('core_asset_binding_id');
        });
    }
};
