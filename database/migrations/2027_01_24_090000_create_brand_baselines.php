<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marka Düzeni 2.3 / 3.10: a brand's start numbers (30 full days), the base every later month is compared with. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_baselines')) {
            return;
        }
        Schema::create('brand_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained('brands')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->json('numbers');
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_baselines');
    }
};
