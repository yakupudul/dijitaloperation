<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8d: Google review intelligence for the brand and nearby competitors (DataForSEO reviews, standard queue).
 * Reviewer names are not stored; only rating, date, text and whether the owner answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->boolean('is_own')->default(false);
            $table->string('title', 200);
            $table->string('cid', 40)->nullable();
            $table->string('place_id', 120)->nullable();
            $table->string('source', 16)->default('grid');
            $table->boolean('active')->default(true);
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'active']);
        });

        Schema::create('review_profile_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_profile_id')->constrained('review_profiles')->cascadeOnDelete();
            $table->date('observed_on');
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->timestamps();

            $table->unique(['review_profile_id', 'observed_on']);
        });

        Schema::create('review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_profile_id')->constrained('review_profiles')->cascadeOnDelete();
            $table->string('review_id', 255);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('text')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->boolean('owner_answered')->default(false);
            $table->timestamps();

            $table->unique(['review_profile_id', 'review_id']);
            $table->index(['review_profile_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');
        Schema::dropIfExists('review_profile_snapshots');
        Schema::dropIfExists('review_profiles');
    }
};
