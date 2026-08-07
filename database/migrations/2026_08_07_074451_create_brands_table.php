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
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('sector')->nullable();
                $table->string('primary_country')->nullable();
                $table->json('target_markets')->nullable();
                $table->json('languages')->nullable();
                $table->text('description')->nullable();
                $table->text('audience')->nullable();
                $table->text('offerings')->nullable();
                $table->text('competitors')->nullable();
                $table->string('logo_url')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('brand_user')) {
            Schema::create('brand_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['brand_id', 'user_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_user');
        Schema::dropIfExists('brands');
    }
};
