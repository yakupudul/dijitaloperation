<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10b: customer health score (0–100) with the reasons behind it, recomputed daily for active customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_health', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('band', 12);
            $table->json('reasons');
            $table->timestampTz('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_health');
    }
};
