<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('scope_type'); // personal|agency|customer|brand|digital_asset|task
            $table->string('scope_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('brand_id')->nullable();
            $table->string('digital_asset_id')->nullable();
            $table->string('task_id')->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'scope_type']);
            $table->index('scope_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_files');
    }
};
