<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_conversion_business_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('conversion_action_id', 128);
            $table->string('business_stage', 64);
            $table->string('business_action_label', 160)->nullable();
            $table->decimal('nominal_value', 20, 6)->nullable();
            $table->string('currency', 8)->nullable();
            $table->boolean('is_quality_signal')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['digital_asset_id', 'conversion_action_id'], 'gads_conversion_business_mapping_unique');
            $table->index(['digital_asset_id', 'business_stage'], 'gads_conversion_business_mapping_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_conversion_business_mappings');
    }
};
