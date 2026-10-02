<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('label', 255);
            $table->string('normalized_key', 255);
            $table->string('note', 255)->nullable();
            $table->string('conversion_type', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('applicability_mode', 32)->default('brand_wide');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['brand_id', 'kind', 'normalized_key'], 'brand_goals_brand_kind_norm_unique');
            $table->index(['brand_id', 'kind', 'status']);
            $table->index(['brand_id', 'status', 'sort_order']);
        });

        Schema::create('brand_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('priority_rank')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index(['brand_id', 'priority_rank']);
        });

        Schema::create('brand_offering_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_offering_id')->constrained('brand_offerings')->restrictOnDelete();
            $table->string('raw_label', 255);
            $table->string('normalized_key', 255);
            $table->string('locale', 16)->nullable();
            $table->string('name_kind', 32);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('provenance', 32);
            $table->string('normalization_version', 16);
            $table->timestamps();

            $table->unique(['brand_id', 'normalized_key'], 'brand_offering_names_brand_norm_unique');
            $table->index(['brand_offering_id', 'is_primary']);
            $table->index(['brand_id', 'is_active']);
        });

        Schema::create('brand_goal_offering', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_goal_id')->constrained('brand_goals')->cascadeOnDelete();
            $table->foreignId('brand_offering_id')->constrained('brand_offerings')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['brand_goal_id', 'brand_offering_id'], 'brand_goal_offering_unique');
            $table->index('brand_offering_id');
        });

        Schema::create('brand_context_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['brand_id', 'event']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_context_activities');
        Schema::dropIfExists('brand_goal_offering');
        Schema::dropIfExists('brand_offering_names');
        Schema::dropIfExists('brand_offerings');
        Schema::dropIfExists('brand_goals');
    }
};
