<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_service_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_definition_id')->constrained('service_definitions')->restrictOnDelete();
            $table->string('status', 32);
            $table->string('brand_applicability_mode', 32);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cadence', 32)->nullable();
            $table->string('reporting_cadence', 32)->nullable();
            $table->date('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'service_definition_id']);
            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('customer_service_scope_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_service_scope_id')->constrained('customer_service_scopes')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['customer_service_scope_id', 'brand_id'], 'css_brand_unique');
            $table->index('brand_id');
        });

        Schema::create('customer_service_scope_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_service_scope_id')->constrained('customer_service_scopes')->cascadeOnDelete();
            $table->string('text', 500);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['customer_service_scope_id', 'sort_order'], 'css_inc_order');
        });

        Schema::create('customer_service_scope_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_service_scope_id')->constrained('customer_service_scopes')->cascadeOnDelete();
            $table->string('text', 500);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['customer_service_scope_id', 'sort_order'], 'css_exc_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service_scope_exclusions');
        Schema::dropIfExists('customer_service_scope_inclusions');
        Schema::dropIfExists('customer_service_scope_brands');
        Schema::dropIfExists('customer_service_scopes');
    }
};
