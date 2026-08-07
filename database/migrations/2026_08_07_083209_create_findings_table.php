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
        if (Schema::hasTable('findings')) {
            return;
        }

        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->string('source_module');
            $table->string('fingerprint');
            $table->string('category');
            $table->string('severity');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->decimal('confidence', 8, 4);
            $table->string('status');
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            // Run model/table lands in a later task; store nullable FK-shaped id without constrained().
            $table->foreignId('last_run_id')->nullable();
            $table->timestamps();

            $table->unique(['digital_asset_id', 'fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
