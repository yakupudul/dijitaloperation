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
        if (Schema::hasTable('tasks')) {
            return;
        }

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('action');
            $table->text('rationale')->nullable();
            $table->string('priority');
            $table->json('snapshot_json')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status');
            $table->timestamps();

            $table->index('recommendation_id');
            $table->index('customer_id');
            $table->index('brand_id');
            $table->index('digital_asset_id');
            $table->index('assignee_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
