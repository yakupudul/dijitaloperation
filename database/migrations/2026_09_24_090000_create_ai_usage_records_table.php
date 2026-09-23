<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->string('route_key', 120)->nullable();
            $table->string('agent', 190);
            $table->string('provider', 48);
            $table->string('model', 190);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable(); // null = price unknown for this model
            $table->string('invocation_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['created_at'], 'ai_usage_created_idx');
            $table->index(['route_key', 'created_at'], 'ai_usage_route_created_idx');
        });

        if (! Schema::hasColumn('agency_settings', 'ai_monthly_budget_usd')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->decimal('ai_monthly_budget_usd', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
        if (Schema::hasColumn('agency_settings', 'ai_monthly_budget_usd')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->dropColumn('ai_monthly_budget_usd');
            });
        }
    }
};
