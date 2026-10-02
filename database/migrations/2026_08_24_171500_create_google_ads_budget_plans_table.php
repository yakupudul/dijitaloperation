<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_budget_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 8)->nullable();
            $table->decimal('planned_budget', 16, 2)->nullable();
            $table->decimal('target_cpa', 16, 2)->nullable();
            $table->decimal('target_roas', 10, 4)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['digital_asset_id', 'period_start', 'period_end'], 'gads_budget_plan_asset_period_unique');
            $table->index(['digital_asset_id', 'period_start', 'period_end'], 'gads_budget_plan_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_budget_plans');
    }
};
