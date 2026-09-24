<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8a: per-brand intelligence settings (opt-in, monthly USD cap), queued DataForSEO tasks and the
 * Google Maps grid rank tracker (runs + one row per grid point with the top 20 results).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_intel_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained('brands')->cascadeOnDelete();
            $table->decimal('monthly_usd', 8, 2)->default(5);
            $table->boolean('grid_enabled')->default(false);
            $table->json('grid_keywords')->nullable();
            $table->decimal('grid_center_lat', 10, 7)->nullable();
            $table->decimal('grid_center_lng', 10, 7)->nullable();
            $table->unsignedTinyInteger('grid_size')->default(7);
            $table->decimal('grid_spacing_km', 5, 2)->default(1);
            $table->unsignedSmallInteger('grid_every_days')->default(7);
            $table->string('gbp_place_id', 120)->nullable();
            $table->string('gbp_cid', 40)->nullable();
            $table->boolean('reviews_enabled')->default(false);
            $table->boolean('backlinks_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dataforseo_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('purpose', 32);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('get_endpoint', 120);
            $table->string('task_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('posted');
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('polls')->default(0);
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'posted_at']);
            $table->index(['purpose', 'subject_type', 'subject_id']);
            $table->index(['brand_id', 'posted_at']);
        });

        Schema::create('map_grid_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('keyword', 200);
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedTinyInteger('grid_size');
            $table->decimal('spacing_km', 5, 2);
            $table->string('status', 16)->default('running');
            $table->string('trigger', 16)->default('manual');
            $table->unsignedSmallInteger('points_total')->default(0);
            $table->unsignedSmallInteger('points_done')->default(0);
            $table->decimal('arp', 5, 2)->nullable();
            $table->decimal('atrp', 5, 2)->nullable();
            $table->decimal('solv', 5, 2)->nullable();
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'keyword', 'started_at']);
        });

        Schema::create('map_grid_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_grid_run_id')->constrained('map_grid_runs')->cascadeOnDelete();
            $table->unsignedTinyInteger('row');
            $table->unsignedTinyInteger('col');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('our_rank')->nullable();
            $table->json('results')->nullable();
            $table->timestamps();

            $table->unique(['map_grid_run_id', 'row', 'col']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_grid_points');
        Schema::dropIfExists('map_grid_runs');
        Schema::dropIfExists('dataforseo_tasks');
        Schema::dropIfExists('brand_intel_settings');
    }
};
