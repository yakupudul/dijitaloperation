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
        if (Schema::hasTable('recommendations')) {
            return;
        }

        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_module');
            $table->string('title');
            $table->text('action')->nullable();
            $table->text('rationale')->nullable();
            $table->string('priority');
            $table->string('effort')->nullable();
            $table->string('status');
            $table->timestamps();

            $table->index('finding_id');
            $table->index('digital_asset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
