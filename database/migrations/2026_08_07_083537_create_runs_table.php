<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('runs')) {
            return;
        }

        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('core_connection_id')->nullable()->constrained('core_connections')->nullOnDelete();
            $table->string('module_id');
            $table->string('status');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['digital_asset_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
